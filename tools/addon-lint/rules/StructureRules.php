<?php

declare(strict_types=1);

namespace StatamicAddonStudio\Lint\Rules;

use StatamicAddonStudio\Lint\AbstractRule;
use StatamicAddonStudio\Lint\AddonContext;
use StatamicAddonStudio\Lint\Severity;

/**
 * Package skeleton and composer metadata.
 *
 * Baseline established from the 15 reference addons in `reference/` (July 2026).
 */
final class ComposerTypeRule extends AbstractRule
{
    public function id(): string
    {
        return 'structure.composer-type';
    }

    public function title(): string
    {
        return 'Declare `"type": "statamic-addon"` in composer.json';
    }

    public function category(): string
    {
        return 'structure';
    }

    public function severity(): string
    {
        return Severity::MINOR;
    }

    public function rationale(): string
    {
        return 'Statamic discovers and lists addons by package type. Official addons ssg, mailchimp, '
            .'responsive-images and tabs all declare it; the ones that omit it rely on provider autoloading only.';
    }

    public function check(AddonContext $addon): array
    {
        $type = $addon->composerValue('type');

        if ($type === 'statamic-addon') {
            return [];
        }

        return [$this->fail(
            $type === null
                ? 'composer.json has no `type` key.'
                : sprintf('composer.json declares `type: %s`.', (string) $type),
            'composer.json',
            null,
            'Set "type": "statamic-addon".'
        )];
    }
}

final class StatamicMetadataRule extends AbstractRule
{
    public function id(): string
    {
        return 'structure.statamic-metadata';
    }

    public function title(): string
    {
        return 'Provide `extra.statamic.name` and `extra.statamic.description`';
    }

    public function category(): string
    {
        return 'structure';
    }

    public function severity(): string
    {
        return Severity::BLOCKER;
    }

    public function rationale(): string
    {
        return 'These two strings are what the Control Panel and the Marketplace listing show. '
            .'All 15 reference addons set both.';
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        foreach (['name', 'description'] as $key) {
            $value = $addon->composerValue('extra.statamic.'.$key);

            if (! is_string($value) || trim($value) === '') {
                $findings[] = $this->fail(
                    sprintf('`extra.statamic.%s` is missing or empty.', $key),
                    'composer.json',
                    null,
                    'The CP addon listing and the Marketplace read this value.'
                );
            }
        }

        $description = $addon->composerValue('extra.statamic.description');

        if (is_string($description) && trim($description) !== '' && mb_strlen($description) < 25) {
            $findings[] = $this->failWith(
                Severity::MINOR,
                'The addon description is very short — it is the one-line pitch in the Marketplace.',
                'composer.json'
            );
        }

        return $findings;
    }
}

final class ServiceProviderRule extends AbstractRule
{
    public function id(): string
    {
        return 'structure.service-provider';
    }

    public function title(): string
    {
        return 'Register the service provider through `extra.laravel.providers` and have it exist';
    }

    public function category(): string
    {
        return 'structure';
    }

    public function severity(): string
    {
        return Severity::BLOCKER;
    }

    public function rationale(): string
    {
        return 'Every reference addon boots through Laravel package discovery. A provider declared but '
            .'missing (or present but undeclared) means the addon silently does nothing after install.';
    }

    public function check(AddonContext $addon): array
    {
        $declared = (array) $addon->composerValue('extra.laravel.providers', []);

        if ($declared === []) {
            return [$this->fail(
                '`extra.laravel.providers` is empty — the addon will not boot via package discovery.',
                'composer.json'
            )];
        }

        $findings = [];
        $psr4 = $addon->composerValue('autoload.psr-4', []);

        foreach ($declared as $class) {
            $class = (string) $class;
            $relative = null;

            foreach ((array) $psr4 as $namespace => $dir) {
                $namespace = rtrim((string) $namespace, '\\');

                if (str_starts_with($class, $namespace.'\\')) {
                    $tail = substr($class, strlen($namespace) + 1);
                    $relative = rtrim((string) (is_array($dir) ? $dir[0] : $dir), '/').'/'
                        .str_replace('\\', '/', $tail).'.php';
                    break;
                }
            }

            if ($relative === null) {
                $findings[] = $this->fail(
                    sprintf('Provider `%s` is not covered by any PSR-4 autoload prefix.', $class),
                    'composer.json'
                );
            } elseif (! $addon->has($relative)) {
                $findings[] = $this->fail(
                    sprintf('Provider `%s` is declared but `%s` does not exist.', $class, $relative),
                    'composer.json'
                );
            }
        }

        return $findings;
    }
}

