#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * A dependency-free smoke test for addon-lint.
 *
 * Builds throwaway addon fixtures in a temp directory and asserts that specific rules fire
 * (or stay silent) on them. Run it after touching src/ or rules/:
 *
 *   php tools/addon-lint/tests/smoke.php
 */

namespace StatamicAddonStudio\Lint;

$base = dirname(__DIR__);

foreach (['Severity', 'Finding', 'Rule', 'AbstractRule', 'AddonContext', 'Config', 'Report', 'Linter', 'Reporter', 'RuleRegistry'] as $class) {
    require_once $base.'/src/'.$class.'.php';
}

$linter = RuleRegistry::all($base.'/rules');

$passed = 0;
$failed = [];

/** Build a fixture addon from a path => contents map and return its lint report. */
function lint(Linter $linter, array $files): Report
{
    $root = sys_get_temp_dir().'/addon-lint-smoke-'.bin2hex(random_bytes(6));

    foreach ($files as $path => $contents) {
        $full = $root.'/'.$path;
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, $contents);
    }

    $addon = new AddonContext($root);
    $report = $linter->run($addon, Config::forAddon($addon));

    exec('rm -rf '.escapeshellarg($root));

    return $report;
}

function fires(Report $report, string $ruleId): bool
{
    foreach ($report->findings as $finding) {
        if ($finding->ruleId === $ruleId) {
            return true;
        }
    }

    return false;
}

function check(string $label, bool $condition): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;

        return;
    }

    $failed[] = $label;
}

$goodComposer = json_encode([
    'name' => 'acme/statamic-thing',
    'type' => 'statamic-addon',
    'license' => 'MIT',
    'require' => ['php' => '^8.2', 'statamic/cms' => '^6.0'],
    'autoload' => ['psr-4' => ['Acme\\Thing\\' => 'src']],
    'extra' => [
        'statamic' => ['name' => 'Thing', 'description' => 'A thing that does a thing for Statamic sites.'],
        'laravel' => ['providers' => ['Acme\\Thing\\ServiceProvider']],
    ],
], JSON_PRETTY_PRINT);

$provider = "<?php\nnamespace Acme\\Thing;\nclass ServiceProvider extends \\Statamic\\Providers\\AddonServiceProvider {}\n";

// --- structure -------------------------------------------------------------

$report = lint($linter, ['composer.json' => $goodComposer, 'src/ServiceProvider.php' => $provider]);
check('valid composer.json does not trip structure.statamic-metadata', ! fires($report, 'structure.statamic-metadata'));
check('valid composer.json does not trip structure.service-provider', ! fires($report, 'structure.service-provider'));
check('valid composer.json does not trip structure.composer-type', ! fires($report, 'structure.composer-type'));
check('valid composer.json does not trip structure.psr4-src', ! fires($report, 'structure.psr4-src'));

$report = lint($linter, ['composer.json' => '{"name":"acme/thing"}']);
check('missing extra.statamic is reported', fires($report, 'structure.statamic-metadata'));
check('missing provider is reported', fires($report, 'structure.service-provider'));

$report = lint($linter, [
    'composer.json' => json_encode([
        'name' => 'acme/thing',
        'require' => ['acme/sibling' => '^1.0'],
        'repositories' => [['type' => 'vcs', 'url' => 'https://github.com/acme/sibling']],
    ]),
]);
check('a repositories block on a library is a blocker', fires($report, 'release.resolvable-dependencies'));

// --- ui / build ------------------------------------------------------------

$viteGood = "import statamic from '@statamic/cms/vite-plugin';\nimport laravel from 'laravel-vite-plugin';\n";
$report = lint($linter, [
    'composer.json' => $goodComposer,
    'src/ServiceProvider.php' => $provider,
    'vite.config.js' => $viteGood,
    'resources/js/cp.js' => "console.info('hi');\n",
]);
check('a correct vite config is accepted', ! fires($report, 'ui.vite-config'));

