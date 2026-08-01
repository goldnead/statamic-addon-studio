#!/usr/bin/env bash
# Release-Kette fuer die goldnead-Statamic-Addon-Familie.
#
# Der Audit-Befund, aus dem dieses Skript folgt: Composer liest den `repositories`-Block
# einer Dependency NICHT — nur den des Root-Projekts. Deshalb ist jedes Addon nur so
# installierbar wie sein am schlechtesten verfuegbares Geschwister, und deshalb wird
# strikt von unten nach oben veroeffentlicht.
#
# Jeder Schritt ist einzeln aufrufbar und idempotent. Nichts laeuft ohne Argument.
#
#   ./release-family.sh check      — Ist-Zustand: Sichtbarkeit + Packagist je Paket
#   ./release-family.sh public     — die 5 privaten Repos oeffentlich machen
#   ./release-family.sh verify     — aus einem leeren Verzeichnis heraus aufloesen
#   ./release-family.sh drop-repos — tote `repositories`-Bloecke entfernen (nach Packagist)
set -uo pipefail

export PATH="$HOME/.local/studio-bin:$PATH"
WEBDEV="$HOME/Documents/WebDev"

# Veroeffentlichungsreihenfolge = Abhaengigkeitsreihenfolge, Fundament zuerst.
# Stufe 0: keine Geschwister-Abhaengigkeit
# Stufe 1: nur brand-context
# Stufe 2: brand-context + Stufe-1-Pakete
LEVEL0=(brand-context identity-contracts email-templates toc)
LEVEL1=(suppression leadhub automations webhook-manager)
LEVEL2=(marketing activity notifications preference-center)
ALL=("${LEVEL0[@]}" "${LEVEL1[@]}" "${LEVEL2[@]}")

# Heute privat. Werden fuer Packagist zwingend oeffentlich gebraucht.
PRIVATE=(activity identity-contracts notifications preference-center suppression)

packagist_status() {
  local pkg="$1"
  php -r '
    $u = "https://repo.packagist.org/p2/goldnead/statamic-".$argv[1].".json";
    $c = curl_init($u);
    curl_setopt_array($c, [CURLOPT_NOBODY=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10]);
    curl_exec($c);
    echo curl_getinfo($c, CURLINFO_HTTP_CODE);
  ' "$pkg" 2>/dev/null || echo "ERR"
}

cmd_check() {
  printf "%-22s %-10s %-10s %-12s %s\n" PAKET SICHTBAR PACKAGIST TAG "REPOS-BLOCK"
  for p in "${ALL[@]}"; do
    local repo="statamic-$p" dir="$WEBDEV/statamic-$p"
    local vis pk tag rb
    vis=$(gh repo view "goldnead/$repo" --json visibility -q .visibility 2>/dev/null || echo "?")
    pk=$(packagist_status "$p")
    tag=$(git -C "$dir" describe --tags --abbrev=0 2>/dev/null || echo "-")
    rb=$(php -r '$c=json_decode(file_get_contents($argv[1]),true); echo count($c["repositories"]??[]);' \
         "$dir/composer.json" 2>/dev/null || echo "?")
    [ "$pk" = "200" ] && pk="ja" || pk="NEIN($pk)"
    printf "%-22s %-10s %-10s %-12s %s\n" "$p" "$vis" "$pk" "$tag" "$rb"
  done
}

cmd_public() {
  echo "Macht ${#PRIVATE[@]} Repos oeffentlich. Das ist praktisch nicht rueckholbar."
  for p in "${PRIVATE[@]}"; do
    local repo="statamic-$p"
    local vis
    vis=$(gh repo view "goldnead/$repo" --json visibility -q .visibility 2>/dev/null)
    if [ "$vis" = "PUBLIC" ]; then echo "-- $repo ist bereits public"; continue; fi
    echo "-- $repo: $vis -> PUBLIC"
    gh repo edit "goldnead/$repo" --visibility public --accept-visibility-change-consequences \
      && echo "   ok" || echo "   !! fehlgeschlagen"
  done
}

# Der einzige ehrliche Test: aus einem leeren Verzeichnis, ohne die Path-Repos
# dieses Rechners. Was hier aufloest, kann ein Kaeufer installieren.
cmd_verify() {
  local tmp; tmp=$(mktemp -d)
  echo "Aufloesungstest in $tmp (ohne lokale Path-Repos)"
  cd "$tmp" || exit 1
  echo '{}' > composer.json
  for p in "${ALL[@]}"; do
    if composer require "goldnead/statamic-$p" --no-install --dry-run --no-interaction \
         >/dev/null 2>&1; then
      echo "  OK    goldnead/statamic-$p"
    else
      echo "  FEHLT goldnead/statamic-$p"
    fi
  done
  rm -rf "$tmp"
}

