<?php

declare(strict_types=1);

namespace StatamicAddonStudio\Lint;

final class Reporter
{
    private const COLORS = [
        Severity::BLOCKER => "\033[41;97m",
        Severity::MAJOR => "\033[31m",
        Severity::MINOR => "\033[33m",
        Severity::INFO => "\033[36m",
    ];

    private const LABELS = [
        Severity::BLOCKER => 'BLOCKER',
        Severity::MAJOR => 'MAJOR  ',
        Severity::MINOR => 'MINOR  ',
        Severity::INFO => 'INFO   ',
    ];

    public function __construct(private readonly bool $color = true)
    {
    }

    /** @param Report[] $reports */
    public function console(array $reports, Linter $linter, bool $verbose = false): string
    {
        $out = '';

        foreach ($reports as $report) {
            $counts = $report->counts();
            $out .= "\n".$this->paint("\033[1m", $report->addon->name())
                ."  ".$this->dim('score '.$report->score().'/100')
                ."  ".$this->dim($report->ruleCount - count($report->skipped).' rules run')."\n";
            $out .= $this->dim(str_repeat('─', 72))."\n";

            if ($report->findings === []) {
                $out .= "  ".$this->paint("\033[32m", '✓ clean')."\n";

                continue;
            }

            foreach ($report->byCategory($linter) as $category => $findings) {
                $out .= "\n  ".$this->paint("\033[1m", strtoupper($category))."\n";

                foreach ($findings as $finding) {
                    $rule = $linter->rules()[$finding->ruleId] ?? null;
                    $out .= '    '.$this->severityBadge($finding->severity)
                        .' '.$this->dim($finding->ruleId)."\n";
                    $out .= '      '.$finding->message."\n";

                    if ($finding->location() !== '') {
                        $out .= '      '.$this->dim('at '.$finding->location())."\n";
                    }

                    if ($finding->hint !== null) {
                        $out .= '      '.$this->dim('→ '.$finding->hint)."\n";
                    }

                    if ($verbose && $rule?->rationale()) {
                        $out .= '      '.$this->dim('why: '.$rule->rationale())."\n";
                    }
                }
            }

            $out .= "\n  ".$this->summaryLine($counts)."\n";
        }

        return $out."\n";
    }

    /** @param Report[] $reports */
    public function json(array $reports): string
    {
        return json_encode(
            ['addons' => array_map(fn (Report $r) => $r->toArray(), $reports)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        )."\n";
    }

    /** @param Report[] $reports */
    public function markdown(array $reports, Linter $linter): string
    {
        $out = "# Addon lint report\n\n";
        $out .= "| Addon | Score | Blocker | Major | Minor | Info |\n|---|---:|---:|---:|---:|---:|\n";

        foreach ($reports as $report) {
            $c = $report->counts();
            $out .= sprintf(
                "| `%s` | %d | %d | %d | %d | %d |\n",
                $report->addon->name(),
                $report->score(),
                $c[Severity::BLOCKER],
                $c[Severity::MAJOR],
                $c[Severity::MINOR],
                $c[Severity::INFO]
            );
        }

        foreach ($reports as $report) {
            $out .= "\n## ".$report->addon->name()."\n";

            if ($report->findings === []) {
                $out .= "\nClean.\n";

                continue;
            }

            foreach ($report->byCategory($linter) as $category => $findings) {
                $out .= "\n### ".ucfirst($category)."\n\n";

                foreach ($findings as $finding) {
                    $out .= sprintf(
                        "- **%s** `%s` — %s%s%s\n",
                        strtoupper($finding->severity),
                        $finding->ruleId,
                        $finding->message,
                        $finding->location() !== '' ? ' (`'.$finding->location().'`)' : '',
                        $finding->hint !== null ? '  \n  → '.$finding->hint : ''
                    );
                }
            }
        }

        return $out;
    }

    private function summaryLine(array $counts): string
    {
        $parts = [];

        foreach ([Severity::BLOCKER, Severity::MAJOR, Severity::MINOR, Severity::INFO] as $severity) {
            if (($counts[$severity] ?? 0) > 0) {
                $parts[] = $this->paint(self::COLORS[$severity], $counts[$severity].' '.$severity);
            }
        }

        return $parts === [] ? $this->paint("\033[32m", 'clean') : implode($this->dim(' · '), $parts);
    }

    private function severityBadge(string $severity): string
    {
        return $this->paint(self::COLORS[$severity] ?? '', self::LABELS[$severity] ?? $severity);
    }

    private function paint(string $code, string $text): string
    {
        return $this->color && $code !== '' ? $code.$text."\033[0m" : $text;
    }

    private function dim(string $text): string
    {
        return $this->paint("\033[90m", $text);
    }
}
