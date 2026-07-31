<?php

declare(strict_types=1);

namespace StatamicAddonStudio\Lint\Rules;

use StatamicAddonStudio\Lint\AbstractRule;
use StatamicAddonStudio\Lint\AddonContext;
use StatamicAddonStudio\Lint\Finding;
use StatamicAddonStudio\Lint\Severity;

/**
 * Control-Panel UI and frontend build.
 *
 * Every rule here traces back to `standards/ui-vocabulary.md` and was verified against
 * the reference addons in `reference/` on Statamic 6.26.
 */
final class ViteConfigRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.vite-config';
    }

    public function title(): string
    {
        return 'Build CP assets with Vite and the official `@statamic/cms/vite-plugin`';
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
        return 'The Statamic Vite plugin rewrites `import ... from "vue"` to the CP\'s own Vue instance. '
            .'Without it the addon bundles a second Vue and every component silently fails to mount.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->vueFiles() !== [] || $addon->match('resources/js/*') !== [] || $addon->match('resources/css/*') !== [];
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        if ($addon->has('webpack.mix.js')) {
            $findings[] = $this->fail(
                'webpack.mix.js is present — Laravel Mix is the Statamic 5 era build.',
                'webpack.mix.js',
                null,
                'Migrate to vite.config.js; all v6 reference addons use Vite.'
            );
        }

        if (! $addon->has('vite.config.js') && ! $addon->has('vite.config.mjs') && ! $addon->has('vite.config.ts')) {
            $findings[] = $this->fail(
                'The addon ships CP assets but has no vite.config.js.'
            );

            return $findings;
        }

        $config = $addon->read('vite.config.js')
            ?? $addon->read('vite.config.mjs')
            ?? $addon->read('vite.config.ts')
            ?? '';

        $file = $addon->has('vite.config.js') ? 'vite.config.js' : ($addon->has('vite.config.mjs') ? 'vite.config.mjs' : 'vite.config.ts');

        if (! str_contains($config, '@statamic/cms/vite-plugin')) {
            $findings[] = $this->fail(
                'vite.config does not use `@statamic/cms/vite-plugin`.',
                $file,
                null,
                "import statamic from '@statamic/cms/vite-plugin'; and add statamic() to plugins."
            );
        }

        if (preg_match('/external\s*:/', $config) === 1) {
            $findings[] = $this->failWith(
                Severity::MAJOR,
                'vite.config declares its own `external` — the Statamic plugin already handles Vue and CP externals.',
                $file
            );
        }

        if (! str_contains($config, 'laravel-vite-plugin')) {
            $findings[] = $this->failWith(
                Severity::MAJOR,
                'vite.config does not use `laravel-vite-plugin`; the addon cannot publish a manifest Statamic can read.',
                $file
            );
        }

        return $findings;
    }
}

final class StatamicCmsPackageRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.statamic-cms-package';
    }

    public function title(): string
    {
        return 'Depend on `@statamic/cms` from the installed vendor directory';
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
        return 'CP UI primitives are imported from `@statamic/cms/ui`. Every reference addon wires the '
            .'package to `file:./vendor/statamic/cms/resources/dist-package`, which pins the UI kit to the '
            .'exact Statamic version the addon is developed against.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->has('package.json');
    }

    public function check(AddonContext $addon): array
    {
        $raw = $addon->read('package.json') ?? '{}';
        $json = json_decode($raw, true) ?: [];
        $deps = array_merge((array) ($json['dependencies'] ?? []), (array) ($json['devDependencies'] ?? []));

        $statamic = $deps['@statamic/cms'] ?? null;

        if ($statamic === null) {
            return [$this->fail(
                'package.json does not depend on `@statamic/cms`.',
                'package.json',
                null,
                'Add "@statamic/cms": "file:./vendor/statamic/cms/resources/dist-package" to devDependencies.'
            )];
        }

        if (! str_starts_with((string) $statamic, 'file:')) {
            return [$this->failWith(
                Severity::MINOR,
                sprintf('`@statamic/cms` resolves to `%s` rather than the installed vendor package.', (string) $statamic),
                'package.json'
            )];
        }

        return [];
    }
}

final class ViteProviderPropertyRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.vite-property';
    }

    public function title(): string
    {
        return 'Register CP assets through the provider\'s `$vite` property, not `$scripts`/`$stylesheets`';
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
        return '`$scripts` and `$stylesheets` publish pre-built files and skip the Vite manifest, so hot '
            .'reload is dead and cache busting is manual. The v6 reference addons all use `$vite`.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->serviceProviders() !== []
            && ($addon->has('vite.config.js') || $addon->has('vite.config.mjs') || $addon->has('vite.config.ts'));
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        foreach ($addon->serviceProviders() as $provider) {
            $contents = $addon->read($provider) ?? '';

            // Two legal registrations: the $vite property on an AddonServiceProvider, or a
            // Statamic::vite() call — which is what a package that must also boot without
            // Statamic has to use, since it cannot extend AddonServiceProvider at all.
            $hasVite = preg_match('/\$vite\s*=/', $contents) === 1
                || preg_match('/Statamic::vite\(|Statamic\\\\Statamic::vite\(/', $contents) === 1;
            $legacy = $addon->grep('/protected\s+\$(scripts|stylesheets)\s*=/', [$provider]);

            foreach ($legacy as $hit) {
                $findings[] = $this->fail(
                    sprintf('`$%s` is a pre-Vite registration path.', $hit['match'][1]),
                    $provider,
                    $hit['line'],
                    'Replace with protected array $vite = [\'hotFile\' => ..., \'publicDirectory\' => ..., \'input\' => [...]].'
                );
            }

            if (! $hasVite && $legacy === []) {
                $findings[] = $this->fail(
                    'vite.config exists but the provider never registers assets (no `$vite`).',
                    $provider,
                    null,
                    'Without registration the built bundle is never loaded by the CP.'
                );
            }
        }

        return $findings;
    }
}

final class ViteHotFileIgnoredRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.hot-file-ignored';
    }

    public function title(): string
    {
        return 'Git-ignore the Vite hot file and commit the built bundle';
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
        return 'Consumers install the addon with Composer and have no Node toolchain. A committed hot file '
            .'points their CP at a dev server that does not exist, so the CP loads no addon assets at all.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->has('vite.config.js') || $addon->has('vite.config.mjs') || $addon->has('vite.config.ts');
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        foreach ($addon->files() as $file) {
            if (preg_match('#(^|/)(hot|vite\.hot)$#', $file) === 1) {
                $findings[] = $this->fail(
                    'The Vite hot file is committed; installed sites will look for a dev server.',
                    $file,
                    null,
                    'Add it to .gitignore and delete it from the index.'
                );
            }
        }

        $manifest = array_filter($addon->files(), fn (string $f) => str_ends_with($f, 'manifest.json'));

        if (! $addon->shipsBuiltAssets()) {
            $findings[] = $this->fail(
                'Built assets never reach the consumer.',
                null,
                null,
                'Consumers have no Node build step. Either commit dist/build plus its manifest, or fetch it '
                .'from the GitHub release via extra.download-dist + pixelfear/composer-dist-plugin as Runway does.'
            );
        } elseif ($addon->composerValue('extra.download-dist') !== null) {
            // The dist plugin downloads the release artefact; nothing to check in the repository.
            return $findings;
        } elseif ($manifest === []) {
            $findings[] = $this->failWith(
                Severity::MINOR,
                'Built assets are committed but no manifest.json is present.',
                null,
                null,
                'Statamic resolves $vite inputs through the Vite manifest.'
            );
        }

        return $findings;
    }
}

final class TailwindTokensRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.tailwind-tokens';
    }

    public function title(): string
    {
        return 'Import the Statamic Tailwind entry instead of building an independent theme';
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
        return 'Statamic 6 ships Tailwind 4 with its own token layer. An addon that starts from a bare '
            .'`@import "tailwindcss"` gets different greys, radii and dark-mode behaviour than every core screen.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->cssFiles() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $entries = array_filter(
            $addon->cssFiles(),
            fn (string $f) => str_starts_with($f, 'resources/css/') || str_starts_with($f, 'resources/')
        );

        if ($entries === []) {
            return [];
        }

        $importsStatamic = $addon->contains('#@import\s+["\']@statamic/cms#', $entries);

        if ($importsStatamic) {
            return [];
        }

        $importsBareTailwind = $addon->grep('#@import\s+["\']tailwindcss["\']#', $entries);

        if ($importsBareTailwind !== []) {
            $hit = $importsBareTailwind[0];

            return [$this->fail(
                'CSS entry imports bare Tailwind instead of the Statamic token layer.',
                $hit['file'],
                $hit['line'],
                'Start the entry with @import "@statamic/cms/tailwind.css"; then @source your own files.'
            )];
        }

        if ($addon->has('tailwind.config.js') || $addon->has('tailwind.config.cjs')) {
            return [$this->failWith(
                Severity::MINOR,
                'A Tailwind 3 style config file is present; v6 configures Tailwind 4 from CSS.',
                $addon->has('tailwind.config.js') ? 'tailwind.config.js' : 'tailwind.config.cjs'
            )];
        }

        return [];
    }
}

final class DarkModeRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.dark-mode';
    }

    public function title(): string
    {
        return 'Pair every hard-coded colour utility with a dark-mode counterpart';
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
        return 'The v6 CP has a first-class dark theme. A panel that stays white in dark mode is the single '
            .'most obvious tell that a screen was not built by Statamic.';
    }

    /**
     * Themed utilities that still need an explicit dark counterpart.
     *
     * Stock, non-themeable colours (bg-white, bg-blue-600, hex literals) are ui.themeable-colors'
     * job — listing them here too would report the same line twice.
     */
    private const RISKY = [
        'bg-gray-50', 'bg-gray-100', 'bg-gray-200',
        'text-gray-900', 'text-gray-800',
        'border-gray-200', 'border-gray-300',
    ];

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->vueFiles() !== [] || $addon->bladeFiles() !== [] || $addon->cssFiles() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $files = array_merge($addon->vueFiles(), $addon->bladeFiles(), $addon->cssFiles(), $addon->antlersFiles());
        $findings = [];

        foreach ($files as $file) {
            $contents = $addon->read($file);

            if ($contents === null) {
                continue;
            }

            foreach (explode("\n", $contents) as $index => $line) {
                foreach (self::RISKY as $class) {
                    if (preg_match('/(?<![\w:-])'.preg_quote($class, '/').'(?![\w-])/', $line) !== 1) {
                        continue;
                    }

                    if (str_contains($line, 'dark:')) {
                        continue;
                    }

                    $findings[] = $this->fail(
                        sprintf('`%s` used without a `dark:` counterpart.', $class),
                        $file,
                        $index + 1,
                        'Prefer a core UI component, or add the dark: variant.'
                    );
                }
            }
        }

        return $this->cap($findings, 25);
    }

    /** @param Finding[] $findings @return Finding[] */
    private function cap(array $findings, int $max): array
    {
        if (count($findings) <= $max) {
            return $findings;
        }

        $kept = array_slice($findings, 0, $max);
        $kept[] = $this->failWith(
            Severity::INFO,
            sprintf('%d further unpaired colour utilities suppressed.', count($findings) - $max)
        );

        return $kept;
    }
}

