<?php

declare(strict_types=1);

namespace StatamicAddonStudio\Lint\Rules;

use StatamicAddonStudio\Lint\AbstractRule;
use StatamicAddonStudio\Lint\AddonContext;
use StatamicAddonStudio\Lint\Finding;
use StatamicAddonStudio\Lint\Severity;

/**
 * The "does this look like Statamic built it" rules.
 *
 * Each maps to a numbered antipattern in `standards/ui-vocabulary.md` §9, which was derived
 * from statamic/cms 6.x itself. The section number is named in every rationale so a finding
 * can always be traced back to the evidence.
 */
final class LegacyCpApiRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.legacy-cp-api';
    }

    public function title(): string
    {
        return 'Do not call Control-Panel APIs that Statamic 6 removed';
    }

    public function category(): string
    {
        return 'ui';
    }

    public function severity(): string
    {
        return Severity::BLOCKER;
    }

    public function rationale(): string
    {
        return 'ui-vocabulary §9.7/§9.9/§9.11: `Statamic::layout()`, `Statamic.$fieldtypes` and '
            .'`Statamic.componentExists()` have zero occurrences left in core 6.x. Code calling them '
            .'fails at runtime rather than degrading.';
    }

    /** pattern => [replacement, applies to PHP?] */
    private const REMOVED = [
        '/Statamic::layout\(/' => ['Ship an Inertia page; the layout helper no longer exists.', true],
        '/Statamic\.\$fieldtypes\b/' => ['Statamic.$components.register(name, component)', false],
        '/Statamic\.componentExists\(/' => ['Statamic.$components.has(name)', false],
        '#components/data-list/#' => ['ui/Listing — the DataList namespace is deleted.', false],
    ];

    public function check(AddonContext $addon): array
    {
        $php = $addon->phpFiles();
        $scripts = array_merge($addon->vueFiles(), $addon->jsFiles());
        $findings = [];

        foreach (self::REMOVED as $pattern => [$replacement, $isPhp]) {
            foreach ($addon->grep($pattern, $isPhp ? $php : $scripts) as $hit) {
                $findings[] = $this->fail(
                    'Removed in Statamic 6: '.trim($hit['text']),
                    $hit['file'],
                    $hit['line'],
                    'Use '.$replacement
                );
            }
        }

        return $findings;
    }
}

final class LegacyBladeShellRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.legacy-blade-shell';
    }

    public function title(): string
    {
        return 'Build CP screens as Inertia pages, not Blade views extending a CP layout';
    }

    public function category(): string
    {
        return 'ui';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'ui-vocabulary §9.7: core has no Blade CP pages left. `@extends(\'statamic::layout\')` '
            .'still renders through the NonInertiaPage compatibility shim, but the page gets no '
            .'breadcrumbs, no Inertia navigation and no shared props — which is exactly what makes it '
            .'feel bolted on.';
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        foreach ($addon->grep('/@extends\(\s*[\'"]statamic::/', $addon->bladeFiles()) as $hit) {
            $findings[] = $this->fail(
                'Blade CP page extending a core layout: '.trim($hit['text']),
                $hit['file'],
                $hit['line'],
                'Render an Inertia page from the controller instead; see standards/ui-vocabulary.md.'
            );
        }

        return $findings;
    }
}

final class LegacyClassNamesRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.legacy-class-names';
    }

    public function title(): string
    {
        return 'Drop Statamic 5 CSS class names — they render as unstyled markup in v6';
    }

    public function category(): string
    {
        return 'ui';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'ui-vocabulary §9.8: `.btn`, `.card`, `.publish-fields` and friends no longer exist in the '
            .'v6 stylesheet. Markup carrying them is not "slightly off" — it is unstyled.';
    }

    private const LEGACY = ['btn', 'btn-primary', 'btn-flat', 'card', 'publish-fields', 'flexy', 'little-heading', 'subhead'];

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->cpViews() !== [] || $addon->vueFiles() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $files = array_merge($addon->cpViews(), $addon->vueFiles());
        $findings = [];

        foreach ($files as $file) {
            $contents = $addon->read($file);

            if ($contents === null) {
                continue;
            }

            foreach (explode("\n", $contents) as $index => $line) {
                if (! preg_match('/class\s*=\s*["\']([^"\']*)["\']/', $line, $m)) {
                    continue;
                }

                $classes = preg_split('/\s+/', $m[1]) ?: [];

                foreach (array_intersect($classes, self::LEGACY) as $legacy) {
                    $findings[] = $this->fail(
                        sprintf('Statamic 5 class name `%s`.', $legacy),
                        $file,
                        $index + 1,
                        'Replace with the equivalent ui-* component.'
                    );
                }
            }
        }

        return $findings;
    }
}

