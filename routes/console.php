<?php

use App\Models\Location;
use App\Services\Rosters\DefaultShiftSetupService;
use App\Services\Rosters\RosterDemoDataSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('pflegedex:create-default-shifts', function (DefaultShiftSetupService $defaultShiftSetupService): int {
    $locations = Location::query()->get();

    $locations->each(
        fn (Location $location) => $defaultShiftSetupService->createForLocation($location),
    );

    $this->info("Standardschichten für {$locations->count()} Wohnbereiche erstellt oder aktualisiert.");

    return Command::SUCCESS;
})->purpose('Create or update default shift templates and staffing rules for all locations');

Artisan::command('pflegedex:seed-roster-demo {--month=2027-01 : Demo month in YYYY-MM format} {--force : Allow running in production}', function (RosterDemoDataSeeder $demoDataSeeder): int {
    if (app()->isProduction() && ! $this->option('force')) {
        $this->error('Der Demo-Seeder darf in Produktion nur mit --force ausgeführt werden.');

        return Command::FAILURE;
    }

    try {
        $result = $demoDataSeeder->seed((string) $this->option('month'));
    } catch (InvalidArgumentException $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }

    $this->info('Dienstplan-Demo-Daten wurden erstellt oder aktualisiert.');
    $this->line("Wohnbereiche: {$result['locationsCount']}");
    $this->line("Monat: {$result['month']}");
    $this->line("PDL: {$result['pdlEmail']} / {$result['pdlPassword']}");
    $this->line("Pflegepersonal gesamt: {$result['nursingStaffCount']} (inkl. {$result['wblCount']} WBL)");
    $this->line("  davon Pflegefachkräfte: {$result['specialistCount']}");
    $this->line("  davon Pflegeassistenten: {$result['assistantCount']}");
    $this->line("  davon Pflegehilfskräfte: {$result['aideCount']}");
    $this->line("Putzkräfte: {$result['cleaningStaffCount']}");
    $this->line("Hausmeister: {$result['caretakerCount']}");
    $this->line("Bewohner: {$result['residentsCount']}");
    $this->line("Schichtvorlagen: {$result['shiftTemplatesCount']}");
    $this->line("Personalbesetzungsregeln: {$result['staffingRulesCount']}");
    $this->line("Abwesenheiten: {$result['absenceRequestsCount']}");
    $this->line("Dienstpläne: {$result['rostersCount']} ({$result['rosterStatus']})");

    return Command::SUCCESS;
})->purpose('Create or update realistic roster demo data for manual testing');