final class Psr4SrcRule extends AbstractRule
{
    public function id(): string
    {
        return 'structure.psr4-src';
    }

    public function title(): string
    {
        return 'Autoload PHP from `src/` via a single PSR-4 prefix';
    }

    public function category(): string
    {
        return 'structure';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'All 15 reference addons use exactly one `Vendor\\Package\\ => src` mapping. '
            .'Deviating makes IDE navigation, static analysis and the studio tooling unreliable.';
    }

    public function check(AddonContext $addon): array
    {
        $psr4 = (array) $addon->composerValue('autoload.psr-4', []);

        if ($psr4 === []) {
            return [$this->fail('No PSR-4 autoload mapping in composer.json.', 'composer.json')];
        }

        $mapsSrc = false;

        foreach ($psr4 as $dir) {
            foreach ((array) $dir as $candidate) {
                if (rtrim((string) $candidate, '/') === 'src') {
                    $mapsSrc = true;
                }
            }
        }

        if (! $mapsSrc) {
            return [$this->fail(
                'No PSR-4 prefix maps to `src`.',
                'composer.json',
                null,
                'Use "Vendor\\\\Package\\\\": "src" like every reference addon does.'
            )];
        }

        if (count($psr4) > 1) {
            return [$this->failWith(
                Severity::MINOR,
                sprintf('composer.json declares %d PSR-4 prefixes; one is the convention.', count($psr4)),
                'composer.json'
            )];
        }

        return [];
    }
}

final class DependencyConstraintsRule extends AbstractRule
{
    public function id(): string
    {
        return 'structure.constraints';
    }

    public function title(): string
    {
        return 'Constrain `php` and `statamic/cms` explicitly and support Statamic 6';
    }

    public function category(): string
    {
        return 'structure';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'Statamic 6 changed the Control Panel substantially. An addon that still allows ^5 without '
            .'shipping v5-compatible UI installs cleanly and then breaks in the CP.';
    }

    public function check(AddonContext $addon): array
    {
        $require = (array) $addon->composerValue('require', []);
        $findings = [];

        if (! isset($require['php'])) {
            // Several official addons omit it and inherit the constraint transitively from
            // statamic/cms. It still costs a clear error message on an unsupported PHP.
            $findings[] = $this->failWith(
                Severity::MINOR,
                'No `php` constraint in require; the supported PHP range is only implied by statamic/cms.',
                'composer.json'
            );
        }

        $statamic = $require['statamic/cms'] ?? null;

        if ($statamic === null) {
            // Framework-agnostic support packages legitimately move it to require-dev/suggest.
            $dev = (array) $addon->composerValue('require-dev', []);
            $suggest = (array) $addon->composerValue('suggest', []);

            if (! isset($dev['statamic/cms']) && ! isset($suggest['statamic/cms'])) {
                $findings[] = $this->fail(
                    'Neither `require` nor `require-dev` nor `suggest` mentions statamic/cms.',
                    'composer.json'
                );
            }

            return $findings;
        }

        if (! str_contains((string) $statamic, '6')) {
            $findings[] = $this->fail(
                sprintf('statamic/cms constraint `%s` does not allow Statamic 6.', (string) $statamic),
                'composer.json'
            );
        } elseif (preg_match('/\^?5/', (string) $statamic) === 1) {
            $findings[] = $this->failWith(
                Severity::MINOR,
                sprintf('statamic/cms constraint `%s` spans v5 and v6.', (string) $statamic),
                'composer.json',
                null,
                'The v6 CP is a different design system. Prefer a v6-only line and keep v5 on a maintenance branch.'
            );
        }

        return $findings;
    }
}

final class FrameworkRangeRule extends AbstractRule
{
    public function id(): string
    {
        return 'structure.framework-range';
    }

    public function title(): string
    {
        return 'Do not promise a Laravel version the declared Statamic version cannot install';
    }