$report = lint($linter, [
    'composer.json' => $goodComposer,
    'vite.config.js' => "import laravel from 'laravel-vite-plugin';\n",
    'resources/js/cp.js' => "export default {};\n",
]);
check('a vite config without the statamic plugin is reported', fires($report, 'ui.vite-config'));

$report = lint($linter, [
    'composer.json' => $goodComposer,
    'webpack.mix.js' => "mix.js('resources/js/cp.js', 'dist');\n",
    'resources/js/cp.js' => "export default {};\n",
]);
check('laravel mix is reported', fires($report, 'ui.vite-config'));

// --- native ui -------------------------------------------------------------

$report = lint($linter, [
    'composer.json' => $goodComposer,
    'resources/views/cp/index.blade.php' => "@extends('statamic::layout')\n@section('content')\n@endsection\n",
]);
check('a v5 blade page shell is reported', fires($report, 'ui.legacy-blade-shell'));

$report = lint($linter, [
    'composer.json' => $goodComposer,
    'resources/js/pages/Index.vue' => "<template><div class=\"bg-white max-w-7xl\"><button>Go</button></div></template>\n",
]);
check('non-themeable colour is reported', fires($report, 'ui.themeable-colors'));
check('custom width container is reported', fires($report, 'ui.page-width'));

// ui-vocabulary §2.3: the narrow detail width is core's own, but only with the opt-in attribute.
$report = lint($linter, [
    'composer.json' => $goodComposer,
    'resources/js/pages/Show.vue' => "<template><div class=\"max-w-5xl 3xl:max-w-6xl mx-auto\" data-max-width-wrapper></div></template>\n",
]);
check('the narrow detail container is accepted', ! fires($report, 'ui.page-width'));

$report = lint($linter, [
    'composer.json' => $goodComposer,
    'resources/js/pages/Show.vue' => "<template><div class=\"max-w-5xl 3xl:max-w-6xl mx-auto\"></div></template>\n",
]);
check('the narrow detail container without data-max-width-wrapper is reported', fires($report, 'ui.page-width'));

$report = lint($linter, [
    'composer.json' => $goodComposer,
    'resources/js/pages/Show.vue' => "<template><div class=\"max-w-5xl mx-auto\" data-max-width-wrapper></div></template>\n",
]);
check('a lone max-w-5xl is still reported', fires($report, 'ui.page-width'));

// The breakpoint-less single-column grid utility: a cross-addon collision that
// is invisible when one addon is checked alone, and one that a comment naming
// the class re-creates all by itself.
$report = lint($linter, [
    'composer.json' => $goodComposer,
    'resources/js/pages/Index.vue' => "<template><div class=\"grid grid-cols-1 sm:grid-cols-2\"></div></template>\n",
]);
check('the bare single-column grid utility is reported', fires($report, 'ui.bare-single-column-grid'));

$report = lint($linter, [
    'composer.json' => $goodComposer,
    'resources/js/pages/Index.vue' => "<template><!-- deliberately no grid-cols-1 here --><div class=\"grid sm:grid-cols-2\"></div></template>\n",
]);
check('naming the class in a comment is reported too', fires($report, 'ui.bare-single-column-grid'));

$report = lint($linter, [
    'composer.json' => $goodComposer,
    'resources/js/pages/Index.vue' => "<template><div class=\"grid sm:grid-cols-2 lg:grid-cols-3 *:min-w-0\"></div></template>\n",
]);
check('a grid without the bare utility is accepted', ! fires($report, 'ui.bare-single-column-grid'));

$report = lint($linter, [
    'composer.json' => $goodComposer,
    'resources/js/pages/Index.vue' => "<template><div class=\"grid sm:grid-cols-1 lg:grid-cols-12\"></div></template>\n",
]);
check('a variant-prefixed single-column grid is accepted', ! fires($report, 'ui.bare-single-column-grid'));

