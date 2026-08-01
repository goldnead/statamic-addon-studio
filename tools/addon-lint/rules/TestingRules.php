<?php

declare(strict_types=1);

namespace StatamicAddonStudio\Lint\Rules;

use StatamicAddonStudio\Lint\AbstractRule;
use StatamicAddonStudio\Lint\AddonContext;
use StatamicAddonStudio\Lint\Severity;

final class TestSuiteRule extends AbstractRule
{
    public function id(): string
    {
        return 'testing.suite';
    }

    public function title(): string
    {
        return 'Ship a runnable test suite with a phpunit configuration';
    }

    public function category(): string
    {
        return 'testing';
    }

    public function severity(): string
    {
        return Severity::BLOCKER;
    }

    public function rationale(): string
    {
        return 'An addon sold on the Marketplace promises reliability. Without a suite there is no way to '
            .'prove the promised behaviour still works after a Statamic patch release.';
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        if (! $addon->has('phpunit.xml') && ! $addon->has('phpunit.xml.dist')) {
            $findings[] = $this->fail('No phpunit.xml or phpunit.xml.dist.');
        }

        $tests = array_filter(
            $addon->phpFiles(),
            fn (string $f) => str_starts_with($f, 'tests/') && ! str_ends_with($f, 'TestCase.php')
        );

        if ($tests === []) {
            $findings[] = $this->fail('No test files under tests/.');
        }

        return $findings;
    }
}

final class AddonTestCaseRule extends AbstractRule
{
    public function id(): string
    {
        return 'testing.addon-testcase';
    }

    public function title(): string
    {
        return 'Boot tests through `Statamic\Testing\AddonTestCase`';
    }

    public function category(): string
    {
        return 'testing';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'Core ships an AddonTestCase that wires Testbench, the addon provider and Statamic\'s own '
            .'service providers in the right order. Hand-rolled Testbench setups drift with every Statamic major.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return array_filter($addon->phpFiles(), fn (string $f) => str_starts_with($f, 'tests/')) !== [];
    }

    public function check(AddonContext $addon): array
    {
        $testFiles = array_values(array_filter($addon->phpFiles(), fn (string $f) => str_starts_with($f, 'tests/')));

        if ($addon->contains('/Statamic\\\\Testing\\\\AddonTestCase|extends\s+AddonTestCase/', $testFiles)) {
            $findings = [];

            if (! $addon->contains('/\$addonServiceProvider/', $testFiles)) {
                $findings[] = $this->failWith(
                    Severity::MINOR,
                    'AddonTestCase is used but `$addonServiceProvider` is not set — the addon may not be booted in tests.',
                    'tests/TestCase.php'
                );
            }

            return $findings;
        }

        return [$this->fail(
            'Tests do not extend Statamic\'s AddonTestCase.',
            'tests/TestCase.php',
            null,
            'class TestCase extends \Statamic\Testing\AddonTestCase { protected string $addonServiceProvider = ServiceProvider::class; }'
        )];
    }
}

final class StacheIsolationRule extends AbstractRule
{
    public function id(): string
    {
        return 'testing.stache-isolation';
    }

    public function title(): string
    {
        return 'Prevent tests from writing Stache items to disk';
    }

    public function category(): string
    {
        return 'testing';
    }

    public function severity(): string
    {
        return Severity::MINOR;
    }

    public function rationale(): string
    {
        return 'Without `PreventsSavingStacheItemsToDisk` a test run leaves content files behind, which makes '
            .'the next run pass or fail depending on the previous one. The trait gained its `s` before '
            .'Statamic 6; both spellings are accepted so the rule still reads a v5-era suite correctly.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        $tests = array_filter($addon->phpFiles(), fn (string $f) => str_starts_with($f, 'tests/'));

        return $tests !== [] && $addon->contains('/Statamic\\\\Facades\\\\(Entry|Collection|Term|Taxonomy|GlobalSet)/', array_values($tests));
    }

    public function check(AddonContext $addon): array
    {
        $tests = array_values(array_filter($addon->phpFiles(), fn (string $f) => str_starts_with($f, 'tests/')));

        if ($addon->contains('/Prevents?SavingStacheItemsToDisk/', $tests)) {
            return [];
        }

        return [$this->fail(
            'Tests create Stache-backed content but never use PreventsSavingStacheItemsToDisk.',
            'tests/TestCase.php'
        )];
    }
}

final class CiWorkflowRule extends AbstractRule
{
    public function id(): string
    {
        return 'testing.ci';
    }

    public function title(): string
    {
        return 'Run the suite in CI across the supported PHP and Laravel matrix';
    }

    public function category(): string
    {
        return 'testing';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'The composer constraints promise a support range. CI is the only thing that proves the '
            .'promise holds at both ends of it.';
    }

    public function check(AddonContext $addon): array
    {
        $workflows = $addon->match('.github/workflows/*');

        if ($workflows === []) {
            return [$this->fail('No GitHub Actions workflows.', null, null, 'Add .github/workflows/tests.yml.')];
        }

        $runsTests = $addon->contains('/phpunit|pest|composer\s+test|vendor\/bin\/(phpunit|pest)/i', $workflows);

        if (! $runsTests) {
            return [$this->fail('No workflow runs the test suite.', $workflows[0])];
        }

        $findings = [];

        if (! $addon->contains('/matrix\s*:/', $workflows)) {
            $findings[] = $this->failWith(
                Severity::MINOR,
                'CI runs a single combination; the composer constraints promise a range.',
                $workflows[0]
            );
        }

        return $findings;
    }
}

final class DistBuildVerificationRule extends AbstractRule
{
    public function id(): string
    {
        return 'testing.dist-verification';
    }

    public function title(): string
    {
        return 'Verify committed build output in CI';
    }

    public function category(): string
    {
        return 'testing';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'When dist/ is committed and nothing rebuilds it in CI, a source change can ship green while '
            .'the bundle users actually load is stale. This exact gap exists in the reference set.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return array_filter($addon->files(), fn (string $f) => str_contains($f, 'dist/build/')) !== [];
    }

    public function check(AddonContext $addon): array
    {
        $workflows = $addon->match('.github/workflows/*');

        if ($workflows !== [] && $addon->contains('/npm\s+(ci|run\s+build)|vite\s+build/', $workflows)) {
            return [];
        }

        return [$this->fail(
            'dist/ is committed but no workflow rebuilds and diffs it.',
            $workflows[0] ?? null,
            null,
            'Add a CI step: npm ci && npm run build && git diff --exit-code dist'
        )];
    }
}
