<?php

namespace App\Http\Controllers;

use App\Models\Roster;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Rosters\ShiftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;

class ShiftController extends Controller
{
    public function store(Request $request, Roster $roster, ShiftService $shiftService): RedirectResponse
    {
        $this->ensurePdlCanAccessLocation($request, $roster->location_id);

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'shift_template_id' => ['required', 'exists:shift_templates,id'],
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $employee = User::query()->findOrFail($validated['user_id']);
        $shiftTemplate = ShiftTemplate::query()->findOrFail($validated['shift_template_id']);
        $this->ensureEmployeeBelongsToRoster($employee, $roster);
        $this->ensureShiftTemplateBelongsToRoster($shiftTemplate, $roster);

        $shiftService->assignManualShift(
            $roster,
            $employee,
            $shiftTemplate,
            $validated['date'],
            $validated['note'] ?? null,
        );

        return back()->with('status', 'shift-created');
    }

    public function update(Request $request, Roster $roster, Shift $shift, ShiftService $shiftService): RedirectResponse
    {
        $this->ensurePdlCanAccessLocation($request, $roster->location_id);

        abort_unless($shift->roster_id === $roster->id, HttpResponse::HTTP_NOT_FOUND);

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'shift_template_id' => ['required', 'exists:shift_templates,id'],
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $employee = User::query()->findOrFail($validated['user_id']);
        $shiftTemplate = ShiftTemplate::query()->findOrFail($validated['shift_template_id']);
        $this->ensureEmployeeBelongsToRoster($employee, $roster);
        $this->ensureShiftTemplateBelongsToRoster($shiftTemplate, $roster);

        $shiftService->updateManualShift(
            $shift,
            $employee,
            $shiftTemplate,
            $validated['date'],
            $validated['note'] ?? null,
        );

        return back()->with('status', 'shift-updated');
    }

    public function destroy(Request $request, Roster $roster, Shift $shift): RedirectResponse
    {
        $this->ensurePdlCanAccessLocation($request, $roster->location_id);

        abort_unless($shift->roster_id === $roster->id, HttpResponse::HTTP_NOT_FOUND);

        if (! $roster->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Nur bearbeitbare Dienstpläne können geändert werden.',
            ]);
        }

        $shift->delete();

        return back()->with('status', 'shift-deleted');
    }

    private function ensurePdlCanAccessLocation(Request $request, string $locationId): void
    {
        $user = $request->user();

        abort_unless(
            $user?->hasRole('PDL') && $user->location_id === $locationId,
            HttpResponse::HTTP_FORBIDDEN,
        );
    }

    private function ensureEmployeeBelongsToRoster(User $employee, Roster $roster): void
    {
        if ($employee->location_id !== $roster->location_id) {
            throw ValidationException::withMessages([
                'user_id' => 'Der Mitarbeiter gehört nicht zum Wohnbereich dieses Dienstplans.',
            ]);
        }
    }

    private function ensureShiftTemplateBelongsToRoster(ShiftTemplate $shiftTemplate, Roster $roster): void
    {
        if ($shiftTemplate->location_id !== $roster->location_id) {
            throw ValidationException::withMessages([
                'shift_template_id' => 'Die Schichtvorlage gehört nicht zum Wohnbereich dieses Dienstplans.',
            ]);
        }
    }
}