final class ThemeableColorsRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.themeable-colors';
    }

    public function title(): string
    {
        return 'Use the themeable colour tokens instead of stock Tailwind palette colours';
    }

    public function category(): string
    {
        return 'ui';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'ui-vocabulary §9.3: core colours resolve through `--theme-color-*`, which the user can '
            .'re-theme at runtime. Stock palette classes and hex literals do not follow, so the addon '
            .'drifts out of the theme the moment anyone changes it.';
    }

    /** class => the token that should have been used */
    private const REPLACEMENTS = [
        'bg-white' => 'bg-content-bg',
        'bg-slate-50' => 'bg-body-bg',
        'bg-slate-100' => 'bg-body-bg',
        'bg-blue-500' => 'bg-primary',
        'bg-blue-600' => 'bg-primary',
        'bg-indigo-500' => 'bg-primary',
        'bg-indigo-600' => 'bg-primary',
        'text-blue-500' => 'text-primary',
        'text-blue-600' => 'text-primary',
        'text-gray-700' => 'text-gray-900 (the themed grey)',
        'border-slate-200' => 'border-content-border',
    ];

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->vueFiles() !== [] || $addon->cpViews() !== [] || $addon->cssFiles() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $files = array_merge($addon->vueFiles(), $addon->cpViews(), $addon->cssFiles());
        $findings = [];

        foreach ($files as $file) {
            $contents = $addon->read($file);

            if ($contents === null) {
                continue;
            }

            foreach (explode("\n", $contents) as $index => $line) {
                foreach (self::REPLACEMENTS as $class => $token) {
                    if (preg_match('/(?<![\w:-])'.preg_quote($class, '/').'(?![\w-])/', $line) === 1) {
                        $findings[] = $this->fail(
                            sprintf('`%s` is not themeable.', $class),
                            $file,
                            $index + 1,
                            'Use `'.$token.'`.'
                        );
                    }
                }

                if (preg_match('/(?:color|background|border)[^;:]*:\s*#[0-9a-f]{3,8}\b/i', $line) === 1) {
                    $findings[] = $this->failWith(
                        Severity::MINOR,
                        'Hard-coded hex colour: '.trim($line),
                        $file,
                        $index + 1,
                        'Reference a --theme-color-* variable or a core utility.'
                    );
                }
            }
        }

        return $this->cap($findings, 30);
    }

    /** @param Finding[] $findings @return Finding[] */
    private function cap(array $findings, int $max): array
    {
        if (count($findings) <= $max) {
            return $findings;
        }

        $kept = array_slice($findings, 0, $max);
        $kept[] = $this->failWith(Severity::INFO, sprintf('%d further non-themeable colours suppressed.', count($findings) - $max));

        return $kept;
    }
}

final class PageWidthRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.page-width';
    }

    public function title(): string
    {
        return 'Constrain page content with a core width container';
    }

    public function category(): string
    {
        return 'ui';
    }

    public function severity(): string
    {
        return Severity::MINOR;
    }

    public function rationale(): string
    {
        return 'ui-vocabulary §9.15: the token is `--max-width-page: 85rem` and the header\'s '
            .'MaxWidthButton lets the user toggle full width. A custom container ignores that toggle and '
            .'sits at a different width than every core screen. §2.3 documents a second legitimate '
            .'width for detail and settings screens, `max-w-5xl 3xl:max-w-6xl mx-auto` with '
            .'`data-max-width-wrapper` — the attribute is what keeps the toggle working, so the narrow '
            .'variant only counts as native when it carries it.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->cpViews() !== [] || $addon->inertiaPages() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $files = array_merge($addon->cpViews(), $addon->vueFiles());
        $findings = [];

        foreach ($addon->grep('/(?<![\w-])(max-w-(?:7xl|6xl|5xl|screen-\w+)|container mx-auto)(?![\w-])/', $files) as $hit) {
            if ($this->isNarrowDetailContainer($hit['text'])) {
                continue;
            }

            $findings[] = $this->fail(
                sprintf('Custom width container `%s`.', $hit['match'][1]),
                $hit['file'],
                $hit['line'],
                'Use max-w-page, or the narrow detail variant `max-w-5xl 3xl:max-w-6xl mx-auto` with data-max-width-wrapper.'
            );
        }

        return $findings;
    }

    /**
     * ui-vocabulary §2.3 sanctions a narrow container for detail and settings screens, the one
     * core uses on pages/forms/Show.vue and pages/preferences/Edit.vue. Both width classes and
     * the opt-in attribute have to be present: without `data-max-width-wrapper` the wrapper
     * ignores the header's expand-layout toggle, which is the defect this rule exists to catch.
     */
    private function isNarrowDetailContainer(string $line): bool
    {
        return preg_match('/(?<![\w-])max-w-5xl(?![\w-])/', $line) === 1
            && preg_match('/(?<![\w-])3xl:max-w-6xl(?![\w-])/', $line) === 1
            && str_contains($line, 'data-max-width-wrapper');
    }
}

