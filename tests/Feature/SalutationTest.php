<?php

declare(strict_types=1);

use App\Enums\Salutation;
use App\Models\Location;
use App\Models\Resident;
use App\Models\User;
use App\Services\Ai\OllamaClient;
use App\Services\Ai\SisFormulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['Admin', 'PDL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

it('verlangt eine Anrede beim Anlegen eines Bewohners', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($pdl)
        ->post(route('residents.store'), [
            'first_name' => 'Test',
            'last_name' => 'Bewohner',
        ])
        ->assertSessionHasErrors('salutation');
});

it('akzeptiert salutation=frau und persistiert das Cast als Enum', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($pdl)
        ->post(route('residents.store'), [
            'salutation' => 'frau',
            'first_name' => 'Erika',
            'last_name' => 'Mustermann',
        ])
        ->assertRedirect();

    $resident = Resident::query()->where('first_name', 'Erika')->first();
    expect($resident)->not->toBeNull()
        ->and($resident->salutation)->toBe(Salutation::Frau)
        ->and($resident->formal_name)->toBe('Frau Mustermann');
});

it('weist ungueltige Anrede zurueck', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($pdl)
        ->post(route('residents.store'), [
            'salutation' => 'divers',
            'first_name' => 'Test',
            'last_name' => 'Bewohner',
        ])
        ->assertSessionHasErrors('salutation');
});

it('SisFormulator: System-Prompt nutzt fuer Frau "die Bewohnerin" und Pronomen "sie"', function (): void {
    $captured = null;

    $client = Mockery::mock(OllamaClient::class);
    $client->shouldReceive('generate')
        ->once()
        ->andReturnUsing(function ($prompt, $system) use (&$captured): string {
            $captured = $system;

            return 'Antwort';
        });

    $formulator = new SisFormulator($client);
    $formulator->formulateField('TF1', 'orientiert', Salutation::Frau);

    expect($captured)->toContain('Die Bewohnerin')
        ->and($captured)->toContain('"sie"');
});

it('SisFormulator: System-Prompt nutzt fuer Herrn "der Bewohner" und Pronomen "er"', function (): void {
    $captured = null;

    $client = Mockery::mock(OllamaClient::class);
    $client->shouldReceive('generate')
        ->once()
        ->andReturnUsing(function ($prompt, $system) use (&$captured): string {
            $captured = $system;

            return 'Antwort';
        });

    $formulator = new SisFormulator($client);
    $formulator->formulateField('TF1', 'orientiert', Salutation::Herr);

    expect($captured)->toContain('Der Bewohner')
        ->and($captured)->toContain('"er"');
});
