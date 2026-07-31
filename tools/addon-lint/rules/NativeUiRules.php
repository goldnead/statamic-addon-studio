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
                'Render an Inertia page from the controller instead; see standards/ui-standard.md.'
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
        return 'Constrain page content with `max-w-page`';
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
            .'sits at a different width than every core screen.';
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
            $findings[] = $this->fail(
                sprintf('Custom width container `%s`.', $hit['match'][1]),
                $hit['file'],
                $hit['line'],
                'Use max-w-page.'
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
