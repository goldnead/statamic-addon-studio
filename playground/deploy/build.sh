#!/usr/bin/env bash
# Baut den deploybaren Stand des Playgrounds (Standard: /tmp/statamic-demo-build).
#
# Was hier landet, ist der committete Stand plus Release-Tags der Addons —
# niemals der Arbeitsstand. Eine parallel laufende Session, die gerade in
# einem Addon-Repo arbeitet, darf nicht mit auf den Server raus.
#
# Was bewusst NICHT hier landet: die .env (mit APP_KEY) und alles, was auf
# dem Server selbst gehört — content/, users/, database/, storage/ werden
# dort gezaehnt und beim naechtlichen Reset aus pristine.tar.gz gestellt.
set -euo pipefail

STUDIO=$(cd "$(dirname "$0")/../.." && pwd)
PLAYGROUND="$STUDIO/playground"
ZIEL=${1:-/tmp/statamic-demo-build}

echo "-> Ziel: $ZIEL"
rm -rf "$ZIEL"
mkdir -p "$ZIEL"

# 1) Committeter Stand des Playgrounds.
git -C "$STUDIO" archive HEAD:playground | tar -x -C "$ZIEL"

# 2) Overlay aus dem Arbeitsbaum: was nicht im Repo liegt, aber gebraucht wird.
#    - public/vendor: die mitgelieferten CP-Assets der Addons (teilweise ignoriert)
#    - public/build: der eigene Vite-Build
#    - composer.lock: im Repo nicht versioniert, Statamic braucht ihn (Version.php)
#    - vendor: Grundbestand; Symlinks der Pfad-Repos werden aufgeloest (-L).
#      Die verschachtelten Dev-Vendors der Addon-Repos bleiben draussen: sie
#      gehoeren niemandem im Betrieb, und mindestens eines davon (testbench
#      unter entitlements) haelt eine Symlink-Schleife, die rsync nicht
#      ueberlebt.
rsync -a "$PLAYGROUND/public/vendor/" "$ZIEL/public/vendor/"
rsync -a "$PLAYGROUND/public/build/" "$ZIEL/public/build/"
cp "$PLAYGROUND/composer.lock" "$ZIEL/composer.lock"
rsync -aL --exclude='/goldnead/*/vendor' "$PLAYGROUND/vendor/" "$ZIEL/vendor/"

