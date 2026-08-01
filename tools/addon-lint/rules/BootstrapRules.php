<?php

declare(strict_types=1);

namespace StatamicAddonStudio\Lint\Rules;

use StatamicAddonStudio\Lint\AbstractRule;
use StatamicAddonStudio\Lint\AddonContext;
use StatamicAddonStudio\Lint\Severity;

/**
 * How the addon boots.
 *
 * Core's AddonServiceProvider already autoloads src/Fieldtypes, src/Tags, src/Modifiers,
 * src/Actions, src/Listeners, src/Policies, src/Commands, src/Scopes, routes/{cp,web,actions}.php
 * and config/. Declaring those by hand is redundant and drifts.
 */
final class AddonServiceProviderRule extends AbstractRule
{
    public function id(): string
    {
        return 'bootstrap.addon-service-provider';
    }

    public function title(): string
    {
        return 'Extend `Statamic\Providers\AddonServiceProvider`, not Laravel\'s ServiceProvider';
    }

    public function category(): string
    {
        return 'bootstrap';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'Statamic\'s provider supplies autoloading of addon classes, route registration, view '
            .'namespacing, publishables and Vite wiring. A plain Laravel provider re-implements all of it by hand.';
    }

    public function check(AddonContext $addon): array
    {
        $declared = (array) $addon->composerValue('extra.laravel.providers', []);

        if ($declared === []) {
            return []; // structure.service-provider already reports this.
        }

        $providers = $addon->serviceProviders();

        if ($providers === []) {
            return [$this->fail(
                'No service provider class was found in src/.',
                'composer.json',
                null,
                'class ServiceProvider extends \Statamic\Providers\AddonServiceProvider'
            )];
        }

        $findings = [];

        foreach ($providers as $provider) {
            $contents = $addon->read($provider) ?? '';

            if (preg_match('/extends\s+\\\\?(\w+\\\\)*AddonServiceProvider\b/', $contents) === 1) {
                continue;
            }

            // A package that must also boot in a Statamic-less context cannot extend
            // AddonServiceProvider, so this is a prompt to confirm the choice was deliberate,
            // not a defect. Everything else should extend it.
            $findings[] = $this->failWith(
                Severity::INFO,
                'Extends Laravel\'s ServiceProvider rather than AddonServiceProvider.',
                $provider,
                null,
                'Deliberate only if the package must boot without Statamic. Otherwise you are '
                .'re-implementing autoloading, route registration, view namespacing and Vite wiring by hand.'
            );
        }

        return $findings;
    }
}

final class RedundantRegistrationRule extends AbstractRule
{
    public function id(): string
    {
        return 'bootstrap.redundant-registration';
    }

    public function title(): string
    {
        return 'Do not hand-register classes the AddonServiceProvider already autoloads';
    }

    public function category(): string
    {
        return 'bootstrap';
    }

    public function severity(): string
    {
        return Severity::MINOR;
    }

    public function rationale(): string
    {
        return 'Runway declares one property, Advanced SEO three. Explicit lists silently go stale when a '
            .'class is added, producing "my fieldtype does not show up" bugs.';
    }

    /** property => directory core scans automatically */
    private const AUTOLOADED = [
        'fieldtypes' => 'src/Fieldtypes',
        'tags' => 'src/Tags',
        'modifiers' => 'src/Modifiers',
        'actions' => 'src/Actions',
        'listeners' => 'src/Listeners',
        'policies' => 'src/Policies',
        'commands' => 'src/Commands',
        'scopes' => 'src/Scopes',
        'widgets' => 'src/Widgets',
    ];

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->serviceProviders() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        foreach ($addon->serviceProviders() as $provider) {
            foreach (self::AUTOLOADED as $property => $directory) {
                $hits = $addon->grep('/protected\s+\$'.$property.'\s*=\s*\[/', [$provider]);

                if ($hits === [] || $addon->match($directory.'/*') === []) {
                    continue;
                }

                $findings[] = $this->fail(
                    sprintf('`$%s` is declared while `%s/` exists and is autoloaded by core.', $property, $directory),
                    $provider,
                    $hits[0]['line'],
                    'Remove the property and let core discover the classes.'
                );
            }
        }

        return $findings;
    }
}

final class BootAddonSizeRule extends AbstractRule
{
    public function id(): string
    {
        return 'bootstrap.boot-addon-size';
    }

    public function title(): string
    {
        return 'Keep `bootAddon()` a chain of single-purpose `boot*()` methods';
    }

    public function category(): string
    {
        return 'bootstrap';
    }

    public function severity(): string
    {
        return Severity::MINOR;
    }