final class NativeComponentsRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.native-components';
    }

    public function title(): string
    {
        return 'Compose CP screens from Statamic UI components instead of hand-rolled markup';
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
        return 'Core ships buttons, panels, tables, modals and headers as `<ui-*>` blade components and '
            .'`@statamic/cms/ui` Vue primitives. Re-implementing them guarantees drift on the next core release.';
    }

    /** Raw elements that almost always should be a core component instead. */
    private const RAW = [
        '/<button(?![\w-])/i' => 'ui-button (Blade) or Button from @statamic/cms/ui (Vue)',
        '/<table(?![\w-])/i' => 'ui-table / the core listing components',
        '/<select(?![\w-])/i' => 'ui-select or Select',
        '/<input(?![\w-])/i' => 'ui-input or Input',
        '/class="[^"]*\bmodal\b/i' => 'ui-modal / Stack',
    ];

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->cpViews() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        foreach ($addon->cpViews() as $file) {
            $contents = $addon->read($file);

            if ($contents === null) {
                continue;
            }

            foreach (explode("\n", $contents) as $index => $line) {
                foreach (self::RAW as $pattern => $replacement) {
                    if (preg_match($pattern, $line) === 1) {
                        $findings[] = $this->fail(
                            sprintf('Raw markup in a CP view; use %s.', $replacement),
                            $file,
                            $index + 1
                        );
                    }
                }
            }
        }

        if ($findings === [] && ! $addon->contains('/<ui-[a-z-]+/', $addon->cpViews())
            && ! $addon->contains('#@statamic/cms/ui#', $addon->vueFiles())) {
            $findings[] = $this->failWith(
                Severity::MINOR,
                'No Statamic UI component is used anywhere in the CP surface.',
                null,
                null,
                'Check the screens against standards/ui-vocabulary.md.'
            );
        }

        return $findings;
    }
}