$scriptSetupFieldtype = <<<'VUE'
<template><ui-input :read-only="isReadOnly" :model-value="value" @update:model-value="update" /></template>
<script setup>
import { Fieldtype } from '@statamic/cms';
const props = defineProps(Fieldtype.props);
const emit = defineEmits(Fieldtype.emits);
const { update, expose, isReadOnly } = Fieldtype.use(emit, props);
defineExpose(expose);
</script>
VUE;

$report = lint($linter, [
    'composer.json' => $goodComposer,
    'resources/js/components/ThingFieldtype.vue' => $scriptSetupFieldtype,
]);
check('a correct script-setup fieldtype is accepted', ! fires($report, 'ui.fieldtype-contract'));

$report = lint($linter, [
    'composer.json' => $goodComposer,
    'resources/js/components/ThingFieldtype.vue' => str_replace('defineExpose(expose);', '', $scriptSetupFieldtype),
]);
check('a script-setup fieldtype without defineExpose is reported', fires($report, 'ui.fieldtype-contract'));

$optionsApiFieldtype = <<<'VUE'
<template><ui-input :read-only="isReadOnly" /></template>
<script>
import { FieldtypeMixin } from '@statamic/cms';
export default { mixins: [FieldtypeMixin] };
</script>
VUE;

$report = lint($linter, [
    'composer.json' => $goodComposer,
    'resources/js/components/ThingFieldtype.vue' => $optionsApiFieldtype,
]);
check('an options-api fieldtype is not asked for defineExpose', ! fires($report, 'ui.fieldtype-contract'));

$report = lint($linter, [
    'composer.json' => $goodComposer,
    'resources/js/pages/Preview.vue' => "<template><iframe :srcdoc=\"html\" /></template>\n",
]);
check('an unsandboxed iframe is reported', fires($report, 'ui.unsandboxed-iframe'));

$report = lint($linter, [
    'composer.json' => $goodComposer,
    'resources/js/pages/Preview.vue' => "<template><iframe sandbox=\"\" :srcdoc=\"html\" /></template>\n",
]);
check('a sandboxed iframe is accepted', ! fires($report, 'ui.unsandboxed-iframe'));

// --- code ------------------------------------------------------------------

$report = lint($linter, [
    'composer.json' => $goodComposer,
    'src/Thing.php' => "<?php\nnamespace Acme\\Thing;\nuse Statamic\\Facades\\YAML;\nclass Thing { public function save() { return YAML::dump([]); } public function dump(): void {} }\n",
]);
check('YAML::dump() is not mistaken for a debug call', ! fires($report, 'code.debug-leftovers'));

$report = lint($linter, [
    'composer.json' => $goodComposer,
    'src/Thing.php' => "<?php\nnamespace Acme\\Thing;\nclass Thing { public function go() { dd(\$this); } }\n",
]);
check('a real dd() is reported', fires($report, 'code.debug-leftovers'));

$report = lint($linter, [
    'composer.json' => $goodComposer,
    'src/Http/Controllers/SettingsController.php' => "<?php\nnamespace Acme\\Thing\\Http\\Controllers;\nclass SettingsController { public function index() { return \\Inertia::render('Settings', ['config' => config('thing')]); } }\n",
]);
check('handing a whole config file to the view is reported', fires($report, 'code.secrets-to-frontend'));

// --- robustness ------------------------------------------------------------

$report = lint($linter, []);
check('an empty directory does not crash the linter', $report instanceof Report);

$report = lint($linter, ['composer.json' => 'not json at all {{{']);
check('malformed composer.json does not crash the linter', $report instanceof Report);

// --- output ----------------------------------------------------------------

echo sprintf("%d passed, %d failed\n", $passed, count($failed));

foreach ($failed as $label) {
    echo "  FAIL  {$label}\n";
}

exit($failed === [] ? 0 : 1);
