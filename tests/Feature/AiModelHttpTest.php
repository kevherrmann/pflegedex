<?php

use App\Models\AiModel;
use App\Models\User;
use App\Services\Ai\OllamaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('Admin');
    Role::findOrCreate('PDL');
    config()->set('ai.ollama.url', 'http://ollama:11434');
    config()->set('ai.ollama.model', 'gemma4:e2b');
});

function aiAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Admin');

    return $user;
}

it('shows the AI model page to admins and ensures a default Gemma model', function (): void {
    $this->actingAs(aiAdmin())
        ->get(route('ai-models.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('AiModels/Index')
            ->has('models', 1)
            ->where('models.0.isDefault', true)
            ->where('models.0.isActive', true)
            ->where('models.0.provider', 'ollama'));

    expect(AiModel::query()->where('is_default', true)->count())->toBe(1);
});

it('forbids non-admins from the AI model page', function (): void {
    $pdl = User::factory()->create();
    $pdl->assignRole('PDL');

    $this->actingAs($pdl)->get(route('ai-models.index'))->assertForbidden();
});

it('lets an admin add an external model with an encrypted api key', function (): void {
    $admin = aiAdmin();

    $this->actingAs($admin)
        ->post(route('ai-models.store'), [
            'label' => 'DeepSeek',
            'provider' => 'openai',
            'model' => 'deepseek-chat',
            'base_url' => 'https://api.deepseek.com',
            'api_key' => 'sk-secret-123',
        ])
        ->assertRedirect();

    $model = AiModel::query()->where('label', 'DeepSeek')->firstOrFail();

    expect($model->provider->value)->toBe('openai')
        ->and($model->model)->toBe('deepseek-chat')
        ->and($model->api_key)->toBe('sk-secret-123')
        ->and($model->is_active)->toBeFalse();

    // API-Key liegt verschlüsselt in der DB (nicht im Klartext).
    $raw = (string) DB::table('ai_models')->where('id', $model->id)->value('api_key');
    expect($raw)->not->toBe('sk-secret-123')
        ->and($raw)->toContain('eyJ');
});

it('requires url and api key for external models', function (): void {
    $this->actingAs(aiAdmin())
        ->from(route('ai-models.index'))
        ->post(route('ai-models.store'), [
            'label' => 'Unvollständig',
            'provider' => 'openai',
            'model' => 'deepseek-chat',
        ])
        ->assertSessionHasErrors(['base_url', 'api_key']);
});

it('activates a model and deactivates the others', function (): void {
    $admin = aiAdmin();
    $this->actingAs($admin)->get(route('ai-models.index')); // legt Default an

    $external = AiModel::query()->create([
        'label' => 'DeepSeek',
        'provider' => 'openai',
        'model' => 'deepseek-chat',
        'base_url' => 'https://api.deepseek.com',
        'api_key' => 'sk-x',
        'is_active' => false,
        'is_default' => false,
    ]);

    $this->actingAs($admin)
        ->patch(route('ai-models.activate', $external))
        ->assertRedirect();

    expect($external->refresh()->is_active)->toBeTrue()
        ->and(AiModel::query()->where('is_active', true)->count())->toBe(1);
});

it('cannot delete the default model', function (): void {
    $admin = aiAdmin();
    $this->actingAs($admin)->get(route('ai-models.index'));
    $default = AiModel::query()->where('is_default', true)->firstOrFail();

    $this->actingAs($admin)
        ->from(route('ai-models.index'))
        ->delete(route('ai-models.destroy', $default))
        ->assertSessionHasErrors(['ai_model']);

    expect(AiModel::query()->whereKey($default->id)->exists())->toBeTrue();
});

it('deletes a non-default model and falls back to default when it was active', function (): void {
    $admin = aiAdmin();
    $this->actingAs($admin)->get(route('ai-models.index'));
    $default = AiModel::query()->where('is_default', true)->firstOrFail();

    $external = AiModel::query()->create([
        'label' => 'DeepSeek',
        'provider' => 'openai',
        'model' => 'deepseek-chat',
        'base_url' => 'https://api.deepseek.com',
        'api_key' => 'sk-x',
        'is_active' => true,
        'is_default' => false,
    ]);
    $default->update(['is_active' => false]);

    $this->actingAs($admin)
        ->delete(route('ai-models.destroy', $external))
        ->assertRedirect();

    expect(AiModel::query()->whereKey($external->id)->exists())->toBeFalse()
        ->and($default->refresh()->is_active)->toBeTrue();
});

it('uses the active ollama model for generation', function (): void {
    Http::fake([
        '*/api/generate' => Http::response(['response' => 'ok'], 200),
    ]);

    AiModel::query()->create([
        'label' => 'Mistral lokal',
        'provider' => 'ollama',
        'model' => 'mistral:7b',
        'base_url' => null,
        'api_key' => null,
        'is_active' => true,
        'is_default' => false,
    ]);

    $result = app(OllamaClient::class)->generate('Hallo');

    expect($result)->toBe('ok');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/generate')
        && $request['model'] === 'mistral:7b');
});

it('uses the active external model via the openai-compatible api', function (): void {
    Http::fake([
        'https://api.deepseek.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Hallo aus DeepSeek']]],
        ], 200),
    ]);

    AiModel::query()->create([
        'label' => 'DeepSeek',
        'provider' => 'openai',
        'model' => 'deepseek-chat',
        'base_url' => 'https://api.deepseek.com',
        'api_key' => 'sk-secret',
        'is_active' => true,
        'is_default' => false,
    ]);

    $result = app(OllamaClient::class)->generate('Frage', 'System');

    expect($result)->toBe('Hallo aus DeepSeek');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/chat/completions')
        && $request['model'] === 'deepseek-chat'
        && $request->hasHeader('Authorization', 'Bearer sk-secret'));
});
