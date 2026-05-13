<?php

namespace App\Services\Absences;

use App\Enums\AbsenceRequestStatus;
use App\Enums\AbsenceRequestType;
use App\Models\User;

class VacationBalanceService
{
    /**
     * @return array{
     *     annualVacationDays: string,
     *     vacationDaysCarriedOver: string,
     *     totalVacationDays: string,
     *     approvedVacationDays: string,
     *     requestedVacationDays: string,
     *     remainingVacationDays: string,
     *     availableVacationDays: string
     * }
     */
    public function forUser(User $user): array
    {
        $user->loadMissing('employeeProfile');

        $annualVacationDays = (float) ($user->employeeProfile?->annual_vacation_days ?? 0);
        $vacationDaysCarriedOver = (float) ($user->employeeProfile?->vacation_days_carried_over ?? 0);

        $approvedVacationDays = (float) $user->absenceRequests()
            ->where('type', AbsenceRequestType::Vacation->value)
            ->where('status', AbsenceRequestStatus::Approved->value)
            ->sum('days_count');

        $requestedVacationDays = (float) $user->absenceRequests()
            ->where('type', AbsenceRequestType::Vacation->value)
            ->where('status', AbsenceRequestStatus::Requested->value)
            ->sum('days_count');

        $totalVacationDays = $annualVacationDays + $vacationDaysCarriedOver;
        $remainingVacationDays = $totalVacationDays - $approvedVacationDays;
        $availableVacationDays = $remainingVacationDays - $requestedVacationDays;

        return [
            'annualVacationDays' => $this->formatDays($annualVacationDays),
            'vacationDaysCarriedOver' => $this->formatDays($vacationDaysCarriedOver),
            'totalVacationDays' => $this->formatDays($totalVacationDays),
            'approvedVacationDays' => $this->formatDays($approvedVacationDays),
            'requestedVacationDays' => $this->formatDays($requestedVacationDays),
            'remainingVacationDays' => $this->formatDays($remainingVacationDays),
            'availableVacationDays' => $this->formatDays($availableVacationDays),
        ];
    }

    private function formatDays(float $days): string
    {
        return number_format($days, 2, '.', '');
    }
}