#!/usr/bin/env bash
#
# ui-sweep — the mechanically checkable half of standards/ui-vocabulary.md §9 and §9.1.
#
# Read-only. Greps an addon's Vue sources for the mistakes that render without a
# warning: a slot Listing has not got, an icon name that is not in the set, a
# combobox bound to '', a checkbox in a table cell without `solo`. Plus the
# louder antipatterns — content on a bare Panel, a danger button in a page
# header, hard-coded colours.
#
# Every hit is a candidate, not a verdict: `grep` cannot see whether a Panel
# actually holds a Card two lines down. Read the line before believing it.
#
# EVERY RULE HERE NOW LIVES IN tools/addon-lint/rules/NativeUiRules.php, which is
# their permanent home (ported 03.09.2026; both report the same 31 candidates
# across the family, on the same lines). Prefer the linter:
#
#   php8.4 tools/addon-lint/bin/addon-lint <addon-path> --category=ui
#
# It carries a rationale and a severity per finding, has smoke tests, and is the
# thing to extend. This script stays as the quick eyeball over the whole family
# grouped by addon — nothing more. A rule added here and not there will drift.
#
# Usage:
#   tools/ui-sweep.sh                       # every statamic-* sibling of the studio
#   tools/ui-sweep.sh ../statamic-leadhub   # one addon
set -uo pipefail

STUDIO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ICONS="$STUDIO/playground/vendor/statamic/cms/resources/svg/icons"

if [ ! -d "$ICONS" ]; then
    echo "no icon set at $ICONS — run composer install in the playground first" >&2
    exit 1
fi