final class BareSingleColumnGridRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.bare-single-column-grid';
    }

    public function title(): string
    {
        return 'Do not ship the breakpoint-less single-column grid utility';
    }

    public function category(): string
    {
        return 'ui';
    }

    public function severity(): string
    {
        return Severity::MINOR;
    }

    public function rationale(): string
    {
        return 'Every addon ships its own Tailwind build and all of them land in the same '
            .'`addon-utilities` layer. Media queries add no specificity, so a breakpoint-less '
            .'`grid-cols-1` from the stylesheet that happens to load last wins against an earlier '
            .'addon\'s `sm:`/`lg:` variant and pins that addon\'s grid to one column at every '
            .'width. A grid falls back to one column on its own, so the class buys nothing and '
            .'costs a cross-addon collision that is invisible when an addon is checked alone. '
            .'Replace the utility\'s `minmax(0,1fr)` track with `*:min-w-0` on the container.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->cpViews() !== [] || $addon->vueFiles() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $files = array_merge($addon->cpViews(), $addon->vueFiles());
        $findings = [];

        // No variant prefix: `sm:grid-cols-1` is media-query scoped and cannot
        // out-order another addon's variant, so it is not the problem.
        //
        // Comments are matched on purpose, and are the reason this rule exists
        // as a scan rather than a review note: Tailwind treats comment text as
        // candidates, so a comment explaining why the class was removed emits
        // the class. statamic-activity shipped exactly that — a correct fix
        // whose own annotation kept the rule in the bundle.
        foreach ($addon->grep('/(?<![\w:-])grid-cols-1(?![\w-])/', $files) as $hit) {
            $findings[] = $this->fail(
                'Breakpoint-less single-column grid utility.'
                    .(str_contains($hit['text'], '<!--') || str_contains($hit['text'], '*') ? ' Naming it in a comment emits it too.' : ''),
                $hit['file'],
                $hit['line'],
                'Drop the class and put *:min-w-0 on the grid container. Do not name the class in a comment.'
            );
        }

        return $findings;
    }
}

final class InlineSvgIconRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.inline-svg-icons';
    }

    public function title(): string
    {
        return 'Reference icons by name instead of pasting SVG markup';
    }

    public function category(): string
    {
        return 'ui';
    }

    public function severity(): string
    {
        return Severity::MINOR;
    }

    public function rationale(): string
    {
        return 'ui-vocabulary §9.16: every `icon` prop takes a name from the 548-icon set, or a set the '
            .'addon registered via `Icon::register()`. Inline SVG gets the wrong size, colour and opacity '
            .'behaviour because it bypasses the icon component entirely.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->cpViews() !== [] || $addon->vueFiles() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $files = array_merge($addon->cpViews(), $addon->vueFiles());
        $findings = [];
        $seen = [];

        foreach ($addon->grep('/<svg[\s>]/i', $files) as $hit) {
            if (isset($seen[$hit['file']])) {
                continue;
            }

            $seen[$hit['file']] = true;

            $findings[] = $this->fail(
                'Inline SVG in a CP template.',
                $hit['file'],
                $hit['line'],
                'Use <ui-icon name="..."> — or register your own set with Icon::register().'
            );
        }

        return $findings;
    }
}

final class InertiaNavigationRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.inertia-navigation';
    }

    public function title(): string
    {
        return 'Mutate through Inertia\'s `router`, not raw axios calls';
    }

    public function category(): string
    {
        return 'ui';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'ui-vocabulary §9.13: `router.post()` drives the progress bar, flash toasts, the dirty-state '
            .'guard and back-button behaviour. `axios.post()` plus a manual reload bypasses all four.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->usesInertia();
    }

    public function check(AddonContext $addon): array
    {
        $files = array_merge($addon->vueFiles(), $addon->jsFiles());
        $findings = [];

        foreach ($addon->grep('/axios\.(post|put|patch|delete)\(/', $files) as $hit) {
            $findings[] = $this->fail(
                'Mutation outside Inertia: '.trim($hit['text']),
                $hit['file'],
                $hit['line'],
                "Use router.post(url, data) from '@statamic/cms/inertia'."
            );
        }

        return $findings;
    }
}

