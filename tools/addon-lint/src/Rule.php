<?php

declare(strict_types=1);

namespace StatamicAddonStudio\Lint;

interface Rule
{
    /** Stable identifier, e.g. "ui.no-custom-buttons". Never rename; deprecate instead. */
    public function id(): string;

    /** One line, imperative: what the addon should do. */
    public function title(): string;

    /** structure | bootstrap | ui | code | testing | release */
    public function category(): string;

    /** Default severity; may be overridden per addon in addon-lint.json. */
    public function severity(): string;

    /** Why this rule exists, referencing the standard it comes from. */
    public function rationale(): string;

    /**
     * Return zero or more findings. Never throw for a missing file — report instead.
     *
     * @return Finding[]
     */
    public function check(AddonContext $addon): array;

    /** True when the rule cannot say anything meaningful about this addon. */
    public function appliesTo(AddonContext $addon): bool;
}
