<?php

namespace App\Services\Rosters;

use App\Enums\AbsenceRequestStatus;
use App\Enums\AbsenceRequestType;
use App\Enums\EmploymentArea;
use App\Enums\RosterStatus;
use App\Models\AbsenceRequest;
use App\Models\EmployeeProfile;
use App\Models\Location;
use App\Models\Roster;
use App\Models\ShiftStaffingRule;
use App\Models\ShiftTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;

class RosterDemoDataSeeder
{
    private const LOCATION_NAME = 'Wohnbereich A';

    private const PDL_EMAIL = 'demo.pdl.dienstplan@pflegedex.local';

    private const PASSWORD = 'password';

    /**
     * @return array<string, int|string>
     */
    public function seed(string $month): array
    {
        $monthDate = $this->parseMonth($month);
        $location = $this->createLocation();
        $pdl = $this->createPdl($location);
        $employees = $this->createEmployees($location);
        $absenceRequestsCount = $this->createAbsenceRequests($location, $pdl, $employees, $monthDate);
        $shiftTemplates = $this->createShiftTemplates($location);
        $staffingRulesCount = $this->createStaffingRules($location, $shiftTemplates);
        $roster = $this->createRoster($location, $pdl, $monthDate);

        return [
            'locationName' => $location->name,
            'month' => $monthDate->format('Y-m'),
            'pdlEmail' => self::PDL_EMAIL,
            'pdlPassword' => self::PASSWORD,
            'employeesCount' => $employees->count(),
            'shiftTemplatesCount' => $shiftTemplates->count(),
            'staffingRulesCount' => $staffingRulesCount,
            'absenceRequestsCount' => $absenceRequestsCount,
            'rosterId' => $roster->id,
            'rosterStatus' => $roster->status->label(),
        ];
    }

