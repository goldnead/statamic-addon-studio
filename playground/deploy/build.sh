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
while read -r repo tag; do
    case $repo in ''|'#'*) continue ;; esac
    ziel="$ZIEL/vendor/goldnead/$repo"
    rm -rf "$ziel"
    mkdir -p "$ziel"
    git -C "$HOME/projects/$repo" archive "$tag" | tar -x -C "$ziel"
    echo "   $repo @ $tag"
done < "$(dirname "$0")/tags.conf"

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
echo "   rsync -a $ZIEL/ root@157.90.224.18:/opt/statamic-demo/app/"
echo "   dann im Container: composer dump-autoload && php artisan package:discover && php artisan migrate --force"
