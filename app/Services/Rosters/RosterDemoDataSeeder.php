<?php

namespace App\Services\Rosters;

use App\Enums\AbsenceRequestStatus;
use App\Enums\AbsenceRequestType;
use App\Enums\EmploymentArea;
use App\Enums\QualificationLevel;
use App\Enums\RosterStatus;
use App\Models\AbsenceRequest;
use App\Models\EmployeeProfile;
use App\Models\Location;
use App\Models\Resident;
use App\Models\Roster;
use App\Models\ShiftCategoryStaffingRule;
use App\Models\ShiftTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;

/**
 * Erzeugt ein realistisches Demo-Pflegeheim fuer Dienstplan- und
 * Urlaubsplanung. Orientiert sich am Personalbemessungsverfahren (PeBeM nach
 * Paragraph 113c SGB XI): zwei Wohnbereiche mit je 20 Bewohnern und einem
 * Qualifikationsmix aus Pflegefachkraeften, Pflegeassistenten und
 * Pflegehilfskraeften, dazu Wohnbereichsleitungen, eine uebergreifende PDL
 * sowie Hauswirtschaft und Technik.
 */
class RosterDemoDataSeeder
{
    /** @var list<array{name: string, short: string, key: string}> */
    private const LOCATIONS = [
        ['name' => 'Wohnbereich A', 'short' => 'A', 'key' => 'a'],
        ['name' => 'Wohnbereich B', 'short' => 'B', 'key' => 'b'],
    ];

    private const PDL_EMAIL = 'demo.pdl.dienstplan@pflegedex.local';

    private const PASSWORD = 'password';

    private const RESIDENTS_PER_AREA = 20;

    /** Vornamen fuer Demo-Mitarbeiter (gemischt). */
    private const STAFF_FIRST_NAMES = [
        'Lena', 'Jonas', 'Sarah', 'Lukas', 'Marie', 'Felix', 'Anna', 'David',
        'Laura', 'Tim', 'Julia', 'Niklas', 'Katharina', 'Florian', 'Sabine',
        'Sebastian', 'Christine', 'Daniel', 'Petra', 'Markus', 'Andrea',
        'Stefan', 'Johanna', 'Andreas', 'Nina', 'Thomas', 'Claudia', 'Michael',
    ];

    private const STAFF_LAST_NAMES = [
        'Müller', 'Schmidt', 'Schneider', 'Fischer', 'Weber', 'Meyer', 'Wagner',
        'Becker', 'Schulz', 'Hoffmann', 'Schäfer', 'Koch', 'Bauer', 'Richter',
        'Klein', 'Wolf', 'Neumann', 'Schwarz', 'Braun', 'Krüger', 'Hofmann',
        'Hartmann', 'Lange', 'Werner', 'Krause', 'Lehmann', 'Köhler', 'Walter',
    ];

    /** Eher zeittypische Vornamen fuer Bewohner. */
    private const RESIDENT_FEMALE_NAMES = [
        'Erika', 'Helga', 'Ingrid', 'Ursula', 'Renate', 'Gisela', 'Edith',
        'Hannelore', 'Waltraud', 'Hildegard', 'Christa', 'Margarete', 'Else',
        'Gertrud', 'Irmgard', 'Elfriede', 'Brigitte', 'Inge', 'Käthe', 'Lieselotte',
    ];

    private const RESIDENT_MALE_NAMES = [
        'Karl', 'Hans', 'Werner', 'Gerhard', 'Heinz', 'Walter', 'Helmut',
        'Günter', 'Horst', 'Kurt', 'Wolfgang', 'Dieter', 'Manfred', 'Herbert',
        'Rudolf', 'Friedrich', 'Otto', 'Erwin', 'Wilhelm', 'Alfred',
    ];

    private const RESIDENT_LAST_NAMES = [
        'Albrecht', 'Brandt', 'Engel', 'Förster', 'Götz', 'Henkel', 'Jäger',
        'Kühn', 'Linde', 'Mertens', 'Naumann', 'Ostermann', 'Pfeiffer', 'Reuter',
        'Sander', 'Thiele', 'Ullrich', 'Voss', 'Winkler', 'Ziegler',
    ];

