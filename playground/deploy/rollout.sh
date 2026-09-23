#!/usr/bin/env bash
# Rollt den gebauten Stand auf die Demo aus — alle Schritte, oder keinen.
#
# Warum es dieses Skript gibt: die vier Schritte standen als Prosa im README,
# und zweimal hat jemand nach dem rsync aufgehoert. Am 05.09.2026 fehlten chown
# und pristine (Demo auf 500, Stand um 03:17 wieder weg). Am 03.09.2026 fehlte
# `migrate --force`: statamic-assessments und statamic-clientrooms waren
# ausgeliefert, ihre Tabellen nie angelegt, und /cp/assessments wie
# /cp/client-rooms antworteten mit HTTP 500.
#
# Ein Schritt, den man ueberlesen kann, ist kein Schritt. Deshalb hier als
# Ablauf mit `set -e`: wer abbricht, bricht sichtbar ab.
set -euo pipefail

: "${HOST:?HOST setzen (Zielserver, echter Wert in GoldnerOS/memory/reference-statamic-demo-deploy.md)}"
: "${APPDIR:?APPDIR setzen (Zielverzeichnis auf dem Server)}"
CONTAINER=${CONTAINER:-statamic-demo}
QUELLE=${1:-/tmp/statamic-demo-build}
SEED=${SEED:-1}

[ -d "$QUELLE" ] || { echo "FEHLER: $QUELLE gibt es nicht. Erst ./deploy/build.sh laufen lassen." >&2; exit 1; }

im_container() {
    ssh "root@$HOST" "docker exec -u www-data -w /var/www/html $CONTAINER $*"
}

echo "-> 1/5 uebertragen"
rsync -a "$QUELLE/" "root@$HOST:$APPDIR/app/"
rsync -a --delete "$QUELLE/vendor/goldnead/" "root@$HOST:$APPDIR/app/vendor/goldnead/"

# Vor package:discover, sonst schreibt Laravel seinen Zwischenspeicher als root
# und jede Seite antwortet mit 500 (tempnam() in AliasLoader.php:111).
#
# `resources` gehoert dazu, auch wenn es aus dem Build kommt: Statamic schreibt
# Blueprints dorthin, und der Demo-Seeder tut es auch. Ohne den Eintrag stirbt
# `demo:seed --fresh` mitten im Lauf mit „file_put_contents(
# resources/blueprints/collections/et_templates/email_template.yaml): Permission
# denied" — was wie ein Seeder-Fehler aussieht und keiner ist (08.09.2026).
echo "-> 2/5 Besitzrechte (uid 33 = www-data im Container)"
#
# `public/assets` seit 23.09.2026: Statamic legt zu jeder Datei im Container
# `assets` beim ersten Lesen eine `.meta`-Datei an. Gehoert der Ordner root,
# scheitert das mit „Unable to create a directory …/.meta", und jede Seite,
# die die Datei zeigt, antwortet mit 500 (so die Kurs-Lektion mit dem neuen
# PDF-Download). Die `.meta` liegt inzwischen im Repo; der chown haelt auch
# den naechsten neuen Anhang und Uploads im CP offen.
ssh "root@$HOST" "cd $APPDIR/app && chown -R 33:33 content users database config storage resources bootstrap/cache public/assets"

echo "-> 3/5 package:discover"
# Kein `composer dump-autoload`: im Container gibt es kein composer, nur php
# (03.09.2026 geprueft). Die Autoload-Dateien kommen fertig aus dem Build.
im_container php artisan package:discover

# Der Stache indiziert content/ und liegt in storage/, das der rsync mit
# ueberschreibt — aber nicht zwingend so, dass Statamic die Aenderung bemerkt.
# Am 19.09.2026 lag eine neu ausgerollte Seite als Datei auf dem Server und
# antwortete trotzdem mit 404, weil der Index sie nicht kannte. Das trifft
# jede neue Seite und jede geaenderte Collection, und es sieht aus wie ein
# vergessener rsync, obwohl die Datei da ist.
#
# Danach `cache:clear`, weil ein alter Eintrag im Application-Cache denselben
# Effekt hat und beides zusammen eine Sekunde kostet.
echo "-> 3b/5 Stache und Cache leeren"
im_container php please stache:clear
im_container php artisan cache:clear

# Veroeffentlichte Vorlagen aus den Tags neu schreiben. `resources/views/vendor`
# ist im Repo ignoriert und kommt nicht mit dem Build; auf dem Server liegt,
# was irgendwann einmal veroeffentlicht wurde. Eine alte Kopie verdeckt jede
# neue Ansicht des Addons still: am 23.09.2026 die Portal-Seiten aus
# statamic-payments 1.25 (Pausieren, Wechseln, Karte) und die Kasse aus
# statamic-funnels 1.17 (Betragsfeld, Land, Coupon, Bump-Regeln, In-App-
# Hinweis). Beide Pakete haben keine Anpassung im Demo, `--force` ist richtig.
echo "-> 3c/5 Vorlagen neu veroeffentlichen"
im_container php artisan vendor:publish --tag=statamic-payments-views --force
im_container php artisan vendor:publish --tag=statamic-funnels-views --force

echo "-> 4/5 migrate --force"
# Der Schritt, dessen Fehlen am 03.09.2026 zwei CP-Seiten auf 500 gesetzt hat.
# Neue Addons bringen neue Migrationen mit, und niemand sieht ihnen das an.
im_container php artisan migrate --force

if [ "$SEED" = "1" ]; then
    echo "-> 4b/5 demo:seed --fresh"
    im_container php artisan demo:seed --fresh
fi

# Ohne das holt der naechtliche Reset um 03:17 UTC den Stand von vorher zurueck,
# Migrationen eingeschlossen. Bei gestopptem Container, sonst greift der Tar
# mitten in einen SQLite-Schreibvorgang.
#
# Der Start steht bewusst NICHT in derselben `&&`-Kette wie der Tar. Scheitert
# `tar` (volle Platte, Rechteproblem, abgerissene Verbindung), bricht die Kette
# davor ab, der Container bleibt gestoppt, und `set -e` beendet dieses Skript
# mit dem rohen tar-Fehler — die Nachprobe unten liefe nie, und die Demo waere
# aus, ohne dass eine Zeile das sagt.
echo "-> 5/5 pristine.tar.gz neu ziehen"
if ! ssh "root@$HOST" "cd $APPDIR && docker compose stop app && tar czf pristine.tar.gz -C app content users database config storage"; then
    echo "" >&2
    echo "ABBRUCH: pristine.tar.gz konnte nicht gezogen werden." >&2
    echo "         Der Container wird jetzt wieder gestartet, aber der naechtliche" >&2
    echo "         Reset um 03:17 UTC holt den ALTEN Stand zurueck. Schritt 5 von" >&2
    echo "         Hand nachholen, sonst ist dieses Deploy morgen frueh weg." >&2
    ssh "root@$HOST" "cd $APPDIR && docker compose up -d app" || true
    exit 1
fi
ssh "root@$HOST" "cd $APPDIR && docker compose up -d app"

echo "-> Nachprobe"
ssh "root@$HOST" "curl -fsS -o /dev/null -w '/up %{http_code}\n' http://127.0.0.1:8099/up"

echo "-> Fertig. CP-Seiten mit einer Sitzung gegenpruefen, von aussen kommt nur 302."
