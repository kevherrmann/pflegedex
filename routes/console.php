<?php

use App\Models\Location;
use App\Services\Rosters\DefaultShiftSetupService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('pflegedex:create-default-shifts', function (DefaultShiftSetupService $defaultShiftSetupService): int {
    $locations = Location::query()->get();

    $locations->each(
        fn (Location $location) => $defaultShiftSetupService->createForLocation($location),
    );

    $this->info("Standardschichten für {$locations->count()} Wohnbereiche erstellt oder aktualisiert.");

    return self::SUCCESS;
})->purpose('Create or update default shift templates and staffing rules for all locations');