    /**
     * @return array<string, int|string>
     */
    public function seed(string $month): array
    {
        $monthDate = $this->parseMonth($month);
        $this->ensureRoles();

        $locations = $this->createLocations();
        $pdl = $this->createPdl($locations);

        $nursingStaff = collect();
        $cleaningStaff = collect();
        $residentsCount = 0;
        $shiftTemplatesCount = 0;
        $staffingRulesCount = 0;
        $absenceRequestsCount = 0;
        $rosters = collect();
        $nameOffset = 0;

        foreach (self::LOCATIONS as $index => $config) {
            $location = $locations[$index];

            $areaStaff = $this->createNursingStaff($location, $config['key'], $nameOffset);
            $nameOffset += $areaStaff->count();
            $nursingStaff = $nursingStaff->merge($areaStaff);

            $cleaningStaff = $cleaningStaff->merge(
                $this->createCleaningStaff($location, $config['key'], $nameOffset),
            );
            $nameOffset += 1;

            $residentsCount += $this->createResidents($location, $config['key'], $index);

            $absenceRequestsCount += $this->createAbsenceRequests($location, $pdl, $areaStaff, $monthDate);

            $templates = $this->createShiftTemplates($location);
            $shiftTemplatesCount += $templates->count();
            $staffingRulesCount += $this->createStaffingRules($location, $templates);

            $rosters->push($this->createRoster($location, $pdl, $monthDate));
        }

        $caretaker = $this->createCaretaker($locations[0], $nameOffset);

        return [
            'locationsCount' => count($locations),
            'month' => $monthDate->format('Y-m'),
            'pdlEmail' => self::PDL_EMAIL,
            'pdlPassword' => self::PASSWORD,
            'wblCount' => $nursingStaff->where('isWbl', true)->count(),
            'nursingStaffCount' => $nursingStaff->count(),
            'specialistCount' => $nursingStaff->where('qualification', QualificationLevel::Specialist)->count(),
            'assistantCount' => $nursingStaff->where('qualification', QualificationLevel::Assistant)->count(),
            'aideCount' => $nursingStaff->where('qualification', QualificationLevel::Aide)->count(),
            'cleaningStaffCount' => $cleaningStaff->count(),
            'caretakerCount' => $caretaker === null ? 0 : 1,
            'residentsCount' => $residentsCount,
            'shiftTemplatesCount' => $shiftTemplatesCount,
            'staffingRulesCount' => $staffingRulesCount,
            'absenceRequestsCount' => $absenceRequestsCount,
            'rostersCount' => $rosters->count(),
            'rosterStatus' => $rosters->first()?->status->label() ?? RosterStatus::Draft->label(),
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

    private function ensureRoles(): void
    {
        foreach (['PDL', 'WBL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    /**
     * @return array<int, Location>
     */
    private function createLocations(): array
    {
        return array_map(fn (array $config): Location => Location::firstOrCreate(
            ['name' => $config['name']],
            [
                'short_name' => $config['short'],
                'description' => 'Demo-Wohnbereich für Dienstplanung und Urlaubsplanung.',
                'active' => true,
            ],
        ), self::LOCATIONS);
    }

    /**
     * @param  array<int, Location>  $locations
     */
    private function createPdl(array $locations): User
    {
        $pdl = User::firstOrNew(['email' => self::PDL_EMAIL]);
        $pdl->forceFill([
            'location_id' => $locations[0]->id,
            'name' => 'Demo PDL Dienstplan',
            'password' => self::PASSWORD,
            'email_verified_at' => now(),
        ])->save();

        $pdl->syncRoles(['PDL']);
        // Die PDL arbeitet wohnbereichsuebergreifend.
        $pdl->locations()->syncWithoutDetaching(collect($locations)->pluck('id')->all());

        return $pdl;
    }

    /**
     * Qualifikationsmix eines Wohnbereichs: 1 WBL, 3 weitere Tagesfachkraefte,
     * 3 Pflegeassistenten, 2 Pflegehilfskraefte und ein dreikoepfiges
     * Nachtwachen-Team aus Fachkraeften (nf01-nf03). Der Nachtdienst verlangt
     * zwingend eine Fachkraft (PDL-Vorgabe); ein einzelner Nachtwache reicht
     * fuer einen vollen Monat nicht (max. 6 Tage am Stueck, Wochenstundenkappe),
     * daher drei nachtfaehige Fachkraefte, die sich Naechte und Wochenenden
     * teilen. Schichtprofile bilden Voll-/Teilzeit und Nacht ab.
     *
     * @return list<array{slug: string, role: string, qualification: QualificationLevel, hours: float, days: int, early: bool, late: bool, night: bool}>
     */
    private function nursingProfiles(): array
    {
        $fk = QualificationLevel::Specialist;
        $as = QualificationLevel::Assistant;
        $hi = QualificationLevel::Aide;

        return [
            ['slug' => 'wbl', 'role' => 'WBL', 'qualification' => $fk, 'hours' => 19.5, 'days' => 5, 'early' => true, 'late' => false, 'night' => false],
            ['slug' => 'fk01', 'role' => 'Pflegekraft', 'qualification' => $fk, 'hours' => 39, 'days' => 5, 'early' => true, 'late' => true, 'night' => false],
            ['slug' => 'fk02', 'role' => 'Pflegekraft', 'qualification' => $fk, 'hours' => 39, 'days' => 5, 'early' => true, 'late' => true, 'night' => false],
            ['slug' => 'fk03', 'role' => 'Pflegekraft', 'qualification' => $fk, 'hours' => 39, 'days' => 5, 'early' => true, 'late' => false, 'night' => false],
            ['slug' => 'as01', 'role' => 'Pflegekraft', 'qualification' => $as, 'hours' => 39, 'days' => 5, 'early' => true, 'late' => true, 'night' => false],
            ['slug' => 'as02', 'role' => 'Pflegekraft', 'qualification' => $as, 'hours' => 30, 'days' => 4, 'early' => true, 'late' => true, 'night' => false],
            ['slug' => 'as03', 'role' => 'Pflegekraft', 'qualification' => $as, 'hours' => 20, 'days' => 3, 'early' => true, 'late' => false, 'night' => false],
            ['slug' => 'hi01', 'role' => 'Pflegekraft', 'qualification' => $hi, 'hours' => 39, 'days' => 5, 'early' => true, 'late' => true, 'night' => false],
            ['slug' => 'hi02', 'role' => 'Pflegekraft', 'qualification' => $hi, 'hours' => 25, 'days' => 4, 'early' => true, 'late' => true, 'night' => false],
            ['slug' => 'nf01', 'role' => 'Pflegekraft', 'qualification' => $fk, 'hours' => 30, 'days' => 4, 'early' => false, 'late' => true, 'night' => true],
            ['slug' => 'nf02', 'role' => 'Pflegekraft', 'qualification' => $fk, 'hours' => 30, 'days' => 4, 'early' => false, 'late' => true, 'night' => true],
            ['slug' => 'nf03', 'role' => 'Pflegekraft', 'qualification' => $fk, 'hours' => 30, 'days' => 4, 'early' => false, 'late' => true, 'night' => true],
        ];
    }

    /**
     * @return Collection<int, User>
     */
    private function createNursingStaff(Location $location, string $areaKey, int $nameOffset): Collection
    {
        return collect($this->nursingProfiles())->map(function (array $profile, int $index) use ($location, $areaKey, $nameOffset): User {
            $email = sprintf('demo.%s.%s@pflegedex.local', $areaKey, $profile['slug']);
            $employee = User::firstOrNew(['email' => $email]);
            $employee->forceFill([
                'location_id' => $location->id,
                'name' => $this->staffName($nameOffset + $index),
                'password' => self::PASSWORD,
                'email_verified_at' => now(),
            ])->save();

            $employee->syncRoles([$profile['role']]);
            $employee->locations()->syncWithoutDetaching([$location->id]);

            EmployeeProfile::updateOrCreate(
                ['user_id' => $employee->id],
                [
                    'employment_area' => EmploymentArea::Nursing,
                    'qualification_level' => $profile['qualification'],
                    'is_nursing_specialist' => $profile['qualification']->isSpecialist(),
                    'weekly_hours' => $profile['hours'],
                    'regular_work_days_per_week' => $profile['days'],
                    'annual_vacation_days' => 30,
                    'vacation_days_carried_over' => $index % 3,
                    'overtime_minutes_balance' => ($index % 4 - 1) * 30,
                    'can_work_early' => $profile['early'],
                    'can_work_late' => $profile['late'],
                    'can_work_night' => $profile['night'],
                    'active' => true,
                ],
            );

            // Zusatzinfos fuer die Zusammenfassung, nicht persistiert.
            $employee->setAttribute('isWbl', $profile['role'] === 'WBL');
            $employee->setAttribute('qualification', $profile['qualification']);

            return $employee;
        });
    }

    /**
     * @return Collection<int, User>
     */
    private function createCleaningStaff(Location $location, string $areaKey, int $nameOffset): Collection
    {
        $email = sprintf('demo.%s.putz@pflegedex.local', $areaKey);
        $employee = User::firstOrNew(['email' => $email]);
        $employee->forceFill([
            'location_id' => $location->id,
            'name' => $this->staffName($nameOffset),
            'password' => self::PASSWORD,
            'email_verified_at' => now(),
        ])->save();

        $employee->syncRoles(['Putzkraft']);
        $employee->locations()->syncWithoutDetaching([$location->id]);

        EmployeeProfile::updateOrCreate(
            ['user_id' => $employee->id],
            [
                'employment_area' => EmploymentArea::Cleaning,
                'qualification_level' => null,
                'is_nursing_specialist' => false,
                'weekly_hours' => 20,
                'regular_work_days_per_week' => 5,
                'annual_vacation_days' => 28,
                'vacation_days_carried_over' => 0,
                'overtime_minutes_balance' => 0,
                'can_work_early' => true,
                'can_work_late' => false,
                'can_work_night' => false,
                'active' => true,
            ],
        );

        return collect([$employee]);
    }

    private function createCaretaker(Location $location, int $nameOffset): User
    {
        $employee = User::firstOrNew(['email' => 'demo.hausmeister@pflegedex.local']);
        $employee->forceFill([
            'location_id' => $location->id,
            'name' => $this->staffName($nameOffset),
            'password' => self::PASSWORD,
            'email_verified_at' => now(),
        ])->save();

        $employee->syncRoles(['Hausmeister']);
        $employee->locations()->syncWithoutDetaching([$location->id]);

        EmployeeProfile::updateOrCreate(
            ['user_id' => $employee->id],
            [
                'employment_area' => EmploymentArea::Caretaker,
                'qualification_level' => null,
                'is_nursing_specialist' => false,
                'weekly_hours' => 39,
                'regular_work_days_per_week' => 5,
                'annual_vacation_days' => 30,
                'vacation_days_carried_over' => 0,
                'overtime_minutes_balance' => 0,
                'can_work_early' => true,
                'can_work_late' => false,
                'can_work_night' => false,
                'active' => true,
            ],
        );

        return $employee;
    }

    /**
     * Legt Bewohner mit gemischten Pflegegraden (2 bis 5) an. Auditing wird
     * dabei abgeschaltet, damit der Demo-Seeder keinen Audit-Laerm erzeugt.
     */
    private function createResidents(Location $location, string $areaKey, int $areaIndex): int
    {
        $areaLetter = strtoupper($areaKey);

        Resident::withoutAuditing(function () use ($location, $areaLetter, $areaIndex): void {
            for ($number = 1; $number <= self::RESIDENTS_PER_AREA; $number++) {
                // Fortlaufender Index ueber beide Bereiche, damit sich die Namen
                // zwischen Wohnbereich A und B nicht wiederholen.
                $globalIndex = $areaIndex * self::RESIDENTS_PER_AREA + ($number - 1);
                $isFemale = ($globalIndex % 2) === 0;
                $genderSeq = intdiv($globalIndex, 2);

                $firstName = $isFemale
                    ? self::RESIDENT_FEMALE_NAMES[$genderSeq % count(self::RESIDENT_FEMALE_NAMES)]
                    : self::RESIDENT_MALE_NAMES[$genderSeq % count(self::RESIDENT_MALE_NAMES)];
                // Zusaetzlicher, je Bereich verschobener Offset, damit auch die
                // Nachnamen zwischen A und B nicht zeilenweise zusammenfallen.
                $lastName = self::RESIDENT_LAST_NAMES[($globalIndex * 7 + $areaIndex * 13 + 3) % count(self::RESIDENT_LAST_NAMES)];

                Resident::updateOrCreate(
                    ['pseudonym' => sprintf('P-DEMO-%s%02d', $areaLetter, $number)],
                    [
                        'location_id' => $location->id,
                        'salutation' => $isFemale ? 'frau' : 'herr',
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'birth_date' => sprintf('19%02d-%02d-%02d', 35 + ($number % 18), ($number % 12) + 1, ($number % 27) + 1),
                        'room_number' => sprintf('%s-1%02d', $areaLetter, $number),
                        'care_level' => 2 + ($number % 4),
                        'active' => true,
                    ],
                );
            }
        });

        return self::RESIDENTS_PER_AREA;
    }

    /**
     * @param  Collection<int, User>  $employees
     */
    private function createAbsenceRequests(Location $location, User $pdl, Collection $employees, CarbonImmutable $monthDate): int
    {
        // Bezieht sich auf die Position im Qualifikationsmix dieses Bereichs.
        $absences = [
            ['index' => 1, 'startDay' => 8, 'endDay' => 12, 'note' => 'Demo-Urlaub Fachkraft'],
            ['index' => 4, 'startDay' => 18, 'endDay' => 20, 'note' => 'Demo-Urlaub Assistent'],
            ['index' => 7, 'startDay' => 25, 'endDay' => 25, 'note' => 'Demo einzelner freier Tag'],
        ];

        $created = 0;

        foreach ($absences as $absence) {
            $employee = $employees->get($absence['index']);

            if (! $employee instanceof User) {
                continue;
            }

            $startsOn = $monthDate->setDay($absence['startDay']);
            $endsOn = $monthDate->setDay($absence['endDay']);

            // whereDate haelt den Seeder unabhaengig vom Speicherformat der
            // Datumsspalten (PostgreSQL date vs. SQLite Text) idempotent.
            $existing = AbsenceRequest::query()
                ->where('user_id', $employee->id)
                ->where('type', AbsenceRequestType::Vacation)
                ->whereDate('starts_on', $startsOn->toDateString())
                ->whereDate('ends_on', $endsOn->toDateString())
                ->first();

            $attributes = [
                'user_id' => $employee->id,
                'type' => AbsenceRequestType::Vacation,
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn->toDateString(),
                'location_id' => $location->id,
                'days_count' => $startsOn->diffInDays($endsOn) + 1,
                'status' => AbsenceRequestStatus::Approved,
                'requested_by' => $pdl->id,
                'decided_by' => $pdl->id,
                'decided_at' => now(),
                'rejection_reason' => null,
                'note' => $absence['note'],
            ];

            if ($existing === null) {
                AbsenceRequest::query()->create($attributes);
            } else {
                $existing->update($attributes);
            }

            $created++;
        }

        return $created;
    }

    /**
     * @return Collection<int, ShiftTemplate>
     */
    private function createShiftTemplates(Location $location): Collection
    {
        $templates = [
            ['code' => 'early', 'name' => 'Frühdienst', 'starts_at' => '06:00', 'ends_at' => '14:00', 'color' => '#F59E0B'],
            ['code' => 'late', 'name' => 'Spätdienst', 'starts_at' => '14:00', 'ends_at' => '22:00', 'color' => '#3B82F6'],
            ['code' => 'night', 'name' => 'Nachtdienst', 'starts_at' => '22:00', 'ends_at' => '06:00', 'color' => '#6366F1'],
        ];

        return collect($templates)->map(fn (array $template): ShiftTemplate => ShiftTemplate::updateOrCreate(
            [
                'location_id' => $location->id,
                'code' => $template['code'],
            ],
            [
                'name' => $template['name'],
                'category' => $template['code'],
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
        // Besetzung PRO KATEGORIE: total = Mindestbesetzung (Boden), target =
        // Idealbesetzung (Aufstockung bis Soll). Früh > Spät > Nacht steckt in den
        // Idealzahlen. Mehrere Schichten je Kategorie teilen sich diese Zahlen.
        $requirements = [
            'early' => ['total' => 2, 'target' => 4, 'specialists' => 1],
            'late' => ['total' => 2, 'target' => 3, 'specialists' => 1],
            // Nachtdienst: zwingend eine Fachkraft (PDL-Vorgabe), keine Aufstockung.
            'night' => ['total' => 1, 'target' => 1, 'specialists' => 1],
        ];

        foreach ($requirements as $category => $requirement) {
            ShiftCategoryStaffingRule::query()->updateOrCreate(
                [
                    'location_id' => $location->id,
                    'category' => $category,
                    'weekday' => null,
                ],
                [
                    'required_total_staff' => $requirement['total'],
                    'target_total_staff' => $requirement['target'],
                    'required_specialists' => $requirement['specialists'],
                ],
            );
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

    private function staffName(int $index): string
    {
        $first = self::STAFF_FIRST_NAMES[$index % count(self::STAFF_FIRST_NAMES)];
        $last = self::STAFF_LAST_NAMES[($index * 3 + 1) % count(self::STAFF_LAST_NAMES)];

        return $first.' '.$last;
    }
}