final class DirtyStateRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.dirty-state';
    }

    public function title(): string
    {
        return 'Leave unsaved-changes handling to `Statamic.$dirty`';
    }

    public function category(): string
    {
        return 'ui';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'ui-vocabulary §9.18: core already owns `beforeunload`, the Inertia `before` hook and '
            .'`popstate`. A second handler produces two stacked confirmation prompts.';
    }

    public function check(AddonContext $addon): array
    {
        $files = array_merge($addon->vueFiles(), $addon->jsFiles());
        $findings = [];

        foreach ($addon->grep('/onbeforeunload|addEventListener\(\s*[\'"]beforeunload/', $files) as $hit) {
            $findings[] = $this->fail(
                'Own beforeunload handler: '.trim($hit['text']),
                $hit['file'],
                $hit['line'],
                'Register the form with Statamic.$dirty instead.'
            );
        }

        return $findings;
    }
}

final class HandRolledOverlayRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.hand-rolled-overlay';
    }

    public function title(): string
    {
        return 'Use core modals and stacks rather than a bespoke fixed overlay';
    }

    public function category(): string
    {
        return 'ui';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'ui-vocabulary §9.6: core overlays participate in the portal stack, the esc-key binding '
            .'stack and FocusScope trapping. A hand-built overlay steals esc from its parent, breaks focus '
            .'return and z-fights with core.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->cpViews() !== [] || $addon->vueFiles() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $files = array_merge($addon->cpViews(), $addon->vueFiles());
        $findings = [];

        foreach ($addon->grep('/class\s*=\s*["\'][^"\']*\bfixed\b[^"\']*\binset-0\b/', $files) as $hit) {
            $findings[] = $this->fail(
                'Hand-rolled full-screen overlay.',
                $hit['file'],
                $hit['line'],
                'Use <ui-modal>, or push a stack via Statamic.$stacks.'
            );
        }

        foreach ($addon->grep('/z-\[?\d{3,}/', $files) as $hit) {
            $findings[] = $this->failWith(
                Severity::MINOR,
                'Very high z-index — a sign of fighting core\'s portal stack: '.trim($hit['text']),
                $hit['file'],
                $hit['line']
            );
        }

        return $findings;
    }
}

final class ListingComponentRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.listing-component';
    }

    public function title(): string
    {
        return 'Render tabular CP data with core\'s `<Listing>`';
    }

    public function category(): string
    {
        return 'ui';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'ui-vocabulary §9.12: a hand-built table loses search, filters, presets, column '
            .'customisation, bulk actions, keyboard shortcuts, sticky headers, drag reordering and '
            .'pagination — and is visibly different from the Entries screen users already know.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->cpViews() !== [] || $addon->inertiaPages() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $files = array_merge($addon->cpViews(), $addon->inertiaPages());
        $findings = [];

        foreach ($files as $file) {
            $contents = $addon->read($file) ?? '';

            if (preg_match('/<(table|thead)[\s>]/i', $contents) !== 1) {
                continue;
            }

            if (preg_match('/<(ui-)?[Ll]isting[\s>]/', $contents) === 1) {
                continue;
            }

            $line = null;

            foreach (explode("\n", $contents) as $index => $text) {
                if (preg_match('/<table[\s>]/i', $text) === 1) {
                    $line = $index + 1;
                    break;
                }
            }

            $findings[] = $this->fail(
                'Hand-built table in a CP screen.',
                $file,
                $line,
                'Use <Listing> with a controller that returns data + meta.columns; see ui-vocabulary §3.'
            );
        }

        return $findings;
    }
}

final class CommandPaletteRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.command-palette';
    }

    public function title(): string
    {
        return 'Expose page-level actions to the command palette';
    }

    public function category(): string
    {
        return 'ui';
    }

    public function severity(): string
    {
        return Severity::MINOR;
    }

    public function rationale(): string
    {
        return 'ui-vocabulary §9.14: every core primary action is wrapped in `<CommandPaletteItem>`. '
            .'An addon screen whose actions are missing from the palette feels inert next to core.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->inertiaPages() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $pages = $addon->inertiaPages();

        if ($addon->contains('/CommandPaletteItem|command-palette-item/', $pages)) {
            return [];
        }

        return [$this->fail(
            sprintf('None of the %d Inertia pages register a command-palette item.', count($pages)),
            $pages[0] ?? null,
            null,
            'Wrap the primary action in <CommandPaletteItem>.'
        )];
    }
}

/**
 * Tag-level scanning, shared by the §9.1 rules below.
 *
 * A Vue tag routinely spans six lines, so a line-based match reports every
 * multi-line component as missing the prop that sits two lines down. These
 * helpers read whole tags and report the line the tag *opens* on.
 */
