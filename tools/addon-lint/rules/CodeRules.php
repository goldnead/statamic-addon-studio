<?php

declare(strict_types=1);

namespace StatamicAddonStudio\Lint\Rules;

use StatamicAddonStudio\Lint\AbstractRule;
use StatamicAddonStudio\Lint\AddonContext;
use StatamicAddonStudio\Lint\Severity;

final class PintRule extends AbstractRule
{
    public function id(): string
    {
        return 'code.pint';
    }

    public function title(): string
    {
        return 'Format with Laravel Pint';
    }

    public function category(): string
    {
        return 'code';
    }

    public function severity(): string
    {
        return Severity::MINOR;
    }

    public function rationale(): string
    {
        return 'Every official Statamic addon ships a pint.json. Matching core formatting means a diff '
            .'against core code reads as a real difference, not as whitespace.';
    }

    public function check(AddonContext $addon): array
    {
        $dev = (array) $addon->composerValue('require-dev', []);

        if ($addon->has('pint.json') || isset($dev['laravel/pint'])) {
            return [];
        }

        if ($addon->has('.php-cs-fixer.php') || $addon->has('.php-cs-fixer.dist.php') || $addon->has('.styleci.yaml')) {
            return [$this->failWith(
                Severity::INFO,
                'Formatting is configured, but not with Pint.',
                null,
                null,
                'Statamic core and all official addons use Pint; matching it lowers review friction.'
            )];
        }

        return [$this->fail('No code formatter configured.', null, null, 'composer require --dev laravel/pint and add pint.json.')];
    }
}

final class NoComposerLockRule extends AbstractRule
{
    public function id(): string
    {
        return 'code.no-composer-lock';
    }

    public function title(): string
    {
        return 'Do not commit composer.lock in a library package';
    }

    public function category(): string
    {
        return 'code';
    }

    public function severity(): string
    {
        return Severity::MINOR;
    }

    public function rationale(): string
    {
        return 'A lock file in a library pins nothing for consumers and only makes the CI matrix lie about '
            .'which dependency versions were actually tested.';
    }

    public function check(AddonContext $addon): array
    {
        if (! in_array('composer.lock', $addon->files(), true)) {
            return [];
        }

        return [$this->fail('composer.lock is committed.', 'composer.lock', null, 'Git-ignore it; CI should resolve fresh.')];
    }
}

final class StabilityRule extends AbstractRule
{
    public function id(): string
    {
        return 'code.stability';
    }

    public function title(): string
    {
        return 'Do not ship `minimum-stability: dev`';
    }

    public function category(): string
    {
        return 'code';
    }

    public function severity(): string
    {
        return Severity::INFO;
    }

    public function rationale(): string
    {
        return 'Composer only honours `minimum-stability` from the root package, so this does not leak into '
            .'a consuming site. It does mean the addon\'s own CI resolves against unstable dependencies, which '
            .'is why Runway and SEO Pro set it deliberately. Worth a conscious decision, not an automatic fix.';
    }

    public function check(AddonContext $addon): array
    {
        $stability = $addon->composerValue('minimum-stability');

        if ($stability === null || $stability === 'stable') {
            return [];
        }

        $hasFlag = $addon->composerValue('prefer-stable') === true;

        return [$this->fail(
            sprintf(
                'composer.json sets minimum-stability to `%s`%s.',
                (string) $stability,
                $hasFlag ? ' with prefer-stable' : ' without prefer-stable'
            ),
            'composer.json',
            null,
            $hasFlag ? 'Deliberate and safe.' : 'Add "prefer-stable": true so CI still favours tagged releases.'
        )];
    }
}

final class DebugLeftoversRule extends AbstractRule
{
    public function id(): string
    {
        return 'code.debug-leftovers';
    }

    public function title(): string
    {
        return 'Ship no debug statements';
    }

    public function category(): string
    {
        return 'code';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'dd()/dump()/ray()/console.log in shipped code leaks into a customer site, sometimes into a '
            .'production response body.';
    }

    public function check(AddonContext $addon): array
    {
        $php = array_values(array_filter($addon->phpFiles(), fn (string $f) => ! str_starts_with($f, 'tests/')));
        $js = array_values(array_filter(
            array_merge($addon->vueFiles(), $addon->jsFiles()),
            fn (string $f) => ! str_contains($f, 'dist/') && ! str_contains($f, 'tests/')
        ));

        $findings = [];

        // The lookbehind must also exclude `::` and `->` so that YAML::dump(), Str::dump()
        // and $collection->dump() are not mistaken for debug calls.
        foreach ($addon->grep('/(?<!function )(?<![\w>:])(dd|dump|ray|var_dump|print_r)\s*\(/', $php) as $hit) {
            $findings[] = $this->fail('Debug call: '.trim($hit['text']), $hit['file'], $hit['line']);
        }

        foreach ($addon->grep('/console\.(log|debug|warn)\s*\(/', $js) as $hit) {
            $findings[] = $this->failWith(Severity::MINOR, 'Console statement: '.trim($hit['text']), $hit['file'], $hit['line']);
        }

        return $findings;
    }
}

final class ConfigPublishingRule extends AbstractRule
{
    public function id(): string
    {
        return 'code.config-publishing';
    }

    public function title(): string
    {
        return 'Merge and publish the addon config through the documented mechanism';
    }

    public function category(): string
    {
        return 'code';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'A config file that is never merged returns null on a site that has not published it, so the '
            .'addon breaks precisely for the users who did nothing wrong.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->match('config/*.php') !== [];
    }

    public function check(AddonContext $addon): array
    {
        $providers = $addon->serviceProviders();

        if ($providers === []) {
            return [];
        }

        // Core auto-merges and auto-publishes anything in the addon's config/ directory.
        // A manual mergeConfigFrom is fine; a manual publish that forgets the merge is not.
        $merges = $addon->contains('/mergeConfigFrom|autoPublishesConfig|\$publishables/', $providers);
        $publishes = $addon->contains('/publishes\(/', $providers);

        if ($publishes && ! $merges) {
            return [$this->fail(
                'The config is published but never merged; unpublished installs read null.',
                $providers[0],
                null,
                'Add $this->mergeConfigFrom(__DIR__.\'/../config/x.php\', \'x\') or rely on core auto-merging.'
            )];
        }

        return [];
    }
}

final class TranslationFilesRule extends AbstractRule
{
    public function id(): string
    {
        return 'code.translation-files';
    }

    public function title(): string
    {
        return 'Ship the translation files the `__()` calls resolve against';
    }

    public function category(): string
    {
        return 'code';
    }

    public function severity(): string
    {
        return Severity::MINOR;
    }

    public function rationale(): string
    {
        return 'Namespaced translation keys without a lang/ directory render the raw key in the CP.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->contains('/__\(\s*[\'"][\w-]+::/', array_merge($addon->phpFiles(), $addon->bladeFiles()));
    }

    public function check(AddonContext $addon): array
    {
        if ($addon->match('lang/*') !== [] || $addon->match('resources/lang/*') !== []) {
            return [];
        }

        return [$this->fail(
            'Namespaced translation keys are used but no lang/ directory is shipped.',
            null,
            null,
            'Add lang/en/*.php and register the namespace, or drop the namespace from the keys.'
        )];
    }
}
