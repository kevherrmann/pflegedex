<?php

namespace App\Services\Rosters;

use App\Models\Roster;
use App\Models\ShiftTemplate;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;

class PdlRosterAccess
{
    public function ensurePdlHasLocation(Request $request): string
    {
        $user = $request->user();

        abort_unless(
            $user?->hasRole('PDL') && $user->location_id !== null,
            HttpResponse::HTTP_FORBIDDEN,
        );

        return $user->location_id;
    }

    public function ensurePdlCanAccessLocation(Request $request, string $locationId): void
    {
        abort_unless(
            $this->ensurePdlHasLocation($request) === $locationId,
            HttpResponse::HTTP_FORBIDDEN,
        );
    }

    public function ensureEmployeeBelongsToRoster(User $employee, Roster $roster): void
    {
        if ($employee->location_id !== $roster->location_id) {
            throw ValidationException::withMessages([
                'user_id' => 'Der Mitarbeiter gehört nicht zum Wohnbereich dieses Dienstplans.',
            ]);
        }
    }

    public function ensureShiftTemplateBelongsToRoster(ShiftTemplate $shiftTemplate, Roster $roster): void
    {
        if ($shiftTemplate->location_id !== $roster->location_id) {
            throw ValidationException::withMessages([
                'shift_template_id' => 'Die Schichtvorlage gehört nicht zum Wohnbereich dieses Dienstplans.',
            ]);
        }
    }
}