# Erst ausfuehren, wenn `verify` fuer alle Geschwister OK meldet. Vorher entfernt
# das Skript die einzige Bruecke, ueber die die Pakete lokal noch aufloesen.
cmd_drop_repos() {
  for p in "${ALL[@]}"; do
    local dir="$WEBDEV/statamic-$p"
    php -r '
      $f = $argv[1] . "/composer.json";
      $c = json_decode(file_get_contents($f), true);
      if (empty($c["repositories"])) { echo "-- kein Block: " . basename($argv[1]) . "\n"; exit; }
      $kept = array_values(array_filter($c["repositories"], function ($r) {
        return ! (($r["type"] ?? "") === "vcs" && str_contains($r["url"] ?? "", "goldnead/statamic-"));
      }));
      $n = count($c["repositories"]) - count($kept);
      if ($kept) { $c["repositories"] = $kept; } else { unset($c["repositories"]); }
      // UNESCAPED_UNICODE ist Pflicht: ohne das wird jeder Gedankenstrich in einer
      // Beschreibung zu — und der Diff enthaelt Aenderungen, die niemand wollte.
      file_put_contents($f, json_encode(
          $c, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE
      ) . "\n");
      echo "-- " . basename($argv[1]) . ": $n Eintrag/Eintraege entfernt\n";
    ' "$dir"
  done
  echo
  echo 'Jetzt je Repo `composer update --dry-run` pruefen, dann committen.'
}

# Packagist-Anmeldung ueber die API statt ueber den Browser. Braucht PACKAGIST_USERNAME
# und PACKAGIST_API_TOKEN (Profil -> Show API Token) in der Umgebung oder in
# GoldnerOS/.env. Ohne Token gibt die Funktion die Browser-Schritte in der richtigen
# Reihenfolge aus und meldet sich nirgends an.
cmd_submit() {
  local envfile="$HOME/GoldnerOS/.env"
  if [ -z "${PACKAGIST_API_TOKEN:-}" ] && [ -f "$envfile" ]; then
    PACKAGIST_USERNAME=$(grep -m1 '^PACKAGIST_USERNAME=' "$envfile" 2>/dev/null | cut -d= -f2-)
    PACKAGIST_API_TOKEN=$(grep -m1 '^PACKAGIST_API_TOKEN=' "$envfile" 2>/dev/null | cut -d= -f2-)
  fi

  if [ -z "${PACKAGIST_API_TOKEN:-}" ]; then
    echo "Kein PACKAGIST_API_TOKEN gefunden. Zwei Wege:"
    echo
    echo "  A) Token hinterlegen (dann macht dieses Skript den Rest):"
    echo "     packagist.org -> Profil -> Show API Token"
    echo "     In $envfile eintragen:"
    echo "       PACKAGIST_USERNAME=goldnead"
    echo "       PACKAGIST_API_TOKEN=..."
    echo
    echo "  B) Von Hand im Browser, packagist.org -> Submit, in dieser Reihenfolge."
    echo "     Die Reihenfolge ist nicht Geschmack: ein Paket ist nur so installierbar"
    echo "     wie sein am schlechtesten verfuegbares Geschwister."
    local i=1
    for p in "${ALL[@]}"; do
      printf "     %2d. https://github.com/goldnead/statamic-%s\n" "$i" "$p"; i=$((i+1))
    done
    echo
    echo "  Danach einmalig die Packagist-GitHub-App auf dem Konto installieren,"
    echo "  sonst existiert ein neuer Tag auf GitHub und fuer Composer nicht."
    return 1
  fi

  for p in "${ALL[@]}"; do
    local code
    code=$(php -r '
      $url = "https://packagist.org/api/create-package?username=".urlencode($argv[1])
           . "&apiToken=".urlencode($argv[2]);
      $body = json_encode(["repository" => ["url" => "https://github.com/goldnead/statamic-".$argv[3]]]);
      $c = curl_init($url);
      curl_setopt_array($c, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
      ]);
      $r = curl_exec($c);
      echo curl_getinfo($c, CURLINFO_HTTP_CODE) . " " . substr((string) $r, 0, 200);
    ' "$PACKAGIST_USERNAME" "$PACKAGIST_API_TOKEN" "$p")
    printf "%-22s %s\n" "$p" "$code"
  done
}

case "${1:-}" in
  check)      cmd_check ;;
  public)     cmd_public ;;
  verify)     cmd_verify ;;
  submit)     cmd_submit ;;
  drop-repos) cmd_drop_repos ;;
  *) sed -n '2,20p' "$0"; exit 1 ;;
esac