# 3) Addons als Release-Tags ueberschreiben. Siehe tags.conf: ein "repo tag" je Zeile.
#
#    Dabei wandert AUCH das gebaute CP-Asset des Tags nach public/vendor und
#    ersetzt, was Schritt 2 aus dem Arbeitsbaum dorthin gelegt hat.
#
#    Warum das sein muss: `public/vendor/<addon>` traegt das JavaScript, das der
#    Browser laedt, und es kam bis 03.09.2026 aus dem Arbeitsbaum des
#    Playgrounds — also vom HEAD der Addons —, waehrend das PHP daneben aus dem
#    Release-Tag stammte. Damit lief die Demo per Konstruktion mit neuem
#    JavaScript auf altem PHP. Genau das war der Blocker vom 03.09.: das JS von
#    statamic-offers 1.6.0 las `t.timezone_note`, der Controller aus 1.5.0
#    schickte den Schluessel nicht, und `undefined.replace(...)` liess beim
#    Bearbeiten eines Angebots und beim Oeffnen eines Gutscheins nur ein leeres
#    Overlay stehen (TASKS/statamic-addons-feedback-2026-09-03/feedback.md,
#    F23/F28). Die gebauten Dateien liegen im Tag (`dist/build/`), es gibt also
#    nichts zu bauen — nur zu nehmen, was zum PHP gehoert.
while read -r repo tag; do
    case $repo in ''|'#'*) continue ;; esac
    ziel="$ZIEL/vendor/goldnead/$repo"
    rm -rf "$ziel"
    mkdir -p "$ziel"
    git -C "$HOME/projects/$repo" archive "$tag" | tar -x -C "$ziel"

    # Das CP-Asset desselben Tags. `--delete`, damit keine Datei aus einer
    # anderen Fassung liegenbleibt: die Bundle-Namen tragen einen Hash, alte
    # blieben sonst neben den neuen stehen.
    #
    # Zwei Konventionen leben in der Familie nebeneinander: die aelteren Addons
    # bauen nach `resources/dist/`, die neueren nach `dist/`. Wer nur eine davon
    # abfragt, laesst die andere Haelfte der Suite auf dem alten Weg.
    #
    # Kopiert wird der ganze dist-Ordner, nicht nur `build/`: das ist genau das,
    # was `vendor:publish` tut (dist-Wurzel -> public/vendor/<addon>/), und es
    # faengt die Addons mit, die kein Vite-Bundle haben, sondern eine einzelne
    # Datei ausliefern (statamic-consent: `consent.js`, `consent.css`).
    quelle=""
    for kandidat in "$ziel/dist" "$ziel/resources/dist"; do
        [ -d "$kandidat" ] && quelle="$kandidat" && break
    done

    if [ -n "$quelle" ]; then
        if [ ! -d "$ZIEL/public/vendor/$repo" ]; then
            echo "   ! $repo: public/vendor/$repo gab es vorher nicht — Publish-Name pruefen" >&2
        fi
        mkdir -p "$ZIEL/public/vendor/$repo"
        rsync -a --delete --exclude=hot "$quelle/" "$ZIEL/public/vendor/$repo/"
        echo "   $repo @ $tag (PHP + CP-Asset)"
    else
        # Kein gebautes Asset im Tag heisst: dieses Addon bringt keine
        # CP-Oberflaeche mit, ODER es hat sein dist nicht committet. Der zweite
        # Fall ist der gefaehrliche — dann laedt der Browser weiter das JS aus
        # dem Arbeitsbaum. Deshalb laut, nicht still.
        if [ -d "$ZIEL/public/vendor/$repo" ]; then
            echo "   ! $repo @ $tag: kein gebautes Asset im Tag, aber public/vendor/$repo existiert —" >&2
            echo "     die Demo laeuft dort mit JS aus dem Arbeitsbaum. dist committen." >&2
        else
            echo "   $repo @ $tag (nur PHP, keine CP-Oberflaeche)"
        fi
    fi
done < "$(dirname "$0")/tags.conf"

