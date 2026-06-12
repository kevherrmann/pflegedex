<?php

namespace App\Services\Rosters;

class RosterGenerationResult
{
    public int $createdShifts = 0;

    public int $deletedAutoShifts = 0;

    public array $skipped = [];

    public function addCreatedShift(): void
    {
        $this->createdShifts++;
    }

    public function addDeletedAutoShifts(int $count): void
    {
        $this->deletedAutoShifts += $count;
    }

    public array $warnings = [];

    public function addSkipped(string $code, string $message, array $context = []): void
    {
        $this->skipped[] = [
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

    public function hasSkipped(): bool
    {
        return $this->skipped !== [];
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }
}