trait ScansVueTags
{
    /**
     * Every opening `<Tag …>` in the source.
     *
     * The attribute pattern steps over quoted values, so a `>` inside one does
     * not end the tag early.
     *
     * @return array<int, array{attrs: string, line: int, offset: int}>
     */
    protected function tags(string $contents, string $tag): array
    {
        $pattern = '/<'.preg_quote($tag, '/').'\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)\/?>/s';

        if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) < 1) {
            return [];
        }

        return array_map(fn (array $match) => [
            'attrs' => $match[1][0],
            'line' => $this->lineAt($contents, $match[0][1]),
            'offset' => $match[0][1],
        ], $matches);
    }

    protected function lineAt(string $contents, int $offset): int
    {
        return $offset <= 0 ? 1 : substr_count($contents, "\n", 0, $offset) + 1;
    }

    /**
     * @param  Finding[]  $findings
     * @return Finding[]
     */
    protected function capped(array $findings, int $max, string $noun): array
    {
        if (count($findings) <= $max) {
            return $findings;
        }

        $kept = array_slice($findings, 0, $max);
        $kept[] = $this->failWith(Severity::INFO, sprintf('%d further %s suppressed.', count($findings) - $max, $noun));

        return $kept;
    }
}

final class IconNameExistsRule extends AbstractRule
{
    use ScansVueTags;

    public function id(): string
    {
        return 'ui.icon-name-exists';
    }

    public function title(): string
    {
        return 'Pass only icon names that exist in the set';
    }

    public function category(): string
    {
        return 'ui';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'ui-vocabulary §9.1: `Icon` renders nothing for a name it does not know — an empty box the '
            .'width of an icon, no console warning, no failing test. `user`, `add`, `check`, `list`, '
            .'`chart-pie` and `refresh` all read as plausible and none of them are in the 548-name set '
            .'(`users`, `plus`, `checkmark`, `layout-list`, `charts-donut-graph`, `sync` are).';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        // An addon that registers its own set names icons this linter cannot see,
        // so every finding here would be a guess.
        return $addon->vueFiles() !== []
            && ! $addon->contains('/Icon::register\(/')
            && $this->iconSet($addon) !== null;
    }

    public function check(AddonContext $addon): array
    {
        $icons = $this->iconSet($addon);
        $findings = [];

        foreach ($addon->vueFiles() as $file) {
            $contents = $addon->read($file) ?? '';

            foreach ($this->names($contents) as [$name, $line]) {
                if (is_file($icons.'/'.$name.'.svg')) {
                    continue;
                }

                $findings[] = $this->fail(
                    sprintf('Icon "%s" is not in the set — it renders as an empty box.', $name),
                    $file,
                    $line,
                    'Look the real name up in vendor/statamic/cms/resources/svg/icons/*.svg.'
                );
            }
        }

        return $this->capped($findings, 20, 'unknown icon names');
    }

    /**
     * The icon names a file asks for, in all three spellings.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    private function names(string $contents): array
    {
        $found = [];

        // 1. `icon="…"` as a prop on any component.
        if (preg_match_all('/(?<![-:\w])icon="([a-z][a-z0-9-]*)"/', $contents, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
            foreach ($matches as $hit) {
                $found[] = [$hit[1][0], $this->lineAt($contents, $hit[0][1])];
            }
        }

        // 2. `name="…"` on a standalone <Icon> — the spelling people forget. A
        //    check that reads only the first misses every icon rendered on its
        //    own, which is how `chart-pie` survived a whole pass over one addon.
        foreach ($this->tags($contents, 'Icon') as $tag) {
            if (preg_match('/(?<![-:\w])name="([a-z][a-z0-9-]*)"/', $tag['attrs'], $matches) === 1) {
                $found[] = [$matches[1], $tag['line']];
            }
        }

        // 3. A bound expression that still names icons literally:
        //    `:icon="ok ? 'checkmark' : 'x'"`. A ternary hid a non-existent
        //    `check` in one addon, so the confirm button lost its icon at exactly
        //    the moment it confirmed. Strip the comparison side first — in
        //    `:icon="sort === 'asc' ? 'arrow-up' : 'arrow-down'"` the string
        //    `asc` is what is being tested, not an icon anybody renders.
        foreach (explode("\n", $contents) as $index => $text) {
            if (preg_match_all('/:(?:icon|name)="([^"]*)"/', $text, $matches) < 1) {
                continue;
            }

            foreach ($matches[1] as $expression) {
                $expression = preg_replace('/[!=]==? *\'[^\']*\'/', '', $expression) ?? '';

                if (preg_match_all('/\'([a-z][a-z0-9-]{2,})\'/', $expression, $literals) > 0) {
                    foreach ($literals[1] as $name) {
                        $found[] = [$name, $index + 1];
                    }
                }
            }
        }

        return $found;
    }

    /** The 548 real names, from the addon's own vendor dir or the studio playground. */
    private function iconSet(AddonContext $addon): ?string
    {
        $candidates = [
            $addon->abs('vendor/statamic/cms/resources/svg/icons'),
            dirname(__DIR__, 3).'/playground/vendor/statamic/cms/resources/svg/icons',
        ];

        $configured = getenv('STATAMIC_ICON_SET');

        if (is_string($configured) && $configured !== '') {
            array_unshift($candidates, $configured);
        }

        foreach ($candidates as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }

        return null;
    }
}

