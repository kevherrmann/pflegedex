<?php

use App\Models\Roster;
use App\Services\Rosters\RosterGeneratorService;
use App\Services\Rosters\RosterValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * End-to-End-Beweis: Auf realistischen Demo-Daten (zwei Wohnbereiche mit
 * PeBeM-Qualifikationsmix, Besetzungsregeln und genehmigten Abwesenheiten)
 * erzeugt der Generator vollständig besetzte Dienstpläne, die der Validator
 * ohne Fehler und ohne Verstöße gegen die harten Regeln abnimmt.
 */
it('generates fully staffed and rule-conform rosters on realistic demo data', function (): void {
    $this->artisan('pflegedex:seed-roster-demo', ['--month' => '2027-03'])
        ->assertSuccessful();

    $rosters = Roster::query()->where('year', 2027)->where('month', 3)->get();

    expect($rosters)->toHaveCount(2);

    $generator = app(RosterGeneratorService::class);
    $validator = app(RosterValidator::class);

    foreach ($rosters as $roster) {
        $startedAt = microtime(true);
        $generationResult = $generator->generate($roster);
        $elapsedSeconds = microtime(true) - $startedAt;

        $noCandidateSkips = collect($generationResult->skipped)
            ->where('code', 'no_candidate');

        // Jeder Besetzungsbedarf wurde erfüllt; die Laufzeit bleibt im Budget.
        expect($generationResult->createdShifts)->toBeGreaterThan(100)
            ->and($noCandidateSkips)->toBeEmpty()
            ->and($elapsedSeconds)->toBeLessThan(10.0);

        $validationResult = $validator->validate($roster->refresh());

        // Keine Fehler: Besetzung, Fachkraftquote, Ruhezeiten, Abwesenheiten.
        expect($validationResult->errors)->toBeEmpty();

        // Keine Warnungen zu Regeln, die der Generator hart garantiert.
        $guaranteedRuleWarnings = collect($validationResult->warnings)
            ->whereIn('code', [
                'employee_too_many_consecutive_work_days',
                'employee_has_no_free_day_in_month',
            ]);

        expect($guaranteedRuleWarnings)->toBeEmpty();

        // Wochenend-Warnungen sind in diesem Monat strukturell unvermeidbar:
        // Pro Wochenendtag braucht jede der drei Schichten eine Fachkraft
        // (24 Fachkraft-Wochenendtage), die 5 Fachkräfte decken mit maximal
        // zwei Wochenenden aber nur 20 ab. Die Lockerung verteilt die
        // Mehrbelastung — niemand arbeitet mehr als drei Wochenenden.
        $weekendWarnings = collect($validationResult->warnings)
            ->where('code', 'employee_too_many_weekends');

        expect($weekendWarnings->every(
            fn (array $warning): bool => $warning['context']['workedWeekends'] <= 3,
        ))->toBeTrue();
    }
});

it('keeps generated rosters deterministic on demo data', function (): void {
    $this->artisan('pflegedex:seed-roster-demo', ['--month' => '2027-03'])
        ->assertSuccessful();

    $roster = Roster::query()->where('year', 2027)->where('month', 3)->firstOrFail();
    $generator = app(RosterGeneratorService::class);

    $generator->generate($roster);
    $firstRun = $roster->shifts()
        ->get()
        ->map(fn ($shift): string => implode('|', [$shift->user_id, $shift->shift_template_id, $shift->date->toDateString()]))
        ->sort()
        ->values();

    $generator->generate($roster->refresh());
    $secondRun = $roster->shifts()
        ->get()
        ->map(fn ($shift): string => implode('|', [$shift->user_id, $shift->shift_template_id, $shift->date->toDateString()]))
        ->sort()
        ->values();

    expect($secondRun->all())->toBe($firstRun->all());
});