final class FieldtypeContractRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.fieldtype-contract';
    }

    public function title(): string
    {
        return 'Implement the full Vue fieldtype contract: props, emits, `Fieldtype.use`, `defineExpose`';
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
        return 'ui-vocabulary §5: in a `<script setup>` fieldtype, omitting `defineExpose(expose)` '
            .'silently kills field actions and replicator previews. Missing `readOnly` handling makes the '
            .'field editable on a revision where it must not be.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->fieldtypeComponents() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        foreach ($addon->fieldtypeComponents() as $file) {
            $contents = $addon->read($file) ?? '';

            // Two legal shapes: `<script setup>` with the composable, or the Options API with
            // FieldtypeMixin. defineExpose only exists in the former.
            $isScriptSetup = preg_match('/<script[^>]*\bsetup\b/', $contents) === 1;
            $usesMixin = preg_match('/FieldtypeMixin|mixins\s*:\s*\[\s*Fieldtype/', $contents) === 1;
            $usesComposable = str_contains($contents, 'Fieldtype.use') || str_contains($contents, 'Fieldtype.props');

            if ($isScriptSetup && ! str_contains($contents, 'defineExpose')) {
                $findings[] = $this->fail(
                    'A `<script setup>` fieldtype that never calls `defineExpose(expose)`.',
                    $file,
                    null,
                    'Field actions and replicator previews depend on the exposed API.'
                );
            }

            if (! $usesComposable && ! $usesMixin) {
                $findings[] = $this->failWith(
                    Severity::MAJOR,
                    'Fieldtype component uses neither the `Fieldtype` composable nor `FieldtypeMixin`.',
                    $file,
                    null,
                    'script setup: defineProps(Fieldtype.props) + Fieldtype.use(emit, props). '
                    .'Options API: mixins: [FieldtypeMixin].'
                );
            }

            // Display-only fieldtypes (previews, alerts) legitimately have nothing to disable,
            // so this is a prompt to check rather than a definite defect.
            $hasEditableControl = preg_match('/<(input|textarea|select|ui-input|ui-select|ui-textarea|Input|Select|Textarea)\b/', $contents) === 1;

            if (! preg_match('/read-?only|readOnly|isReadOnly/i', $contents)) {
                $findings[] = $this->failWith(
                    $hasEditableControl ? Severity::MAJOR : Severity::MINOR,
                    $hasEditableControl
                        ? 'Fieldtype renders an editable control but never honours the read-only state.'
                        : 'Fieldtype does not reference the read-only state; confirm it is display-only.',
                    $file
                );
            }

            if (preg_match('/emit\(\s*[\'"]input[\'"]/', $contents) === 1) {
                $findings[] = $this->failWith(
                    Severity::MAJOR,
                    'Emitting `input` is the Statamic 5 fieldtype contract.',
                    $file,
                    null,
                    'Emit through update()/updateDebounced() instead.'
                );
            }
        }

        return $findings;
    }
}

