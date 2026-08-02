#!/usr/bin/env bash
# Faehrt das Release-Gate gegen ein oder mehrere Addon-Repos und meldet nur das Ergebnis.
# Existiert, weil ein Bericht eines Agenten eine Behauptung ist und kein Beleg.
#
#   ./verify-gate.sh brand-context suppression
#   ./verify-gate.sh --all
set -uo pipefail

export PATH="$HOME/.local/studio-bin:$PATH"
STUDIO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Wurzel, unter der die Addon-Repos liegen. Ueberschreibbar, weil sie nicht immer
# unter ~/Documents/WebDev stehen — auf dem Laptop ist das seit dem 31.07. wegen einer
# offenen macOS-Dateiberechtigung unlesbar und jeder Zugriff dorthin blockiert.
# Fallback auf den Ausweichordner, wenn WebDev nicht lesbar ist.
if [ -n "${ADDON_ROOT:-}" ]; then
  WEBDEV="$ADDON_ROOT"
elif ls "$HOME/Documents/WebDev" >/dev/null 2>&1; then
  WEBDEV="$HOME/Documents/WebDev"
else
  WEBDEV="$HOME/projects-tcc-workaround"
  echo "== ~/Documents/WebDev nicht lesbar, nutze $WEBDEV"
fi

ALL=(activity automations brand-context email-templates events identity-contracts
     lead-magnets leadhub marketing notifications preference-center suppression
     toc webhook-manager)

if [ "${1:-}" = "--all" ]; then set -- "${ALL[@]}"; fi
[ $# -gt 0 ] || { echo "usage: verify-gate.sh <addon>... | --all"; exit 1; }

printf "%-20s %-22s %-10s %-12s %-22s %s\n" ADDON TESTS PINT PHPSTAN "LINT b/M/m" COMMITS
for p in "$@"; do
  dir="$WEBDEV/statamic-$p"
  [ -d "$dir" ] || { printf "%-20s %s\n" "$p" "KEIN REPO"; continue; }
  cd "$dir" || continue

  # Testrunner: die Familie nutzt beides, Pest und PHPUnit.
  # Pest faerbt seine Ausgabe auch ohne TTY; ohne das sed ist die Spalte unlesbar.
  strip_ansi() { sed -E $'s/\x1b\\[[0-9;]*[a-zA-Z]//g'; }
  tests="kein vendor/"
  if [ -x vendor/bin/pest ]; then
    tests=$(vendor/bin/pest --colors=never 2>&1 | strip_ansi | grep -oE 'Tests: .*' | head -1)
  elif [ -x vendor/bin/phpunit ]; then
    tests=$(vendor/bin/phpunit 2>&1 | strip_ansi \
      | grep -oE 'OK \(.*\)|FAILURES.*|ERRORS.*' | head -1)
  fi
  [ -n "$tests" ] || tests="?"

  pint="fehlt"
  [ -x vendor/bin/pint ] && { vendor/bin/pint --test >/dev/null 2>&1 && pint="ok" || pint="ROT"; }

  stan="fehlt"
  [ -x vendor/bin/phpstan ] && {
    vendor/bin/phpstan analyse --no-progress --error-format=raw >/dev/null 2>&1 \
      && stan="ok" || stan="ROT"; }

  lint=$(php "$STUDIO/tools/addon-lint/bin/addon-lint" . --format=json --fail-on=never 2>/dev/null \
    | php -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        $f = $d["addons"][0]["findings"] ?? [];
        $c = ["blocker"=>0,"major"=>0,"minor"=>0];
        foreach ($f as $x) { if (isset($c[$x["severity"]])) $c[$x["severity"]]++; }
        echo "{$c["blocker"]}/{$c["major"]}/{$c["minor"]}";
      ' 2>/dev/null)
  [ -n "$lint" ] || lint="?"

  n=$(git rev-list --count @{u}..HEAD 2>/dev/null || echo "?")
  dirty=$(git status --porcelain | wc -l | tr -d ' ')
  [ "$dirty" = "0" ] || n="$n +${dirty}dirty"

  printf "%-20s %-22s %-10s %-12s %-22s %s\n" "$p" "${tests:0:22}" "$pint" "$stan" "$lint" "$n"
done
