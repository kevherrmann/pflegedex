<?php

namespace App\Services\Rosters;

use App\Enums\RosterStatus;
use App\Models\Location;
use App\Models\Roster;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RosterService
{
    public function createOrGetDraft(Location $location, User $createdBy, int $year, int $month): Roster
    {
        $this->validateYear($year);
        $this->validateMonth($month);

        return Roster::query()->firstOrCreate(
            [
                'location_id' => $location->id,
                'year' => $year,
                'month' => $month,
            ],
            [
                'status' => RosterStatus::Draft,
                'created_by' => $createdBy->id,
            ],
        );
    }

    public function publish(Roster $roster): Roster
    {
        if (! $roster->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Nur bearbeitbare Dienstpläne können veröffentlicht werden.',
            ]);
        }

        $roster->forceFill([
            'status' => RosterStatus::Published,
            'published_at' => now(),
        ])->save();

        return $roster->refresh();
    }

    public function lock(Roster $roster): Roster
    {
        if ($roster->status !== RosterStatus::Published) {
            throw ValidationException::withMessages([
                'status' => 'Nur veröffentlichte Dienstpläne können gesperrt werden.',
            ]);
        }

        $roster->forceFill([
            'status' => RosterStatus::Locked,
        ])->save();

        return $roster->refresh();
    }

    public function reopen(Roster $roster): Roster
    {
        if ($roster->status !== RosterStatus::Published) {
            throw ValidationException::withMessages([
                'status' => 'Nur veröffentlichte Dienstpläne können wieder geöffnet werden.',
            ]);
        }

        $roster->forceFill([
            'status' => RosterStatus::Reviewed,
            'published_at' => null,
        ])->save();

        return $roster->refresh();
    }

    private function validateYear(int $year): void
    {
        if ($year < 2020 || $year > 2100) {
            throw ValidationException::withMessages([
                'year' => 'Das Jahr muss zwischen 2020 und 2100 liegen.',
            ]);
        }
    }

    private function validateMonth(int $month): void
    {
        if ($month < 1 || $month > 12) {
            throw ValidationException::withMessages([
                'month' => 'Der Monat muss zwischen 1 und 12 liegen.',
            ]);
        }
    }
}