final class DomPiercingRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.no-dom-piercing';
    }

    public function title(): string
    {
        return 'Never reach into DOM or CSS the addon does not own';
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
        return 'Traversing out of the component or styling core internals by `[data-ui-*]` couples the addon '
            .'to markup Statamic changes in patch releases. Use the documented extension points instead.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->vueFiles() !== [] || $addon->jsFiles() !== [] || $addon->cssFiles() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        $scriptPatterns = [
            '/\.closest\(/' => [Severity::MAJOR, 'traverses out of the component into markup core owns'],
            '/new MutationObserver/' => [Severity::MAJOR, 'observes DOM the addon does not own'],
            '/document\.querySelector(All)?\(\s*[\'"]\[data-ui/' => [Severity::MAJOR, 'selects core internals'],
            '/document\.(body|head)\.appendChild\(/' => [Severity::MINOR, 'mutates the document outside the component tree'],
        ];

        foreach ($scriptPatterns as $pattern => [$severity, $why]) {
            foreach ($addon->grep($pattern, array_merge($addon->vueFiles(), $addon->jsFiles())) as $hit) {
                $findings[] = $this->failWith(
                    $severity,
                    sprintf('%s (%s).', trim($hit['text']), $why),
                    $hit['file'],
                    $hit['line']
                );
            }
        }

        foreach ($addon->grep('/\[data-ui-[a-z-]+\]/', $addon->cssFiles()) as $hit) {
            $findings[] = $this->fail(
                'CSS targets a core `[data-ui-*]` hook.',
                $hit['file'],
                $hit['line'],
                'Style your own wrapper class instead; core data attributes are internal.'
            );
        }

        foreach ($addon->grep('/!important/', $addon->cssFiles()) as $hit) {
            $findings[] = $this->failWith(
                Severity::MINOR,
                '`!important` in addon CSS usually means it is fighting core styles.',
                $hit['file'],
                $hit['line']
            );
        }

        return $findings;
    }
}