final class ListingSlotRule extends AbstractRule
{
    use ScansVueTags;

    /** What `Listing` does NOT have. Its real set is initializing, default, prepended-row-actions, cell-{name}, tbody-start. */
    private const DEAD = ['actions', 'empty', 'footer', 'header'];

    public function id(): string
    {
        return 'ui.listing-slots';
    }

    public function title(): string
    {
        return 'Use only the slots `Listing` actually has';
    }

    public function category(): string
    {
        return 'ui';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'ui-vocabulary §9.1: Vue drops an unknown slot without a word, so the action it held never '
            .'renders. In one addon the entire "edit" path was unreachable for months. Listing has '
            .'`initializing`, `default`, `prepended-row-actions`, plus `cell-{name}` and `tbody-start`.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->vueFiles() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        foreach ($addon->vueFiles() as $file) {
            $contents = $addon->read($file) ?? '';

            if (! str_contains($contents, '<Listing')) {
                continue;
            }

            // Scoped to the Listing block on purpose: `#actions` is correct on
            // `Header`, `#footer` is correct on `Modal` and `Stack`. Only inside
            // <Listing> are they dead.
            if (preg_match_all('/<Listing\b.*?<\/Listing>/s', $contents, $blocks, PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }

            $pattern = '/#('.implode('|', self::DEAD).')[=>]/';

            foreach ($blocks[0] as [$block, $blockOffset]) {
                if (preg_match_all($pattern, $block, $slots, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) < 1) {
                    continue;
                }

                foreach ($slots as $slot) {
                    $findings[] = $this->fail(
                        sprintf('Slot #%s does not exist on <Listing> — Vue drops it silently.', $slot[1][0]),
                        $file,
                        $this->lineAt($contents, $blockOffset + $slot[0][1]),
                        'Use #prepended-row-actions, #cell-{name} or #tbody-start.'
                    );
                }
            }
        }

        return $findings;
    }
}

final class UnknownPropRule extends AbstractRule
{
    use ScansVueTags;

    /**
     * tag => [pattern over the tag's attributes, what is wrong].
     *
     * Verified against vendor/statamic/cms/resources/dist-package/types/components/ui/*.d.ts.
     * Do not infer a prop from its name — look it up there.
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private const BAD = [
        // TabTrigger takes `text` + `name`. Getting this wrong renders an empty
        // tab strip, which makes every tab behind it unreachable.
        ['TabTrigger', '/:?(?:label|has-error)=/', 'takes text/name, not label/has-error'],
        // Alert knows default|warning|error|success. `danger` and `info` both
        // fall through to the neutral style, so a failure and a hint look like
        // nothing. The lookbehind is load-bearing: without it `variant="` also
        // matches inside `:variant="`, and the lookahead then reads the
        // expression instead of a literal — seven false alarms in one addon.
        ['Alert', '/(?<![-:\w])variant="(?!default|warning|error|success)/', 'unknown variant'],
        // And the bound form, where the literals sit inside the expression.
        ['Alert', '/:variant="[^"]*\'(?!default\'|warning\'|error\'|success\')[a-z]+\'/', 'unknown variant (bound)'],
        // DropdownItem takes variant="destructive"; a bare `danger` colours nothing.
        ['DropdownItem', '/(?<![-\w])danger(?![-\w=])/', 'takes variant="destructive", not a bare danger'],
        // Panel takes heading/subheading/icon only.
        ['Panel', '/(?<![-\w])collapsible(?![-\w])/', 'has no collapsible prop'],
    ];

    public function id(): string
    {
        return 'ui.unknown-props';
    }

    public function title(): string
    {
        return 'Pass only props the component declares';
    }

    public function category(): string
    {
        return 'ui';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'ui-vocabulary §9.1: Vue passes an unknown prop through as a plain HTML attribute, so it '
            .'lands in the DOM and has no effect — no warning anywhere. The worst of the family, because '
            .'the damage scales with what the prop was for.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->vueFiles() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        foreach ($addon->vueFiles() as $file) {
            $contents = $addon->read($file) ?? '';

            foreach (self::BAD as [$tag, $pattern, $what]) {
                foreach ($this->tags($contents, $tag) as $hit) {
                    if (preg_match($pattern, $hit['attrs']) !== 1) {
                        continue;
                    }

                    $findings[] = $this->fail(
                        sprintf('<%s> %s.', $tag, $what),
                        $file,
                        $hit['line'],
                        'Check dist-package/types/components/ui/'.$tag.'.vue.d.ts for the real props.'
                    );
                }
            }

            // CommandPaletteItem runs `action` or `url`; an @click on it is never
            // called, and core logs a console warning nobody reads.
            foreach ($this->tags($contents, 'CommandPaletteItem') as $hit) {
                if (! str_contains($hit['attrs'], '@click') || preg_match('/:?(?:action|url)=/', $hit['attrs']) === 1) {
                    continue;
                }

                $findings[] = $this->fail(
                    '<CommandPaletteItem> has @click but no action/url — the palette entry does nothing.',
                    $file,
                    $hit['line'],
                    'Pass :action="() => …" or :url="…".'
                );
            }
        }

        return $findings;
    }
}

final class EmptyStringPickerRule extends AbstractRule
{
    private const BINDING = "modelValue ? String(props.modelValue) : ''";

    public function id(): string
    {
        return 'ui.picker-empty-model';
    }

    public function title(): string
    {
        return 'Bind an empty picker to `null`, not `\'\'`';
    }

    public function category(): string
    {
        return 'ui';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'ui-vocabulary §9.1: an empty string counts as a selection, so the trigger renders '
            .'`getOptionLabel(selectedOption)` — which is empty — instead of the placeholder branch. '
            .'The field looks blank, with a clear button offering to clear nothing.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->vueFiles() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        foreach ($addon->vueFiles() as $file) {
            $contents = $addon->read($file) ?? '';

            if (! str_contains($contents, self::BINDING)) {
                continue;
            }

            // Unless the option list actually carries a `value: ''` entry — then
            // the empty string IS a choice with a label ("No opportunity") and
            // binding it is correct.
            if (str_contains($contents, "value: ''")) {
                continue;
            }

            foreach (explode("\n", $contents) as $index => $text) {
                if (str_contains($text, self::BINDING)) {
                    $findings[] = $this->fail(
                        'Picker bound to \'\' instead of null — it renders blank, not the placeholder.',
                        $file,
                        $index + 1,
                        'Bind null: props.modelValue ? String(props.modelValue) : null.'
                    );
                }
            }
        }

        return $findings;
    }
}

final class CheckboxSoloRule extends AbstractRule
{
    use ScansVueTags;

    /** How far back a `#cell-` may sit and still be this checkbox's cell. */
    private const CELL_REACH = 900;

