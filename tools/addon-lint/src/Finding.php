<?php

declare(strict_types=1);

namespace StatamicAddonStudio\Lint;

final class Finding
{
    public function __construct(
        public readonly string $ruleId,
        public readonly string $severity,
        public readonly string $message,
        public readonly ?string $file = null,
        public readonly ?int $line = null,
        public readonly ?string $hint = null,
    ) {
    }

    public function location(): string
    {
        if ($this->file === null) {
            return '';
        }

        return $this->line === null ? $this->file : $this->file.':'.$this->line;
    }

    public function toArray(): array
    {
        return [
            'rule' => $this->ruleId,
            'severity' => $this->severity,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'hint' => $this->hint,
        ];
    }
}