final class CpRouteHelperRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.cp-route-helper';
    }

    public function title(): string
    {
        return 'Build CP links with `cp_route()`, never a hard-coded `/cp` prefix';
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
        return 'The CP prefix is configurable per site. Hard-coded `/cp/...` links 404 on every site that '
            .'renamed it, which is common for security reasons.';
    }

    public function check(AddonContext $addon): array
    {
        $files = array_merge($addon->phpFiles(), $addon->bladeFiles(), $addon->vueFiles(), $addon->antlersFiles());
        $findings = [];

        foreach ($addon->grep('#[\'"]/cp/[a-z0-9_-]+#i', $files) as $hit) {
            if (str_contains($hit['file'], 'tests/')) {
                continue;
            }

            $findings[] = $this->fail(
                'Hard-coded CP path: '.trim($hit['text']),
                $hit['file'],
                $hit['line'],
                'Use cp_route(\'name\', $params) in PHP/Blade or Statamic.$config.get(\'cpUrl\') in JS.'
            );
        }

        return $findings;
    }
}

final class TranslatedCpStringsRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.translated-strings';
    }

    public function title(): string
    {
        return 'Wrap user-facing CP strings in `__()`';
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
        return 'The CP is fully localised. Untranslated labels in an otherwise German or French CP are an '
            .'immediate tell, and the Marketplace review calls it out.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->contains('/configFieldItems|fieldtypeConfig/', $addon->phpFiles());
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        foreach ($addon->phpFiles() as $file) {
            $contents = $addon->read($file);

            if ($contents === null || ! str_contains($contents, 'configFieldItems')) {
                continue;
            }

            foreach (explode("\n", $contents) as $index => $line) {
                if (preg_match("/'(display|instructions)'\s*=>\s*'([^']{2,})'/", $line, $m) === 1) {
                    $findings[] = $this->fail(
                        sprintf('Untranslated `%s` label: "%s".', $m[1], $m[2]),
                        $file,
                        $index + 1,
                        "Wrap it: '".$m[1]."' => __('...')"
                    );
                }
            }
        }

        return $findings;
    }
}