    public function category(): string
    {
        return 'structure';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'Statamic 6 requires laravel/framework ^12.40 || ^13.0. An addon declaring ^11 alongside '
            .'statamic/cms ^6.0 states a support range that cannot resolve for anyone — and a CI job that '
            .'tests one combination never notices. Found live in brand-context on its first CI run.';
    }

    /** Statamic major => the Laravel majors it accepts. */
    private const SUPPORTED = [
        6 => [12, 13],
        5 => [10, 11, 12],
        4 => [9, 10, 11],
    ];

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->composerValue('require.laravel/framework') !== null
            && $addon->composerValue('require.statamic/cms') !== null;
    }

    public function check(AddonContext $addon): array
    {
        $laravel = (string) $addon->composerValue('require.laravel/framework');
        $statamic = (string) $addon->composerValue('require.statamic/cms');

        $statamicMajors = $this->majors($statamic);
        $laravelMajors = $this->majors($laravel);

        if ($statamicMajors === [] || $laravelMajors === []) {
            return [];
        }

        $allowed = [];

        foreach ($statamicMajors as $major) {
            foreach (self::SUPPORTED[$major] ?? [] as $candidate) {
                $allowed[$candidate] = true;
            }
        }

        if ($allowed === []) {
            return []; // An unknown Statamic major; say nothing rather than guess.
        }

        $impossible = array_values(array_filter($laravelMajors, fn (int $m) => ! isset($allowed[$m])));

        if ($impossible === []) {
            return [];
        }

        return [$this->fail(
            sprintf(
                'laravel/framework `%s` allows Laravel %s, which statamic/cms `%s` cannot install.',
                $laravel,
                implode(' and ', $impossible),
                $statamic
            ),
            'composer.json',
            null,
            sprintf('Narrow the constraint to Laravel %s.', implode('/', array_keys($allowed)))
        )];
    }

    /** @return int[] */
    private function majors(string $constraint): array
    {
        if (preg_match_all('/(\d+)(?:\.\d+)*/', $constraint, $matches) < 1) {
            return [];
        }

        $majors = array_map('intval', $matches[1]);

        return array_values(array_unique($majors));
    }
}

final class LicenseRule extends AbstractRule
{
    public function id(): string
    {
        return 'structure.license';
    }

    public function title(): string
    {
        return 'Ship a LICENSE file and declare the matching `license` key';
    }

    public function category(): string
    {
        return 'structure';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'Commercial Marketplace addons use `"license": "proprietary"` plus a licence file; '
            .'free ones use MIT. A missing licence blocks a listing.';
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];
        $declared = $addon->composerValue('license');

        if (! is_string($declared) || trim($declared) === '') {
            $findings[] = $this->fail('composer.json has no `license` key.', 'composer.json');
        }

        $hasFile = $addon->has('LICENSE.md') || $addon->has('LICENSE') || $addon->has('LICENSE.txt');

        if (! $hasFile) {
            $findings[] = $this->fail('No LICENSE / LICENSE.md file in the package root.');
        }

        return $findings;
    }
}

final class GitattributesRule extends AbstractRule
{
    public function id(): string
    {
        return 'structure.gitattributes';
    }

    public function title(): string
    {
        return 'Keep the distributed package small with `.gitattributes` export-ignore';
    }

    public function category(): string
    {
        return 'structure';
    }

    public function severity(): string
    {
        return Severity::MINOR;
    }

    public function rationale(): string
    {
        return 'Tests, CI config and build sources are downloaded into every site that installs the addon '
            .'unless they are export-ignored. Runway, Mailchimp and Advanced SEO do this.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->match('tests/*') !== [] || $addon->match('.github/*') !== [];
    }

    public function check(AddonContext $addon): array
    {
        if (! $addon->has('.gitattributes')) {
            return [$this->fail(
                'No `.gitattributes`; tests and CI config are shipped to every installing site.',
                null,
                null,
                'Add export-ignore lines for /tests, /.github, /phpunit.xml, /vite.config.js, /package.json.'
            )];
        }

        $contents = $addon->read('.gitattributes') ?? '';

        if (! str_contains($contents, 'export-ignore')) {
            return [$this->fail('`.gitattributes` contains no export-ignore rules.', '.gitattributes')];
        }

        return [];
    }
}