    public function id(): string
    {
        return 'ui.checkbox-solo';
    }

    public function title(): string
    {
        return 'Give a `Checkbox` in a table cell the `solo` prop';
    }

    public function category(): string
    {
        return 'ui';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'ui-vocabulary §9.1: without `solo` the checkbox prints the literal text `false` where the '
            .'label goes. `solo` is documented as exactly this case: "hides the label and description … '
            .'like in a table cell".';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->vueFiles() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        foreach ($addon->vueFiles() as $file) {
            $contents = $addon->read($file) ?? '';

            foreach ($this->tags($contents, 'Checkbox') as $hit) {
                if (preg_match('/\bsolo\b/', $hit['attrs']) === 1 || preg_match('/:?label=/', $hit['attrs']) === 1) {
                    continue;
                }

                // Only inside a listing cell; elsewhere a label is correct.
                $start = max(0, $hit['offset'] - self::CELL_REACH);

                if (! str_contains(substr($contents, $start, $hit['offset'] - $start), '#cell-')) {
                    continue;
                }

                $findings[] = $this->fail(
                    'Checkbox in a table cell without `solo` — it prints "false" where the label goes.',
                    $file,
                    $hit['line'],
                    'Add the solo prop.'
                );
            }
        }

        return $findings;
    }
}

final class BadgePillRule extends AbstractRule
{
    use ScansVueTags;

    public function id(): string
    {
        return 'ui.badge-pill';
    }

    public function title(): string
    {
        return 'Render a status badge as `pill` with no `size`';
    }

    public function category(): string
    {
        return 'ui';
    }

    public function severity(): string
    {
        // A square chip (a tag, a count) is legitimate, so this asks rather than
        // asserts. Read the line before changing it.
        return Severity::INFO;
    }

    public function rationale(): string
    {
        return 'ui-vocabulary §9.22: `size="sm"` adds `rounded-[0.1875rem]`, a 3px radius where every '
            .'button is `rounded-lg`, and `color="default"` is the same chip as `Button variant="default"` '
            .'minus the gradient — together they read as a broken button. A status is `StatusIndicator`, '
            .'or a `Badge` with `pill`, a semantic colour and no `size`.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->vueFiles() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        foreach ($addon->vueFiles() as $file) {
            $contents = $addon->read($file) ?? '';

            foreach ($this->tags($contents, 'Badge') as $hit) {
                if (! str_contains($hit['attrs'], 'size="sm"') || preg_match('/\bpill\b/', $hit['attrs']) === 1) {
                    continue;
                }

                $findings[] = $this->fail(
                    'Badge size="sm" without pill — is this a status?',
                    $file,
                    $hit['line'],
                    'A status wants pill + a semantic colour and no size. A tag or count chip is fine as is.'
                );
            }
        }