    private function parseMonth(string $month): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new InvalidArgumentException('Der Monat muss im Format YYYY-MM angegeben werden.');
        }

        [$year, $monthNumber] = array_map('intval', explode('-', $month));

        if ($monthNumber < 1 || $monthNumber > 12) {
            throw new InvalidArgumentException('Der Monat muss zwischen 01 und 12 liegen.');
        }

        return CarbonImmutable::create($year, $monthNumber, 1)->startOfDay();
    }

    private function createLocation(): Location
    {
        return Location::firstOrCreate(
            ['name' => self::LOCATION_NAME],
            [
                'short_name' => 'A',
                'description' => 'Demo-Wohnbereich für Dienstplanung und Urlaubsplanung.',
                'active' => true,
            ],
        );
    }

    private function createPdl(Location $location): User
    {
        Role::findOrCreate('PDL', 'web');

        $pdl = User::firstOrNew(['email' => self::PDL_EMAIL]);
        $pdl->forceFill([
            'location_id' => $location->id,
            'name' => 'Demo PDL Dienstplan',
            'password' => self::PASSWORD,
            'email_verified_at' => now(),
        ])->save();

        $pdl->syncRoles(['PDL']);
        $pdl->locations()->syncWithoutDetaching([$location->id]);

        return $pdl;
    }

    /**
     * @return Collection<int, User>
     */
    private function createEmployees(Location $location): Collection
    {
        Role::findOrCreate('Pflegekraft', 'web');

        $profiles = [
            ['specialist' => true, 'hours' => 39, 'days' => 5, 'night' => true, 'vacation' => 30, 'carried' => 2, 'overtime' => 120],
            ['specialist' => true, 'hours' => 39, 'days' => 5, 'night' => true, 'vacation' => 30, 'carried' => 0, 'overtime' => -60],
            ['specialist' => true, 'hours' => 30, 'days' => 4, 'night' => false, 'vacation' => 28, 'carried' => 1, 'overtime' => 30],
            ['specialist' => true, 'hours' => 20, 'days' => 3, 'night' => false, 'vacation' => 26, 'carried' => 0, 'overtime' => 0],
            ['specialist' => true, 'hours' => 40, 'days' => 5, 'night' => true, 'vacation' => 30, 'carried' => 3, 'overtime' => 90],
            ['specialist' => false, 'hours' => 39, 'days' => 5, 'night' => false, 'vacation' => 30, 'carried' => 0, 'overtime' => 45],
            ['specialist' => false, 'hours' => 30, 'days' => 4, 'night' => false, 'vacation' => 28, 'carried' => 0, 'overtime' => -30],
            ['specialist' => false, 'hours' => 20, 'days' => 3, 'night' => false, 'vacation' => 26, 'carried' => 0, 'overtime' => 0],
            ['specialist' => false, 'hours' => 39, 'days' => 5, 'night' => true, 'vacation' => 30, 'carried' => 1, 'overtime' => 60],
            ['specialist' => false, 'hours' => 30, 'days' => 4, 'night' => false, 'vacation' => 28, 'carried' => 2, 'overtime' => 15],
            ['specialist' => true, 'hours' => 30, 'days' => 4, 'night' => true, 'vacation' => 29, 'carried' => 0, 'overtime' => -15],
            ['specialist' => false, 'hours' => 20, 'days' => 3, 'night' => false, 'vacation' => 26, 'carried' => 0, 'overtime' => 0],
        ];

        return collect($profiles)->map(function (array $profile, int $index) use ($location): User {
            $number = $index + 1;
            $employee = User::firstOrNew(['email' => sprintf('demo.pflege.%02d@pflegedex.local', $number)]);
            $employee->forceFill([
                'location_id' => $location->id,
                'name' => sprintf('Demo Pflege %02d', $number),
                'password' => self::PASSWORD,
                'email_verified_at' => now(),
            ])->save();

            $employee->syncRoles(['Pflegekraft']);
            $employee->locations()->syncWithoutDetaching([$location->id]);

            EmployeeProfile::updateOrCreate(
                ['user_id' => $employee->id],
                [
                    'employment_area' => EmploymentArea::Nursing,
                    'is_nursing_specialist' => $profile['specialist'],
                    'weekly_hours' => $profile['hours'],
                    'regular_work_days_per_week' => $profile['days'],
                    'annual_vacation_days' => $profile['vacation'],
                    'vacation_days_carried_over' => $profile['carried'],
                    'overtime_minutes_balance' => $profile['overtime'],
                    'can_work_early' => true,
                    'can_work_late' => true,
                    'can_work_night' => $profile['night'],
                    'active' => true,
                ],
            );

            return $employee;
        });
    }

    /**
     * @param  Collection<int, User>  $employees
     */
    private function createAbsenceRequests(Location $location, User $pdl, Collection $employees, CarbonImmutable $monthDate): int
    {
        $absences = [
            ['employeeNumber' => 1, 'startDay' => 8, 'endDay' => 12, 'note' => 'Demo-Urlaub Januar'],
            ['employeeNumber' => 5, 'startDay' => 20, 'endDay' => 22, 'note' => 'Demo-Urlaub kurz'],
            ['employeeNumber' => 8, 'startDay' => 26, 'endDay' => 26, 'note' => 'Demo einzelner freier Tag'],
        ];

        foreach ($absences as $absence) {
            $employee = $employees->get($absence['employeeNumber'] - 1);

            if (! $employee instanceof User) {
                throw new InvalidArgumentException(sprintf('Demo-Pflegekraft %02d existiert nicht.', $absence['employeeNumber']));
            }

            $startsOn = $monthDate->setDay($absence['startDay']);
            $endsOn = $monthDate->setDay($absence['endDay']);

            AbsenceRequest::updateOrCreate(
                [
                    'user_id' => $employee->id,
                    'type' => AbsenceRequestType::Vacation,
                    'starts_on' => $startsOn->toDateString(),
                    'ends_on' => $endsOn->toDateString(),
                ],
                [
                    'location_id' => $location->id,
                    'days_count' => $startsOn->diffInDays($endsOn) + 1,
                    'status' => AbsenceRequestStatus::Approved,
                    'requested_by' => $pdl->id,
                    'decided_by' => $pdl->id,
                    'decided_at' => now(),
                    'rejection_reason' => null,
                    'note' => $absence['note'],
                ],
            );
        }

        return count($absences);
    }

    /**
     * @return Collection<int, ShiftTemplate>
     */
    private function createShiftTemplates(Location $location): Collection
    {
        $templates = [
            ['code' => 'F', 'name' => 'Frühdienst', 'starts_at' => '06:00', 'ends_at' => '14:00', 'color' => '#F59E0B'],
            ['code' => 'S', 'name' => 'Spätdienst', 'starts_at' => '14:00', 'ends_at' => '22:00', 'color' => '#3B82F6'],
            ['code' => 'N', 'name' => 'Nachtdienst', 'starts_at' => '22:00', 'ends_at' => '06:00', 'color' => '#6366F1'],
        ];

        return collect($templates)->map(fn (array $template): ShiftTemplate => ShiftTemplate::updateOrCreate(
            [
                'location_id' => $location->id,
                'code' => $template['code'],
            ],
            [
                'name' => $template['name'],
                'starts_at' => $template['starts_at'],
                'ends_at' => $template['ends_at'],
                'duration_minutes' => 480,
                'color' => $template['color'],
                'active' => true,
            ],
        ));
    }

    /**
     * @param  Collection<int, ShiftTemplate>  $shiftTemplates
     */
    private function createStaffingRules(Location $location, Collection $shiftTemplates): int
    {
        $requirements = [
            'F' => ['total' => 2, 'specialists' => 1],
            'S' => ['total' => 2, 'specialists' => 1],
            'N' => ['total' => 1, 'specialists' => 1],
        ];

        foreach ($shiftTemplates as $shiftTemplate) {
            $requirement = $requirements[$shiftTemplate->code];
            $rule = ShiftStaffingRule::query()
                ->where('location_id', $location->id)
                ->where('shift_template_id', $shiftTemplate->id)
                ->whereNull('weekday')
                ->first();

            if ($rule === null) {
                $rule = new ShiftStaffingRule([
                    'shift_template_id' => $shiftTemplate->id,
                    'weekday' => null,
                ]);
            }

            $rule->forceFill([
                'location_id' => $location->id,
                'required_total_staff' => $requirement['total'],
                'required_specialists' => $requirement['specialists'],
            ])->save();
        }

        return count($requirements);
    }

    private function createRoster(Location $location, User $pdl, CarbonImmutable $monthDate): Roster
    {
        return Roster::firstOrCreate(
            [
                'location_id' => $location->id,
                'year' => $monthDate->year,
                'month' => $monthDate->month,
            ],
            [
                'status' => RosterStatus::Draft,
                'generated_at' => null,
                'published_at' => null,
                'created_by' => $pdl->id,
            ],
        );
    }
}
