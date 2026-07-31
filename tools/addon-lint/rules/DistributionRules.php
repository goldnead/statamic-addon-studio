<?php

declare(strict_types=1);

namespace StatamicAddonStudio\Lint\Rules;

use StatamicAddonStudio\Lint\AbstractRule;
use StatamicAddonStudio\Lint\AddonContext;
use StatamicAddonStudio\Lint\Severity;

/**
 * Can a stranger actually install this?
 *
 * These rules exist because the July 2026 audit of the in-house addons found that the real
 * release blocker was not code quality — it was that 11 of 12 packages could not be installed
 * by anyone but their author.
 */
final class ResolvableDependenciesRule extends AbstractRule
{
    public function id(): string
    {
        return 'release.resolvable-dependencies';
    }

    public function title(): string
    {
        return 'Do not rely on a `repositories` block to reach your own dependencies';
    }

    public function category(): string
    {
        return 'release';
    }

    public function severity(): string
    {
        return Severity::BLOCKER;
    }

    public function rationale(): string
    {
        return 'Composer ignores the `repositories` key of a package it installs as a dependency — it is '
            .'only honoured in the root project. A library whose siblings are reachable only through that '
            .'block installs for its author and fails for everyone else, which makes the README install '
            .'command untrue.';
    }

    public function check(AddonContext $addon): array
    {
        $repositories = $addon->composerValue('repositories');

        if ($repositories === null || $repositories === []) {
            return [];
        }

        $require = array_merge(
            (array) $addon->composerValue('require', []),
            (array) $addon->composerValue('require-dev', [])
        );

        $names = [];

        foreach ((array) $repositories as $repository) {
            $type = is_array($repository) ? ($repository['type'] ?? '?') : '?';
            $url = is_array($repository) ? ($repository['url'] ?? '') : '';
            $names[] = $type.($url !== '' ? ' '.$url : '');
        }

        $runtimeDeps = array_diff_key((array) $addon->composerValue('require', []), ['php' => null]);

        return [$this->fail(
            sprintf(
                'composer.json declares %d custom repositor%s (%s) while requiring %d package%s.',
                count($repositories),
                count($repositories) === 1 ? 'y' : 'ies',
                implode(', ', array_slice($names, 0, 3)),
                count($runtimeDeps),
                count($runtimeDeps) === 1 ? '' : 's'
            ),
            'composer.json',
            null,
            'Publish every runtime dependency to Packagist (or a private Composer registry the buyer can '
            .'authenticate against) and remove the block. Verify with: '
            .'composer create-project --no-install in a clean directory.'
        )];
    }
}

final class PackagistPresenceRule extends AbstractRule
{
    public function id(): string
    {
        return 'release.installable';
    }

    public function title(): string
    {
        return 'Every runtime dependency must be installable by the buyer';
    }

    public function category(): string
    {
        return 'release';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'An addon is only as installable as its least available dependency. A private sibling '
            .'package turns a paid download into a support ticket.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $this->siblings($addon) !== [];
    }

    public function check(AddonContext $addon): array
    {
        $vendor = explode('/', $addon->name())[0] ?? '';

        return [$this->failWith(
            Severity::INFO,
            sprintf(
                'Requires %d package(s) from the same vendor (%s). Each must be public before this addon can ship.',
                count($this->siblings($addon)),
                implode(', ', $this->siblings($addon))
            ),
            'composer.json',
            null,
            sprintf('Check each with: composer show %s/<name> --all', $vendor)
        )];
    }

    /** @return string[] */
    private function siblings(AddonContext $addon): array
    {
        $vendor = explode('/', $addon->name())[0] ?? null;

        if ($vendor === null || $vendor === '') {
            return [];
        }

        return array_values(array_filter(
            array_keys((array) $addon->composerValue('require', [])),
            fn (string $package) => str_starts_with($package, $vendor.'/')
        ));
    }
}

final class SecretsInFrontendRule extends AbstractRule
{
    public function id(): string
    {
        return 'code.secrets-to-frontend';
    }

    public function title(): string
    {
        return 'Never hand a whole config array to the frontend';
    }

    public function category(): string
    {
        return 'code';
    }

    public function severity(): string
    {
        return Severity::BLOCKER;
    }