        return $findings;
    }
}

final class DangerButtonRule extends AbstractRule
{
    use ScansVueTags;

    public function id(): string
    {
        return 'ui.danger-button';
    }

    public function title(): string
    {
        return 'Keep `variant="danger"` inside the confirmation modal';
    }

    public function category(): string
    {
        return 'ui';
    }

    public function severity(): string
    {
        return Severity::MINOR;
    }

    public function rationale(): string
    {
        return 'ui-vocabulary §9.24: core uses `danger` in exactly one place — the confirm button inside a '
            .'modal (`ConfirmationModal`). A destructive page action is a `DropdownItem '
            .'variant="destructive"` inside the header\'s `…` menu.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->vueFiles() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        foreach ($addon->vueFiles() as $file) {
            $contents = $addon->read($file) ?? '';

            // Only <Button>: <Text variant="danger"> is the correct way to colour
            // an error message and is not a finding. Both spellings of the value,
            // because the bound form is how a red row button survived the first
            // version of this rule.
            foreach ($this->tags($contents, 'Button') as $hit) {
                $literal = str_contains($hit['attrs'], 'variant="danger"');
                $bound = preg_match('/:variant="[^"]*\'danger\'/', $hit['attrs']) === 1;

                if (! $literal && ! $bound) {
                    continue;
                }

                $findings[] = $this->fail(
                    'Button variant="danger" outside a confirmation modal.',
                    $file,
                    $hit['line'],
                    'Move it into the header\'s … menu as <DropdownItem variant="destructive">.'
                );
            }
        }

        return $findings;
    }
}

final class DropdownTriggerRule extends AbstractRule
{
    /** How far below the `#trigger` line the dots button may sit. */
    private const LOOKAHEAD = 4;

    public function id(): string
    {
        return 'ui.dropdown-trigger';
    }

    public function title(): string
    {
        return 'Let `Dropdown` render its own dots trigger';
    }

    public function category(): string
    {
        return 'ui';
    }

    public function severity(): string
    {
        return Severity::MINOR;
    }

    public function rationale(): string
    {
        return 'ui-vocabulary §9.24: `Dropdown` already defaults to `Button icon="dots" variant="ghost" '
            .'size="sm"`, so passing a `#trigger` that rebuilds exactly that duplicates core and drifts '
            .'from it on the next release.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->vueFiles() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        foreach ($addon->vueFiles() as $file) {
            $lines = explode("\n", $addon->read($file) ?? '');

            foreach ($lines as $index => $text) {
                if (! str_contains($text, '<template #trigger>')) {
                    continue;
                }

                $window = implode("\n", array_slice($lines, $index + 1, self::LOOKAHEAD));

                if (! str_contains($window, 'icon="dots"')) {
                    continue;
                }

                $findings[] = $this->fail(
                    'Hand-built dots trigger on a Dropdown.',
                    $file,
                    $index + 1,
                    'Drop the #trigger slot — Dropdown renders it.'
                );
            }
        }

        return $findings;
    }
}

final class PanelBodyRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.panel-body';
    }

    public function title(): string
    {
        return 'Put a `Card` between a `Panel` and its content';
    }

    public function category(): string
    {
        return 'ui';
    }

    public function severity(): string
    {
        return Severity::MINOR;
    }

    public function rationale(): string
    {
        return 'ui-vocabulary §9.19: `Panel` is the grey frame; the padding content needs lives on `Card` '
            .'(`px-4.5 py-5 space-y-2`). Every core publish section is `Panel > PanelHeader > Card > '
            .'Fields`, and `CardPanel` is the shorthand. The single exception is a table, which `Listing` '
            .'drops straight into the `Panel`.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->vueFiles() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        foreach ($addon->vueFiles() as $file) {
            $lines = explode("\n", $addon->read($file) ?? '');

            foreach ($lines as $index => $text) {
                if (preg_match('/<Panel\b/', $text) !== 1) {
                    continue;
                }

                $next = $lines[$index + 1] ?? '';

                if (preg_match('/^\s*<div class="p[xy]?-[0-9]/', $next) !== 1) {
                    continue;
                }

                $findings[] = $this->fail(
                    'Padded div straight on a Panel — content is sitting on the grey.',
                    $file,
                    $index + 2,
                    'Wrap the body in <Card>, or use <CardPanel>.'
                );
            }
        }

        return $findings;
    }
}
