<?php

namespace App\Services\Rosters\Planning;

use App\Models\ShiftTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Eine geplante (noch nicht persistierte) Dienstzuweisung. Der Mitarbeiter
 * ist bewusst veränderlich, damit die lokale Suche Dienste umverteilen kann.
 */
class PlannedAssignment
{
    public function __construct(
        public User $employee,
        public readonly ShiftTemplate $shiftTemplate,
        public readonly CarbonImmutable $date,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
    ) {}

    public function minutes(): int
    {
        return (int) $this->startsAt->diffInMinutes($this->endsAt, true);
    }
}
