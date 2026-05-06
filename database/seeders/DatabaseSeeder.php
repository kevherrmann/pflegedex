<?php

namespace Database\Seeders;

use App\Enums\SisRiskKind;
use App\Enums\SisTopic;
use App\Models\CareReport;
use App\Models\Location;
use App\Models\Resident;
use App\Models\Sis;
use App\Models\SisRisk;
use App\Models\SisTopicEntry;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Auditing wird waehrend des Seeders deaktiviert, damit Demo-Daten
     * keinen Audit-Laerm produzieren. In Tests faellt das ohnehin durch
     * 'console' => true wieder rein, was wir aber gezielt fuer den
     * Resident-Update-Pfad brauchen.
     */
    public function run(): void
    {
        Location::withoutAuditing(function (): void {
            Resident::withoutAuditing(function (): void {
                User::withoutAuditing(function (): void {
                    CareReport::withoutAuditing(function (): void {
                        Sis::withoutAuditing(function (): void {
                            $this->seedAll();
                        });
                    });
                });
            });
        });
    }

    private function seedAll(): void
    {
        $this->call(RoleSeeder::class);

        $location = Location::firstOrCreate(
            ['name' => 'Wohnbereich A'],
            [
                'short_name' => 'A',
                'description' => 'Erster Beispiel-Wohnbereich für die lokale Entwicklung.',
                'active' => true,
            ],
        );

        $secondLocation = Location::firstOrCreate(
            ['name' => 'Wohnbereich B'],
            [
                'short_name' => 'B',
                'description' => 'Zweiter Beispiel-Wohnbereich für Tests mit bereichsgetrennter Sichtbarkeit.',
                'active' => true,
            ],
        );

        Resident::firstOrCreate(
            [
                'location_id' => $location->id,
                'first_name' => 'Erika',
                'last_name' => 'Mustermann',
            ],
            [
                'pseudonym' => 'P-'.now()->format('Y').'-0001',
                'birth_date' => '1938-05-12',
                'room_number' => 'A-101',
                'care_level' => 3,
                'active' => true,
            ],
        );

        Resident::firstOrCreate(
            [
                'location_id' => $secondLocation->id,
                'first_name' => 'Karl',
                'last_name' => 'Beispiel',
            ],
            [
                'pseudonym' => 'P-'.now()->format('Y').'-0002',
                'birth_date' => '1941-09-24',
                'room_number' => 'B-201',
                'care_level' => 2,
                'active' => true,
            ],
        );

        $admin = User::updateOrCreate(
            ['email' => 'admin@pflegedex.local'],
            [
                'location_id' => $location->id,
                'name' => 'Pflegedex Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $admin->assignRole('Admin');

        $pdl = User::updateOrCreate(
            ['email' => 'pdl@pflegedex.local'],
            [
                'location_id' => $location->id,
                'name' => 'Pflegedex PDL',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $pdl->assignRole('PDL');
        $pdl->locations()->syncWithoutDetaching([$location->id, $secondLocation->id]);

        $carl = User::updateOrCreate(
            ['email' => 'carl@pflegedex.local'],
            [
                'location_id' => $location->id,
                'name' => 'Carl Pflegekraft',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $carl->syncRoles(['Pflegekraft']);
        $carl->locations()->sync([$location->id]);

        $this->seedDemoSis($pdl);
    }

    private function seedDemoSis(User $author): void
    {
        $erika = Resident::query()
            ->where('first_name', 'Erika')
            ->where('last_name', 'Mustermann')
            ->first();

        if ($erika === null || $erika->sis()->exists()) {
            return;
        }

        $sis = Sis::query()->create([
            'resident_id' => $erika->id,
            'location_id' => $erika->location_id,
            'opening_question' => 'Möchte gerne mehr Zeit im Garten verbringen und Kontakt zu ihrer Tochter halten.',
            'started_at' => today()->subDays(10),
            'completed_at' => today()->subDays(2),
            'evaluated_at' => null,
            'next_evaluation_due' => today()->subDays(2)->addWeeks(8),
            'created_by' => $author->id,
        ]);

        $topicTexts = [
            1 => 'Orientierung zeitlich leicht eingeschränkt, kommunikativ aufgeschlossen.',
            2 => 'Geht mit Rollator selbstständig kurze Strecken, Sturzrisiko erhöht.',
            3 => 'Diabetes Typ 2, gut eingestellt. Regelmäßige Blutzuckerkontrolle.',
            4 => 'Benötigt Unterstützung beim Duschen, kleidet sich selbst an.',
            5 => 'Wöchentlicher Besuch der Tochter, gerne in Gesellschaft.',
            6 => 'Einzelzimmer mit eigenem Bad, fühlt sich wohl.',
        ];

        foreach (SisTopic::numbers() as $number) {
            SisTopicEntry::query()->create([
                'sis_id' => $sis->id,
                'topic_number' => $number,
                'content' => $topicTexts[$number] ?? null,
            ]);
        }

        $riskFlags = [
            'sturz' => ['is_at_risk' => true, 'needs_further_assessment' => true, 'notes' => 'Mobilitätsförderung läuft, Sturzprotokoll alle 8 Wochen.'],
            'dekubitus' => ['is_at_risk' => false, 'needs_further_assessment' => false, 'notes' => null],
            'inkontinenz' => ['is_at_risk' => true, 'needs_further_assessment' => false, 'notes' => 'Nachts inkontinent, tagsüber selbstständig.'],
            'schmerz' => ['is_at_risk' => false, 'needs_further_assessment' => false, 'notes' => 'Keine bekannten Schmerzen.'],
            'ernaehrung' => ['is_at_risk' => false, 'needs_further_assessment' => false, 'notes' => null],
            'sonstiges' => ['is_at_risk' => false, 'needs_further_assessment' => false, 'notes' => null],
        ];

        foreach (SisRiskKind::values() as $kind) {
            $flags = $riskFlags[$kind];
            SisRisk::query()->create([
                'sis_id' => $sis->id,
                'risk_kind' => $kind,
                'is_at_risk' => $flags['is_at_risk'],
                'needs_further_assessment' => $flags['needs_further_assessment'],
                'notes' => $flags['notes'],
            ]);
        }

        $sis->refresh()->load(['topicEntries', 'risks']);
        $sis->appendVersion('created', $author);
    }
}