    public function rationale(): string
    {
        return 'Runway and Advanced SEO both express bootAddon() as a fluent chain returning $this. It keeps '
            .'the provider readable as the addon grows and makes each concern individually testable.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->serviceProviders() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        foreach ($addon->serviceProviders() as $provider) {
            $contents = $addon->read($provider) ?? '';
            $lines = explode("\n", $contents);
            $start = null;
            $depth = 0;
            $length = 0;

            foreach ($lines as $index => $line) {
                if ($start === null && preg_match('/function\s+bootAddon\s*\(/', $line) === 1) {
                    $start = $index + 1;
                }

                if ($start === null) {
                    continue;
                }

                $depth += substr_count($line, '{') - substr_count($line, '}');
                $length++;

                if ($length > 1 && $depth <= 0) {
                    break;
                }
            }

            if ($start !== null && $length > 60) {
                $findings[] = $this->fail(
                    sprintf('bootAddon() is %d lines long.', $length),
                    $provider,
                    $start,
                    'Split into boot*() methods returning $this and chain them.'
                );
            }
        }

        return $findings;
    }
}

final class ForkedCoreComponentRule extends AbstractRule
{
    public function id(): string
    {
        return 'bootstrap.forked-core-component';
    }

    public function title(): string
    {
        return 'Do not copy Statamic core files into the addon';
    }

    public function category(): string
    {
        return 'bootstrap';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'Every UI drift incident found in the reference set traces back to a forked core file. '
            .'A copy is frozen at the version it was taken from and diverges on the next core release.';
    }

    public function check(AddonContext $addon): array
    {
        $files = array_merge($addon->vueFiles(), $addon->jsFiles(), $addon->phpFiles(), $addon->bladeFiles());
        $findings = [];

        $patterns = [
            '/copied\s+from\s+(statamic\s+)?core/i',
            '/copy\s+of\s+statamic(\'s)?\s+/i',
            '/taken\s+from\s+statamic/i',
            '/vendor\/statamic\/cms\/resources\/js\//',
        ];

        foreach ($patterns as $pattern) {
            foreach ($addon->grep($pattern, $files) as $hit) {
                $findings[] = $this->fail(
                    'Forked core code: '.trim($hit['text']),
                    $hit['file'],
                    $hit['line'],
                    'Import the component from @statamic/cms/ui, or open a core PR for the missing extension point.'
                );
            }
        }

        return $findings;
    }
}

final class CpAuthorizationRule extends AbstractRule
{
    public function id(): string
    {
        return 'bootstrap.cp-authorization';
    }

    public function title(): string
    {
        return 'Authorize every write action in CP controllers, not just in the view';
    }

    public function category(): string
    {
        return 'bootstrap';
    }

    public function severity(): string
    {
        return Severity::BLOCKER;
    }

    public function rationale(): string
    {
        return 'Hiding a button in Blade while leaving the store/update/destroy route open is the exact '
            .'defect found in one of the largest third-party addons: any authenticated CP user can call it.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->cpControllers() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        // Routes registered through Utility::register() inherit core's
        // `can:access {handle} utility` middleware, so the controller needs no guard of its own.
        $routeFiles = $addon->match('routes/*.php');
        $guardedRoutes = $addon->contains('/middleware\(\s*\[?\s*[\'"]can:/', $routeFiles)
            || $addon->contains('/->can\(|[\'"]can:/', $routeFiles);
        $utilityRegistered = $addon->contains('/Utility::(register|extend)/', $addon->phpFiles());

        foreach ($addon->cpControllers() as $file) {
            $contents = $addon->read($file) ?? '';

            $writeMethods = [];

            foreach (explode("\n", $contents) as $index => $line) {
                if (preg_match('/public\s+function\s+(store|update|destroy|delete)\s*\(/', $line, $m) === 1) {
                    $writeMethods[] = ['name' => $m[1], 'line' => $index + 1];
                }
            }

            if ($writeMethods === []) {
                continue;
            }

            // `authorizeOrFail` is Statamic's own guard on `Statamic\Http\Controllers\CP\Controller`,
            // so a controller extending it authorizes correctly without ever writing `$this->authorize(`.
            // Missing it made this rule report `statamic-leadhub`'s ExportController as unguarded while
            // all 38 of its write routes return 403 for an unauthorized user.
            if (preg_match('/\$this->authorize(OrFail)?\(|Gate::|->can\(|abort_unless|abort_if|authorizeResource/', $contents) === 1) {
                continue;
            }

            $names = implode('/', array_column($writeMethods, 'name'));

            if ($guardedRoutes || $utilityRegistered) {
                $findings[] = $this->failWith(
                    Severity::MAJOR,
                    sprintf(
                        'CP controller exposes %s with no guard of its own; authorization appears to come '
                        .'from route middleware. Verify it actually covers these routes.',
                        $names
                    ),
                    $file,
                    $writeMethods[0]['line']
                );

                continue;
            }

            $findings[] = $this->fail(
                sprintf(
                    'CP controller exposes %s and neither the controller nor any route file authorizes.',
                    $names
                ),
                $file,
                $writeMethods[0]['line'],
                'Any authenticated CP user can call these routes. Add $this->authorize(...), '
                .'$this->authorizeOrFail(...) or a can: middleware.'
            );
        }

        return $findings;
    }
}
