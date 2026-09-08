#!/bin/sh
# Naechtlicher Reset der Demo. Auf dem Server als $APPDIR/reset.sh, per cron 03:17 UTC.
#
# Stellt den gezaehnten Zustand aus pristine.tar.gz wieder her: Datenbank,
# Inhalte, CP-Konten, Config. Alles, was Besucher am Tag veraendert haben, ist
# danach weg.
#
# Diese Datei ist die versionierte Fassung. Sie lag bis 08.09.2026 nur auf dem
# Server, und ein neu aufgesetzter Demo-Server haette die Migrationszeile unten
# nicht mitgebracht — also genau den Vorfall wiederholt, gegen den sie steht.
# Ausrollen mit:  scp deploy/demo-reset.sh root@$HOST:$APPDIR/reset.sh
#
# Das Repo ist oeffentlich, deshalb stehen Verzeichnis, Container und Port als
# Variablen. Echte Werte: GoldnerOS/memory/reference-statamic-demo-deploy.md.
set -eu

APPDIR=${APPDIR:-/opt/statamic-demo}
CONTAINER=${CONTAINER:-statamic-demo}
HEALTH=${HEALTH:-http://127.0.0.1:8099/up}
LOG=${LOG:-/var/log/statamic-demo-reset.log}

cd "$APPDIR"

docker compose stop app

rm -rf app/content app/users app/database app/config app/storage
tar xzf pristine.tar.gz -C app
chown -R 33:33 app/content app/users app/database app/config app/storage

docker compose up -d app
sleep 3

# Migrationen NACH dem Wiederherstellen, nicht davor.
#
# pristine.tar.gz ist ein Abzug der Datenbank von dem Tag, an dem er gezogen
# wurde. Wer ein Addon mit neuer Migration ausrollt und den Abzug nicht neu
# zieht, hat den migrierten Stand bis 03:17 UTC — danach steht die alte
# Datenbank wieder da, ohne die neue Tabelle, und die CP-Seite antwortet mit
# HTTP 500. Genau so starben /cp/assessments und /cp/client-rooms am
# 03.09.2026 (`no such table: assessments` / `client_rooms`).
#
# Eine Zeile hier heilt das jede Nacht selbst, statt es jede Nacht zu
# wiederholen. Ohne `--force` fragt artisan nach und der cron haengt.
if docker exec -u www-data -w /var/www/html "$CONTAINER" php artisan migrate --force >> "$LOG" 2>&1; then
    echo "$(date -Is) Reset: migrate OK" >> "$LOG"
else
    echo "$(date -Is) Reset: migrate FEHLGESCHLAGEN" >> "$LOG"
fi

# Die Migration schreibt als www-data, aber SQLite legt beim Schreiben
# Begleitdateien an. Einmal geradeziehen kostet nichts.
chown -R 33:33 app/database

if curl -fsS -o /dev/null "$HEALTH"; then
    echo "$(date -Is) Reset OK" >> "$LOG"
else
    echo "$(date -Is) Reset: /up nicht erreichbar" >> "$LOG"
fi