    public function rationale(): string
    {
        return 'Passing `config(\'addon\')` wholesale into an Inertia prop or a Blade view ships every key '
            .'in that file — including API tokens — to the browser. Pass the individual values the screen '
            .'actually needs.';
    }

    public function check(AddonContext $addon): array
    {
        $files = array_merge($addon->phpFiles(), $addon->bladeFiles());
        $findings = [];

        // config('handle') with no dot means "the entire file".
        $patterns = [
            '/Inertia::render\([^)]*config\(\s*[\'"][a-z0-9_.-]+[\'"]\s*\)/i',
            '/[\'"]\w+[\'"]\s*=>\s*config\(\s*[\'"][a-z0-9_-]+[\'"]\s*\)\s*,?\s*(\/\/.*)?$/i',
        ];

        foreach ($patterns as $pattern) {
            foreach ($addon->grep($pattern, $files) as $hit) {
                if (preg_match('/config\(\s*[\'"][a-z0-9_-]+\.[a-z0-9_.-]+[\'"]/i', $hit['text']) === 1) {
                    continue; // A specific key, not the whole file.
                }

                if (! preg_match('/Inertia::render|->with\(|compact\(|[\'"]props[\'"]/', $hit['text'])
                    && ! str_contains($hit['file'], 'Controllers')) {
                    continue;
                }

                $findings[] = $this->fail(
                    'A whole config file is handed to the view layer: '.trim($hit['text']),
                    $hit['file'],
                    $hit['line'],
                    'Pass only the keys the screen needs, e.g. config(\'addon.public_setting\').'
                );
            }
        }

        return $findings;
    }
}

final class UnsandboxedIframeRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.unsandboxed-iframe';
    }

    public function title(): string
    {
        return 'Sandbox any iframe that renders user-authored content';
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
        return 'A same-origin iframe rendering content a CP user authored gives that content full access '
            .'to the CP session. Any editor who can write HTML can then act as any admin who previews it.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->contains('/<iframe/i', array_merge($addon->vueFiles(), $addon->cpViews()));
    }

    public function check(AddonContext $addon): array
    {
        $files = array_merge($addon->vueFiles(), $addon->cpViews());
        $findings = [];

        foreach ($files as $file) {
            $contents = $addon->read($file);

            if ($contents === null) {
                continue;
            }

            // The attribute may sit on any line of a multi-line tag, so inspect the whole tag.
            if (preg_match_all('/<iframe\b[^>]*>/is', $contents, $matches, PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }

            foreach ($matches[0] as [$tag, $offset]) {
                if (stripos($tag, 'sandbox') !== false) {
                    continue;
                }

                $findings[] = $this->fail(
                    'Iframe without a `sandbox` attribute.',
                    $file,
                    substr_count(substr($contents, 0, $offset), "\n") + 1,
                    'Add sandbox="allow-same-origin" only if genuinely required; prefer a fully sandboxed '
                    .'iframe or a srcdoc rendered from sanitised HTML.'
                );
            }
        }

        return $findings;
    }
}

final class ConfirmDestructiveActionsRule extends AbstractRule
{
    public function id(): string
    {
        return 'ui.confirm-destructive';
    }

    public function title(): string
    {
        return 'Confirm every destructive action';
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
        return 'Core confirms every delete. An addon screen where one delete asks and the next does not is '
            .'both inconsistent with core and a data-loss report waiting to happen.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->vueFiles() !== [];
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        foreach ($addon->vueFiles() as $file) {
            $contents = $addon->read($file);

            if ($contents === null) {
                continue;
            }

            $confirms = preg_match('/ui-confirmation|ConfirmationModal|confirm\(|:confirm\b|useConfirm/i', $contents) === 1;

            if ($confirms) {
                continue;
            }

            foreach (explode("\n", $contents) as $index => $line) {
                if (preg_match('/router\.delete\(|\.delete\(|axios\.delete\(/', $line) !== 1) {
                    continue;
                }

                $findings[] = $this->fail(
                    'Destructive request with no confirmation anywhere in the component: '.trim($line),
                    $file,
                    $index + 1,
                    'Wrap it in <ui-confirmation-modal>, as core does for every delete.'
                );
            }
        }

        return $findings;
    }
}
