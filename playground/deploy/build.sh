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
done < "$(dirname "$0")/tags.conf"

echo "-> Fertig. Auf den Server:"
# Host und Zielverzeichnis stehen hier absichtlich nicht: das Repo ist oeffentlich.
# Die echten Werte liegen in GoldnerOS/memory/reference-statamic-demo-deploy.md.
echo "   rsync -a $ZIEL/ root@\$HOST:\$APPDIR/app/"
# Kein `composer dump-autoload`: im Demo-Container gibt es kein composer, nur php
# (gepruft 03.09.2026). Gebraucht wird es auch nicht — die Autoload-Dateien kommen
# fertig aus diesem Build, die Addons laden per PSR-4.
echo "   dann im Container: php artisan package:discover && php artisan migrate --force"
