<?php

declare(strict_types=1);

namespace StatamicAddonStudio\Lint;

final class Report
{
    /**
     * @param  Finding[]  $findings
     * @param  array<string, string>  $skipped
     */
    public function __construct(
        public readonly AddonContext $addon,
        public readonly array $findings,
        public readonly array $skipped = [],
        public readonly int $ruleCount = 0,
    ) {
    }

    /** @return Finding[] */
    public function ofSeverity(string $severity): array
    {
        return array_values(array_filter($this->findings, fn (Finding $f) => $f->severity === $severity));
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $counts = array_fill_keys(array_keys(Severity::ORDER), 0);

        foreach ($this->findings as $finding) {
            $counts[$finding->severity] = ($counts[$finding->severity] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return array<string, Finding[]> */
    public function byCategory(Linter $linter): array
    {
        $grouped = [];

        foreach ($this->findings as $finding) {
            $rule = $linter->rules()[$finding->ruleId] ?? null;
            $grouped[$rule?->category() ?? 'other'][] = $finding;
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * 0–100. Blockers cost 12, majors 5, minors 1.5, info 0.
     * Deliberately blunt: the number is a trend indicator, the findings are the truth.
     */
    public function score(): int
    {
        $counts = $this->counts();

        $penalty = $counts[Severity::BLOCKER] * 12
            + $counts[Severity::MAJOR] * 5
            + $counts[Severity::MINOR] * 1.5;

        return (int) max(0, round(100 - $penalty));
    }

    public function failsAt(string $threshold): bool
    {
        foreach ($this->findings as $finding) {
            if (Severity::atLeast($finding->severity, $threshold)) {
                return true;
            }
        }

        return false;
    }

    public function toArray(): array
    {
        return [
            'addon' => $this->addon->name(),
            'root' => $this->addon->root,
            'score' => $this->score(),
            'rules_run' => $this->ruleCount - count($this->skipped),
            'rules_total' => $this->ruleCount,
            'counts' => $this->counts(),
            'findings' => array_map(fn (Finding $f) => $f->toArray(), $this->findings),
            'skipped' => $this->skipped,
        ];
    }
}