# 3a) Waechter: ruft ein gepinntes Addon etwas, das die gepinnte Fassung von
#     brand-context nicht hat?
#
#     Am 06.09.2026 stand tags.conf 13 Eintraege hinter den echten Tags, darunter
#     brand-context v1.11.1 — ohne `SettingsRegistry`. Das war an dem Tag noch
#     harmlos, weil keine gepinnte Fassung die Klasse rief. Der HEAD von
#     automations, leadhub und webhook-manager ruft sie sehr wohl. Die Falle
#     schnappt also in dem Moment zu, in dem jemand eines davon hier anhebt und
#     brand-context vergisst: die Demo bootet dann nicht mehr, alle Seiten 500.
#
#     Deshalb hier eine Zeile Pruefung statt einer Zeile Erinnerung. Sie kostet
#     nichts und faengt genau den Fall, der sonst erst auf dem Server auffaellt.
#
#     Bewusst nur diese eine Klasse: sie ist die einzige, bei der ein Addon
#     ueber die Paketgrenze in ein anderes greift und deren Fehlen den Boot
#     abbricht. Eine allgemeine Abhaengigkeitspruefung waere composer, und
#     composer laeuft hier nicht.
fehlt=""
for anrufer in "$ZIEL"/vendor/goldnead/*; do
    [ -d "$anrufer/src" ] || continue
    if grep -rqs "SettingsRegistry" "$anrufer/src"; then
        if ! [ -f "$ZIEL/vendor/goldnead/statamic-brand-context/src/Settings/SettingsRegistry.php" ]; then
            fehlt="$fehlt $(basename "$anrufer")"
        fi
    fi
done

if [ -n "$fehlt" ]; then
    echo "" >&2
    echo "ABBRUCH: diese Addons rufen SettingsRegistry, aber die gepinnte" >&2
    echo "         brand-context-Fassung bringt sie nicht mit:$fehlt" >&2
    echo "         Die Demo wuerde beim Booten fatalen und alle Seiten mit 500" >&2
    echo "         beantworten. brand-context in tags.conf anheben." >&2
    exit 1
fi

# 3b) Ein `hot`-File zeigt auf einen laufenden Vite-Dev-Server. Landet es auf
#     der Demo, laedt das CP JavaScript von einem Rechner, den es dort nicht
#     gibt. Es ist in den Addon-Repos ignoriert, kann aber ueber den
#     Arbeitsbaum-Overlay aus Schritt 2 mitkommen.
find "$ZIEL/public/vendor" -maxdepth 3 -name hot -type f -print -delete

# 4) installed.json auf die Tag-Versionen ziehen. Der Grundbestand kennt die
#    Pfad-Repos als dev-main; der Server soll die echte Version sehen.
while read -r repo tag; do
    case $repo in ''|'#'*) continue ;; esac
    version=${tag#v}
    jq --arg repo "goldnead/$repo" --arg v "$version" \
        '(.packages[] | select(.name == $repo) | .version) = $v' \
        "$ZIEL/vendor/composer/installed.json" > "$ZIEL/vendor/composer/installed.json.tmp"
    mv "$ZIEL/vendor/composer/installed.json.tmp" "$ZIEL/vendor/composer/installed.json"

    # Nachzaehlen, nicht behaupten. `select(.name == $repo)` trifft bei einem
    # Tippfehler in tags.conf oder einem fehlenden Paket schlicht nichts, jq gibt
    # das Dokument unveraendert mit Exit 0 zurueck, und mv gelingt ebenfalls.
    # Das Addon liefe dann als dev-main auf dem Server, waehrend hier "Fertig"
    # steht — ein Update, das als erfolgt gilt, ohne dass jemand nachgesehen hat.
    if ! jq -e --arg repo "goldnead/$repo" --arg v "$version" \
        'any(.packages[]; .name == $repo and .version == $v)' \
        "$ZIEL/vendor/composer/installed.json" > /dev/null; then
        echo "ABBRUCH: goldnead/$repo steht nach dem Stempeln nicht mit $version" >&2
        echo "         in vendor/composer/installed.json. Name in tags.conf pruefen." >&2
        exit 1
    fi
done < "$(dirname "$0")/tags.conf"

# 5) Migrationen zaehlen, damit der naechste Schritt nicht optional aussieht.
#
#    Am 03.09.2026 gingen statamic-assessments und statamic-clientrooms als Code
#    raus, ihre Migrationen liefen nie, und /cp/assessments wie /cp/client-rooms
#    antworteten mit HTTP 500 (`no such table`). Hier steht deshalb, wie viele
#    Migrationsdateien im Build liegen — nicht als Erinnerung, sondern als Zahl,
#    die man sieht.
migrationen=$(find "$ZIEL/vendor/goldnead" -path '*/database/migrations/*.php' 2>/dev/null | wc -l)

echo "-> Fertig. $migrationen Migrationsdateien in den Addons."
# Host und Zielverzeichnis stehen hier absichtlich nicht: das Repo ist oeffentlich.
# Die echten Werte liegen in GoldnerOS/memory/reference-statamic-demo-deploy.md.
echo "   Ausrollen mit allen fuenf Schritten (rsync, chown, package:discover,"
echo "   migrate --force, pristine):"
echo ""
echo "     HOST=… APPDIR=… ./deploy/rollout.sh $ZIEL"
echo ""
echo "   Von Hand rsyncen laesst regelmaessig Schritte aus — genau so entstanden"
echo "   die 500er vom 03.09. (migrate vergessen) und vom 05.09. (chown und"
echo "   pristine vergessen)."
