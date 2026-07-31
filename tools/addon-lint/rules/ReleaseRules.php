<?php

declare(strict_types=1);

namespace StatamicAddonStudio\Lint\Rules;

use StatamicAddonStudio\Lint\AbstractRule;
use StatamicAddonStudio\Lint\AddonContext;
use StatamicAddonStudio\Lint\Severity;

final class ReadmeRule extends AbstractRule
{
    public function id(): string
    {
        return 'release.readme';
    }

    public function title(): string
    {
        return 'Ship a README that covers requirements, installation and usage';
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
        return 'The README is the Marketplace listing body and the only documentation most buyers read '
            .'before purchase.';
    }

    public function check(AddonContext $addon): array
    {
        $readme = $addon->read('README.md');

        if ($readme === null) {
            return [$this->fail('No README.md.')];
        }

        $findings = [];
        $lower = strtolower($readme);

        // Official addons keep the long-form documentation on statamic.dev and the README short.
        // That is legitimate — but only if the README actually points there.
        $linksToDocs = preg_match('#\]\(https?://[^)]*(docs?|statamic\.dev|documentation)#i', $readme) === 1
            || preg_match('/#+\s*documentation/i', $readme) === 1;

        $sections = [
            'installation' => '/#+\s*(installation|install|getting started|setup)/i',
            'usage' => '/#+\s*(usage|how it works|configuration|use|features)/i',
            'requirements' => '/#+\s*(requirements|compatibility|support)/i',
        ];

        foreach ($sections as $name => $pattern) {
            if (preg_match($pattern, $readme) === 1) {
                continue;
            }

            $severity = match (true) {
                $name === 'requirements' => Severity::MINOR,
                $linksToDocs => Severity::MINOR,
                default => Severity::MAJOR,
            };

            $findings[] = $this->failWith(
                $severity,
                sprintf(
                    'README has no %s section%s.',
                    $name,
                    $linksToDocs ? ' (documentation is linked externally)' : ''
                ),
                'README.md'
            );
        }

        if (! str_contains($lower, 'composer require') && ! $linksToDocs) {
            $findings[] = $this->failWith(
                Severity::MAJOR,
                'README neither shows a `composer require` line nor links to documentation.',
                'README.md'
            );
        }

        if (str_word_count(strip_tags($readme)) < 120 && ! $linksToDocs) {
            $findings[] = $this->failWith(
                Severity::MAJOR,
                'README is very short for a paid addon listing and links to no external docs.',
                'README.md'
            );
        }

        return $findings;
    }
}

final class ChangelogRule extends AbstractRule
{
    public function id(): string
    {
        return 'release.changelog';
    }

    public function title(): string
    {
        return 'Maintain a CHANGELOG';
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
        return 'Buyers upgrading a paid addon need to know what changed. Nine of the reference addons ship one.';
    }

    public function check(AddonContext $addon): array
    {
        if ($addon->has('CHANGELOG.md') || $addon->has('CHANGELOG')) {
            return [];
        }

        return [$this->fail('No CHANGELOG.md.')];
    }
}

final class ScreenshotRule extends AbstractRule
{
    public function id(): string
    {
        return 'release.screenshots';
    }

    public function title(): string
    {
        return 'Include Control-Panel screenshots for an addon with a CP surface';
    }

    public function category(): string
    {
        return 'release';
    }

    public function severity(): string
    {
        return Severity::MINOR;
    }

    public function rationale(): string
    {
        return 'The Marketplace listing is browsed visually. A CP addon with no screenshot converts poorly '
            .'and gives the reviewer nothing to judge the UI against.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->hasCpSurface();
    }

    public function check(AddonContext $addon): array
    {
        $images = array_filter(
            $addon->files(),
            fn (string $f) => preg_match('/\.(png|jpg|jpeg|gif|webp)$/i', $f) === 1
                && ! str_contains($f, 'dist/')
                && ! str_contains($f, 'tests/')
        );

        if ($images !== []) {
            return [];
        }

        return [$this->fail('The addon has a CP surface but ships no screenshots.')];
    }
}

final class VersioningRule extends AbstractRule
{
    public function id(): string
    {
        return 'release.versioning';
    }

    public function title(): string
    {
        return 'Tag releases with semver';
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
        return 'Composer resolves an addon by its tags. An untagged repository can only be installed from a '
            .'branch, which no Marketplace customer will do.';
    }

    public function check(AddonContext $addon): array
    {
        if (! is_dir($addon->abs('.git'))) {
            return [$this->failWith(Severity::INFO, 'Not a git repository; cannot inspect tags.')];
        }

        $output = [];
        $status = 0;
        exec(sprintf('git -C %s tag --list 2>/dev/null', escapeshellarg($addon->root)), $output, $status);

        $semver = array_filter($output, fn (string $t) => preg_match('/^v?\d+\.\d+\.\d+/', $t) === 1);

        if ($semver === []) {
            return [$this->fail('No semver tags in the repository.', null, null, 'Tag the first release, e.g. v1.0.0.')];
        }

        return [];
    }
}

final class MarketplaceEditionRule extends AbstractRule
{
    public function id(): string
    {
        return 'release.editions';
    }

    public function title(): string
    {
        return 'Declare editions when the addon is sold with tiers';
    }

    public function category(): string
    {
        return 'release';
    }

    public function severity(): string
    {
        return Severity::INFO;
    }

    public function rationale(): string
    {
        return 'Advanced SEO and Bard Texstyle both use `extra.statamic.editions` to gate features. If an '
            .'addon has a paid tier, the edition must be declared or licensing cannot be enforced.';
    }

    public function check(AddonContext $addon): array
    {
        $license = $addon->composerValue('license');
        $editions = $addon->composerValue('extra.statamic.editions');

        if ($license !== 'proprietary' || $editions !== null) {
            return [];
        }

        return [$this->fail(
            'License is proprietary but no `extra.statamic.editions` are declared.',
            'composer.json',
            null,
            'Confirm this is a single-edition commercial addon; otherwise declare the editions.'
        )];
    }
}
