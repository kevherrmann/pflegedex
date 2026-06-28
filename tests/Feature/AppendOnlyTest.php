<?php

declare(strict_types=1);

use App\Models\CareReport;
use App\Models\Location;
use App\Models\ReportVersion;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Models\Audit;

uses(RefreshDatabase::class);

function appendOnlyReportVersion(): ReportVersion
{
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    $report = CareReport::factory()->for($resident)->create();

    return $report->appendVersion('created', User::factory()->create());
}

it('verhindert das Loeschen einer Berichtsversion (append-only)', function (): void {
    $version = appendOnlyReportVersion();

    expect(fn () => $version->delete())->toThrow(Exception::class);
    expect($version->fresh())->not->toBeNull();
});

it('verhindert das Aendern des Versionsinhalts (append-only)', function (): void {
    $version = appendOnlyReportVersion();

    $version->content_snapshot = 'nachtraeglich manipuliert';

    expect(fn () => $version->save())->toThrow(Exception::class);
});

it('erlaubt die Attributions-Nullung (created_by) fuer Nutzerloeschung', function (): void {
    $version = appendOnlyReportVersion();

    // Nur created_by aendern -> Inhalt unveraendert -> erlaubt (FK nullOnDelete-Pfad).
    $version->created_by = null;
    $version->save();

    expect($version->fresh()->created_by)->toBeNull();
});

it('verhindert das Loeschen eines Audit-Eintrags (DB-Trigger)', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();

    // Aenderung erzeugt einen Audit-Eintrag (Resident ist auditiert).
    $resident->update(['room_number' => 'A-999']);

    $audit = Audit::query()->latest()->first();
    expect($audit)->not->toBeNull();

    $countBefore = Audit::query()->count();

    // owen-it-Audit hat keinen Eloquent-Guard -> der Postgres-Trigger muss greifen.
    // In einen Savepoint kapseln, damit der Trigger-Fehler nur diesen zurueckrollt
    // und die umschliessende Test-Transaktion (RefreshDatabase) nutzbar bleibt.
    expect(fn () => DB::transaction(fn () => $audit->delete()))->toThrow(Exception::class);
    expect(Audit::query()->count())->toBe($countBefore);
})->skip(
    fn (): bool => DB::getDriverName() !== 'pgsql',
    'Der Audit-Löschschutz ist ein PostgreSQL-Trigger; SQLite (lokale Testdatenbank) kennt ihn nicht. Auf der PostgreSQL-CI läuft der Test.',
);
