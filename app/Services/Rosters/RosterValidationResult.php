<?php

namespace App\Services\Rosters;

class RosterValidationResult
{
    public function __construct(
        public array $errors = [],
        public array $warnings = [],
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    public function isGreen(): bool
    {
        return ! $this->hasErrors() && ! $this->hasWarnings();
    }

    public function isYellow(): bool
    {
        return ! $this->hasErrors() && $this->hasWarnings();
    }

    public function isRed(): bool
    {
        return $this->hasErrors();
    }

    public function addError(string $code, string $message, array $context = []): void
    {
        $this->errors[] = [
            'code' => $code,
            'message' => $message,
            'context' => $context,
        ];
    }

    public function addWarning(string $code, string $message, array $context = []): void
    {
        $this->warnings[] = [
            'code' => $code,
            'message' => $message,
            'context' => $context,
        ];
    }
}
