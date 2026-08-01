#!/usr/bin/env bash
# Stellt die beiden gitignorierten Teile des Studios wieder her:
#   reference/   — Upstream-Klone, gegen die die Standards und addon-lint kalibriert sind
#   playground/  — pristines Statamic 6 fuer den Seite-an-Seite-Vergleich mit Core
# Idempotent: vorhandene Klone werden nur aktualisiert.
set -uo pipefail

STUDIO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export PATH="$HOME/.local/studio-bin:$PATH"   # php 8.5 + Herd composer

echo "== PHP: $(php -r 'echo PHP_VERSION;')"

# ---------------------------------------------------------------- reference/
mkdir -p "$STUDIO/reference"
cd "$STUDIO/reference" || exit 1

# statamic/cms zuerst: ui-vocabulary.md zitiert direkt daraus.
declare -a REPOS=(
  "statamic__cms|https://github.com/statamic/cms.git"
  "statamic__seo-pro|https://github.com/statamic/seo-pro.git"
  "statamic__runway|https://github.com/statamic/runway.git"
  "statamic__eloquent-driver|https://github.com/statamic/eloquent-driver.git"
  "statamic__ssg|https://github.com/statamic/ssg.git"
  "statamic__collaboration|https://github.com/statamic/collaboration.git"
  "duncanmcclean__simple-commerce|https://github.com/duncanmcclean/simple-commerce.git"
  "duncanmcclean__guest-entries|https://github.com/duncanmcclean/guest-entries.git"
  # duncanmcclean/sitemap: Repo unter keinem der bekannten Namen mehr erreichbar (01.08.2026).
  "jonassiewertsen__statamic-livewire|https://github.com/jonassiewertsen/statamic-livewire.git"
  "aerni__advanced-seo|https://github.com/aerni/advanced-seo.git"
  "spatie__statamic-responsive-images|https://github.com/spatie/statamic-responsive-images.git"
  "edalzell__forma|https://github.com/edalzell/statamic-forma.git"
)

for entry in "${REPOS[@]}"; do
  dir="${entry%%|*}"; url="${entry##*|}"
  if [ -d "$dir/.git" ]; then
    echo "-- update $dir"
    git -C "$dir" fetch --quiet --tags --prune && git -C "$dir" pull --quiet --ff-only 2>/dev/null \
      || echo "   !! pull fehlgeschlagen: $dir"
  else
    echo "-- clone  $dir"
    git clone --quiet --depth 50 "$url" "$dir" || echo "   !! clone fehlgeschlagen: $url"
  fi
done

echo "== reference/: $(find "$STUDIO/reference" -maxdepth 1 -mindepth 1 -type d | wc -l | tr -d ' ') Repos"

# --------------------------------------------------------------- playground/
if [ -f "$STUDIO/playground/artisan" ]; then
  echo "== playground/ existiert bereits, uebersprungen"
  exit 0
fi

echo "== playground: Statamic 6 installieren"
rm -rf "$STUDIO/playground"
cd "$STUDIO" || exit 1
composer create-project statamic/statamic playground --no-interaction --quiet || {
  echo "!! create-project fehlgeschlagen"; exit 1; }

cd "$STUDIO/playground" || exit 1
php please make:user --no-interaction \
  --email=studio@local --password=studio-local-password --super 2>/dev/null \
  || echo "!! Superuser bitte manuell anlegen: php please make:user"

echo "== fertig. Start: cd $STUDIO/playground && php artisan serve --port=8099"
