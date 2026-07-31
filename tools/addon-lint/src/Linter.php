<?php

declare(strict_types=1);

namespace StatamicAddonStudio\Lint;

final class Linter
{
    /** @var Rule[] */
    private array $rules = [];

    /** @param Rule[] $rules */
    public function __construct(array $rules = [])
    {
        foreach ($rules as $rule) {
            $this->add($rule);
        }
    }

    public function add(Rule $rule): void
    {
        $this->rules[$rule->id()] = $rule;
    }

    /** @return Rule[] */
    public function rules(): array
    {
        return $this->rules;
    }

    public function run(AddonContext $addon, Config $config): Report
    {
        $findings = [];
        $skipped = [];

        foreach ($this->rules as $rule) {
            if (! $config->enabled($rule->id())) {
                $skipped[$rule->id()] = 'disabled in addon-lint.json';

                continue;
            }

            if (! $config->matchesCategory($rule->category())) {
                $skipped[$rule->id()] = 'category filtered out';

                continue;
            }

            if (! $rule->appliesTo($addon)) {
                $skipped[$rule->id()] = 'not applicable to this addon';

                continue;
            }

            foreach ($rule->check($addon) as $finding) {
                $severity = $config->severityFor($rule->id(), $finding->severity);

                $findings[] = $severity === $finding->severity
                    ? $finding
                    : new Finding(
                        $finding->ruleId,
                        $severity,
                        $finding->message,
                        $finding->file,
                        $finding->line,
                        $finding->hint
                    );
            }
        }

        usort($findings, function (Finding $a, Finding $b) {
            return [Severity::rank($a->severity), $a->ruleId, (string) $a->file, (int) $a->line]
                <=> [Severity::rank($b->severity), $b->ruleId, (string) $b->file, (int) $b->line];
        });

        return new Report($addon, $findings, $skipped, count($this->rules));
    }
}
