<?php

declare(strict_types=1);

namespace StatamicAddonStudio\Lint;

/**
 * Per-addon configuration, read from `addon-lint.json` in the addon root.
 *
 * {
 *   "disable": ["release.changelog"],
 *   "severity": { "ui.dark-mode": "minor" },
 *   "categories": ["ui", "code"]          // optional allow-list
 * }
 */
final class Config
{
    public function __construct(
        private readonly array $disabled = [],
        private readonly array $severities = [],
        private readonly ?array $categories = null,
    ) {
    }

    public static function forAddon(AddonContext $addon, ?array $categoryFilter = null): self
    {
        $raw = $addon->read('addon-lint.json');
        $data = $raw === null ? [] : (json_decode($raw, true) ?: []);

        return new self(
            disabled: array_map('strval', (array) ($data['disable'] ?? [])),
            severities: (array) ($data['severity'] ?? []),
            categories: $categoryFilter ?? (isset($data['categories']) ? (array) $data['categories'] : null),
        );
    }

    public function enabled(string $ruleId): bool
    {
        return ! in_array($ruleId, $this->disabled, true);
    }

    public function matchesCategory(string $category): bool
    {
        return $this->categories === null || in_array($category, $this->categories, true);
    }

    public function severityFor(string $ruleId, string $default): string
    {
        $override = $this->severities[$ruleId] ?? null;

        return is_string($override) && isset(Severity::ORDER[$override]) ? $override : $default;
    }
}
