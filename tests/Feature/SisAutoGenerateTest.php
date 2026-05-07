<?php

declare(strict_types=1);

use App\Jobs\GenerateSisJob;
use App\Models\Location;
use App\Models\Resident;
use App\Models\SisGeneration;
use App\Models\User;
use App\Services\Ai\AiHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeAiHealthService;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['Admin', 'PDL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

it('dispatcht beim SIS-Anlegen automatisch einen Generate-Job wenn KI verfuegbar ist', function (): void {
    // Healthy ist Default aus TestCase, aber explizit fuer Lesbarkeit:
    app()->bind(AiHealthService::class, fn() => FakeAiHealthService::healthy());

    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($pdl)
        ->post(route('residents.sis.store', $resident), [
            'opening_question' => 'Garten, Familie',
            'topics' => [['topic_number' => 1, 'content' => 'orientiert']],
            'risks' => [['risk_kind' => 'sturz', 'is_at_risk' => true, 'needs_further_assessment' => false]],
        ])
        ->assertRedirect(route('residents.sis.show', $resident))
        ->assertSessionHas('success');

    Queue::assertPushed(GenerateSisJob::class);
    expect(SisGeneration::query()->count())->toBe(1);

    $generation = SisGeneration::query()->first();
    expect($generation->status)->toBe('pending')
        ->and($generation->triggered_by)->toBe($pdl->id);
});

it('dispatcht KEINEN Generate-Job beim Anlegen wenn KI nicht verfuegbar ist', function (): void {
    app()->bind(AiHealthService::class, fn() => FakeAiHealthService::unavailable());

    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($pdl)
        ->post(route('residents.sis.store', $resident), [
            'opening_question' => 'Garten, Familie',
            'topics' => [],
            'risks' => [],
        ])
        ->assertRedirect(route('residents.sis.show', $resident))
        ->assertSessionHas('success'); // SIS ist trotzdem angelegt

    Queue::assertNothingPushed();
    expect(SisGeneration::query()->count())->toBe(0);
});