if [ $# -gt 0 ]; then
    ADDONS=("$@")
else
    mapfile -t ADDONS < <(find "$(dirname "$STUDIO")" -maxdepth 1 -type d -name 'statamic-*' \
        -not -name 'statamic-addon-studio' | sort)
fi

total=0

# report <label> <body>
report() {
    local label="$1" body="$2"
    [ -z "$body" ] && return 0
    local n
    n=$(printf '%s\n' "$body" | grep -c .)
    total=$((total + n))
    printf '  %-32s %3d\n' "$label" "$n"
    printf '%s\n' "$body" | head -5 | sed 's/^/      /'
    [ "$n" -gt 5 ] && printf '      … %d more\n' "$((n - 5))"
}

for dir in "${ADDONS[@]}"; do
    js="$dir/resources/js"
    [ -d "$js" ] || continue
    name=$(basename "$dir")

    before=$total
    printf '\n=== %s\n' "$name"

    # §9.1 — an unknown icon name renders an empty box, no warning.
    #
    # Two spellings, and the second is easy to forget: `icon="…"` as a prop on
    # any component, and `name="…"` on `<Icon>` itself. A sweep that checks only
    # the first misses every standalone icon on the page.
    report "icon does not exist" "$(
        {
            grep -rhoE 'icon="[a-z0-9-]+"' "$js" --include=*.vue 2>/dev/null | sed 's/icon="//;s/"//'
            grep -rhoE '<Icon\b[^>]*[^:]name="[a-z0-9-]+"' "$js" --include=*.vue 2>/dev/null | grep -oE '[^:]name="[a-z0-9-]+"' | sed 's/.*name="//;s/"//'
            # And the third spelling: a bound expression that still names icons
            # literally — `:icon="ok ? 'checkmark' : 'x'"`. A ternary hid a
            # non-existent `check` in one addon, so the confirm button lost its
            # icon at exactly the moment it confirmed.
            # Strip the comparison side first — in
            # `:icon="sort === 'asc' ? 'arrow-up' : 'arrow-down'"` the string
            # `asc` is what is being tested, not an icon anybody renders.
            grep -rhoE ':(icon|name)="[^"]*"' "$js" --include=*.vue 2>/dev/null \
                | sed -E "s/[!=]==? *'[^']*'//g" \
                | grep -oE "'[a-z][a-z0-9-]{2,}'" | tr -d "'"
        } | sort -u | while read -r i; do [ -f "$ICONS/$i.svg" ] || echo "$i"; done
    )"

    # §9.1 — a slot INSIDE a <Listing> that Listing has not got. Vue drops it
    # without a word, and the action it held never renders.
    #
    # Scoped to the Listing block on purpose: `#actions` is correct on `Header`,
    # `#footer` is correct on `Modal` and `Stack`. Only inside `<Listing>` are
    # they dead. Listing's slots are `initializing`, `default`,
    # `prepended-row-actions`, plus `cell-{name}` and `tbody-start`.
    report "dead slot inside <Listing>" "$(
        find "$js" -name '*.vue' -exec perl -0777 -ne '
            while (/<Listing\b.*?<\/Listing>/gs) {
                my $block = $&;
                my $start = 1 + substr($_, 0, pos($_) - length($&)) =~ tr/\n//;
                while ($block =~ /#(actions|empty|footer|header)[=>]/g) {
                    my $off = substr($block, 0, pos($block)) =~ tr/\n//;
                    print "$ARGV:" . ($start + $off) . " #$1\n";
                }
            }' {} \; 2>/dev/null
    )"

    # §9.1 — a prop the component has not got. Vue passes an unknown prop
    # through as a plain attribute, so nothing warns and the value simply has
    # no effect. Verified against
    # vendor/statamic/cms/resources/dist-package/types/components/ui/*.d.ts.
    report "prop the component has not got" "$(
        find "$js" -name '*.vue' -exec perl -0777 -ne '
            my @bad = (
                # TabTrigger takes `text` + `name`, not `label`/`has-error`.
                # Getting this wrong renders an empty tab strip, which makes
                # every tab behind it unreachable.
                ["TabTrigger",         qr/:?(label|has-error)=/,        "label/has-error"],
                # Alert knows default|warning|error|success. `danger` and
                # `info` both fall through to the neutral style, so a failure
                # and a hint look like nothing.
                #
                # The lookbehind is load-bearing: without it `variant="` also
                # matches inside `:variant="`, and the lookahead then reads the
                # expression instead of a literal. That produced seven false
                # alarms in one addon before it was caught.
                ["Alert",              qr/(?<![-:\w])variant="(?!default|warning|error|success)/, "unknown variant"],
                # And the bound form, where the literals sit inside the
                # expression: :variant="ok ? \x27success\x27 : \x27danger\x27".
                # \x27 is an apostrophe — the whole perl program is inside
                # single quotes in bash, so a literal one would end it.
                ["Alert",              qr/:variant="[^"]*\x27(?!default\x27|warning\x27|error\x27|success\x27)[a-z]+\x27/, "unknown variant (bound)"],
                # DropdownItem takes variant="destructive"; a bare `danger`
                # attribute colours nothing.
                ["DropdownItem",       qr/(?<![-\w])danger(?![-\w=])/,  "bare danger"],
                # Panel takes heading/subheading/icon only.
                ["Panel",              qr/(?<![-\w])collapsible(?![-\w])/, "collapsible"],
            );
            for my $r (@bad) {
                my ($tag, $re, $what) = @$r;
                while (/<\Q$tag\E\b(.*?)\/?>/gs) {
                    # $-[0] belongs to the LAST successful match, and the
                    # attribute test below is one. Read the line off the tag
                    # before testing it, or the finding points at the tag end.
                    my ($a, $n) = ($1, 1 + (substr($_, 0, $-[0]) =~ tr/\n//));
                    next unless $a =~ $re;
                    print "$ARGV:$n <$tag> $what\n";
                }
            }
            # CommandPaletteItem runs `action` or `url`; an @click on it is
            # never called, and core logs a console warning nobody reads.
            while (/<CommandPaletteItem\b(.*?)>/gs) {
                my ($a, $n) = ($1, 1 + (substr($_, 0, $-[0]) =~ tr/\n//));
                next unless $a =~ /\@click/;
                next if $a =~ /:?(action|url)=/;
                print "$ARGV:$n <CommandPaletteItem> \@click without action/url\n";
            }' {} \; 2>/dev/null
    )"

    # §9.1 — a combobox bound to '' counts as "something is selected", so the
    # trigger renders an empty label instead of the placeholder.
    #
    # Unless the option list actually contains a `value: ''` entry — then the
    # empty string IS a choice with a label ("No opportunity") and binding it is
    # correct. Skip those files.
    report "picker bound to '' not null" "$(
        grep -rln "modelValue ? String(props.modelValue) : ''" "$js" --include=*.vue 2>/dev/null \
            | while read -r f; do
                  grep -q "value: ''" "$f" || grep -n "modelValue ? String(props.modelValue) : ''" "$f" | sed "s|^|$f:|"
              done
    )"

    # The next three read whole tags, not lines: a Vue tag is routinely spread
    # over six lines, and a line-based grep reports every multi-line component
    # as missing the prop that sits two lines down.

    # §9.1 — Checkbox without `solo` prints its own value where the label goes.
    # Only inside a listing cell; elsewhere a label is correct.
    report "Checkbox in cell without solo" "$(
        find "$js" -name '*.vue' -exec perl -0777 -ne '
            while (/#cell-.{0,900}?<Checkbox\b(.*?)\/>/gs) {
                # $-[1], not $-[0]: the match starts back at `#cell-`, and the
                # finding belongs on the <Checkbox> line.
                my ($t, $n) = ($1, 1 + (substr($_, 0, $-[1]) =~ tr/\n//));
                next if $t =~ /\bsolo\b/ || $t =~ /:?label=/;
                print "$ARGV:$n\n";
            }' {} \; 2>/dev/null
    )"

    # §22 — a status badge is pill + colour. A square chip (a tag, a count) is
    # fine, so this asks rather than asserts.
    report 'Badge size="sm", pill? (check)' "$(
        find "$js" -name '*.vue' -exec perl -0777 -ne '
            while (/<Badge\b(.*?)\/>/gs) {
                my ($t, $n) = ($1, 1 + (substr($_, 0, $-[0]) =~ tr/\n//));
                next unless $t =~ /size="sm"/;
                next if $t =~ /\bpill\b/;
                print "$ARGV:$n\n";
            }' {} \; 2>/dev/null
    )"

    # §24 — danger belongs in a confirmation modal, not a page header.
    # Only <Button>: <Text variant="danger"> is the correct way to colour an
    # error message and is not a finding.
    #
    # Matches both closings (`/>` and `></Button>`) and both spellings of the
    # value: the literal `variant="danger"` and a bound expression that still
    # names it, `:variant="x ? 'danger' : 'default'"`. The bound form is how a
    # red row button survived the first version of this rule.
    report 'Button variant="danger"' "$(
        find "$js" -name '*.vue' -exec perl -0777 -ne '
            while (/<Button\b(.*?)(?:\/>|>)/gs) {
                my ($t, $n) = ($1, 1 + (substr($_, 0, $-[0]) =~ tr/\n//));
                next unless $t =~ /variant="danger"/ || $t =~ /:variant="[^"]*'"'"'danger'"'"'/;
                print "$ARGV:$n\n";
            }' {} \; 2>/dev/null
    )"

    # §24 — Dropdown already renders its own dots trigger.
    report "own dots trigger on Dropdown" "$(
        grep -rn -A4 '<template #trigger>' "$js" --include=*.vue 2>/dev/null | grep 'icon="dots"'
    )"

    # §19 — a Panel whose body is a padded div: content straight onto the grey.
    report "padded div directly in Panel" "$(
        grep -rn -A1 '<Panel' "$js" --include=*.vue 2>/dev/null \
            | grep -E '[-:][0-9]+[-:][[:space:]]*<div class="p[xy]?-[0-9]'
    )"

    # §12 — a hand-built table loses everything Listing gives. Not in comments:
    # statamic-booking documents why it does NOT build one, and said so with the
    # word `<table>`, which the first version of this sweep reported as a table.
    report "hand-built <table>" "$(
        grep -rn '<table' "$js" --include=*.vue 2>/dev/null \
            | grep -vE ':[[:space:]]*(\*|//|<!--)'
    )"

    # §3 — colours that do not follow the user's theme.
    report "hard-coded colour" "$(
        grep -rnoE 'class="[^"]*(bg-white|bg-slate-|bg-indigo-|text-slate-)' "$js" --include=*.vue 2>/dev/null
    )"

    # §15 — the container token is max-w-page.
    report "custom width container" "$(
        grep -rn 'max-w-7xl\|container mx-auto' "$js" --include=*.vue 2>/dev/null
    )"

    # §16 — icon props want a name, not markup.
    report "inline SVG" "$(
        grep -rn '<svg' "$js" --include=*.vue 2>/dev/null
    )"

    # §13 — a mutation that changes the page goes through the Inertia router, or
    # it loses the progress bar, flash toasts, the dirty guard and the back
    # button. Fetching JSON to fill a picker is a legitimate axios call, so this
    # asks rather than asserts.
    report "axios write (check)" "$(
        grep -rnE 'axios\.(post|patch|put|delete)' "$js" --include=*.vue 2>/dev/null
    )"

    # §8 — v5 class names render unstyled in v6. The leading boundary must be
    # the start of the attribute or a space: `sa-token-btn` is the addon's own
    # class, not core's dead `.btn`.
    report "v5 class name" "$(
        grep -rnE 'class="(|[^"]* )(btn|btn-primary|publish-fields|flexy|little-heading|subhead)( |")' "$js" --include=*.vue 2>/dev/null
    )"

    [ $total -eq $before ] && echo "  clean"
done

printf '\n%d candidate(s) across %d addon(s).\n' "$total" "${#ADDONS[@]}"
echo "Candidates, not verdicts — read the line before fixing it."
