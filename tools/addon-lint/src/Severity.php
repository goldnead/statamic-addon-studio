<?php

declare(strict_types=1);

namespace StatamicAddonStudio\Lint;

final class Severity
{
    /** Ships broken behaviour or blocks a marketplace release. */
    public const BLOCKER = 'blocker';

    /** Visibly non-native or a real maintenance hazard. Fix before release. */
    public const MAJOR = 'major';

    /** Polish. Fix when touching the area. */
    public const MINOR = 'minor';

    /** Informational; surfaced so a human can judge. */
    public const INFO = 'info';

    public const ORDER = [
        self::BLOCKER => 0,
        self::MAJOR => 1,
        self::MINOR => 2,
        self::INFO => 3,
    ];

    public static function rank(string $severity): int
    {
        return self::ORDER[$severity] ?? 99;
    }

    public static function atLeast(string $severity, string $threshold): bool
    {
        return self::rank($severity) <= self::rank($threshold);
    }
}
