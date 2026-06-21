<?php

namespace App\Services\Rosters;

use App\Models\Location;
use App\Models\ShiftCategoryStaffingRule;
use App\Models\ShiftTemplate;

class DefaultShiftSetupService
{
    /**
     * @var array<int, array{
     *     name: string,
     *     code: string,
     *     starts_at: string,
     *     ends_at: string,
     *     duration_minutes: int,
     *     color: string,
     *     required_total_staff: int,
     *     required_specialists: int
     * }>
     */
    private const DEFAULT_SHIFTS = [
        [
            'name' => 'Frühdienst',
            'code' => 'early',
            'starts_at' => '06:00',
            'ends_at' => '14:00',
            'duration_minutes' => 480,
            'color' => '#F59E0B',
            'required_total_staff' => 5,
            'required_specialists' => 1,
        ],
        [
            'name' => 'Spätdienst',
            'code' => 'late',
            'starts_at' => '14:00',
            'ends_at' => '22:00',
            'duration_minutes' => 480,
            'color' => '#3B82F6',
            'required_total_staff' => 3,
            'required_specialists' => 1,
        ],
        [
            'name' => 'Nachtdienst',
            'code' => 'night',
            'starts_at' => '22:00',
            'ends_at' => '06:00',
            'duration_minutes' => 480,
            'color' => '#6366F1',
            'required_total_staff' => 1,
            'required_specialists' => 1,
        ],
    ];

    public function createForLocation(Location $location): void
    {
        foreach (self::DEFAULT_SHIFTS as $defaultShift) {
            $shiftTemplate = ShiftTemplate::query()->updateOrCreate(
                [
                    'location_id' => $location->id,
                    'code' => $defaultShift['code'],
                ],
                [
                    'name' => $defaultShift['name'],
                    'category' => $defaultShift['code'],
                    'starts_at' => $defaultShift['starts_at'],
                    'ends_at' => $defaultShift['ends_at'],
                    'duration_minutes' => $defaultShift['duration_minutes'],
                    'color' => $defaultShift['color'],
                    'active' => true,
                ],
            );

            // Besetzung pro Kategorie (early/late/night).
            ShiftCategoryStaffingRule::query()->updateOrCreate(
                [
                    'location_id' => $location->id,
                    'category' => $defaultShift['code'],
                    'weekday' => null,
                ],
                [
                    'required_total_staff' => $defaultShift['required_total_staff'],
                    'required_specialists' => $defaultShift['required_specialists'],
                ],
            );
        }
    }
}
