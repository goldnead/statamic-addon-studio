<?php

declare(strict_types=1);

namespace StatamicAddonStudio\Lint;

abstract class AbstractRule implements Rule
{
    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return '';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return true;
    }

    protected function fail(string $message, ?string $file = null, ?int $line = null, ?string $hint = null): Finding
    {
        return new Finding($this->id(), $this->severity(), $message, $file, $line, $hint);
    }

    protected function failWith(string $severity, string $message, ?string $file = null, ?int $line = null, ?string $hint = null): Finding
    {
        return new Finding($this->id(), $severity, $message, $file, $line, $hint);
    }
}
