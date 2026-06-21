<?php

namespace App\Http\Controllers;

use App\Enums\AiProvider;
use App\Models\AiModel;
use App\Services\Ai\AiHealthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Verwaltung der KI-Modelle (nur Admin). Der lokale Gemma-Standard bleibt immer
 * vorhanden und aktiviert; zusätzlich lassen sich externe, OpenAI-kompatible
 * Modelle (z. B. DeepSeek) per verschlüsseltem API-Key anbinden und aktivieren.
 */
class AiModelController extends Controller
{
    public function index(Request $request, AiHealthService $health): Response
    {
        $this->authorizeAdmin($request);

        $this->ensureDefault();

        return Inertia::render('AiModels/Index', [
            'models' => AiModel::query()
                ->orderByDesc('is_default')
                ->orderBy('label')
                ->get()
                ->map(fn (AiModel $model): array => [
                    'id' => $model->id,
                    'label' => $model->label,
                    'provider' => $model->provider->value,
                    'providerLabel' => $model->provider->label(),
                    'model' => $model->model,
                    'baseUrl' => $model->base_url,
                    'hasApiKey' => filled($model->api_key),
                    'isActive' => $model->is_active,
                    'isDefault' => $model->is_default,
                ])
                ->values(),
            'providers' => collect(AiProvider::cases())
                ->map(fn (AiProvider $provider): array => [
                    'value' => $provider->value,
                    'label' => $provider->label(),
                ])
                ->values(),
            'health' => $health->status(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'provider' => ['required', Rule::enum(AiProvider::class)],
            'model' => ['required', 'string', 'max:255'],
            'base_url' => ['nullable', 'string', 'max:255', 'url', 'required_if:provider,openai'],
            'api_key' => ['nullable', 'string', 'max:255', 'required_if:provider,openai'],
        ]);

        AiModel::query()->create([
            'label' => $validated['label'],
            'provider' => $validated['provider'],
            'model' => $validated['model'],
            'base_url' => $validated['base_url'] ?? null,
            'api_key' => $validated['api_key'] ?? null,
            'is_active' => false,
            'is_default' => false,
        ]);

        return back()->with('status', 'ai-model-created');
    }

    public function activate(Request $request, AiModel $aiModel): RedirectResponse
    {
        $this->authorizeAdmin($request);

        AiModel::query()->where('is_active', true)->update(['is_active' => false]);
        $aiModel->update(['is_active' => true]);

        app(AiHealthService::class)->forget();

        return back()->with('status', 'ai-model-activated');
    }

    public function test(Request $request, AiModel $aiModel): RedirectResponse
    {
        $this->authorizeAdmin($request);

        try {
            if ($aiModel->provider === AiProvider::OpenAi) {
                $response = Http::timeout(15)
                    ->withToken((string) $aiModel->api_key)
                    ->acceptJson()
                    ->post(rtrim((string) $aiModel->base_url, '/').'/v1/chat/completions', [
                        'model' => $aiModel->model,
                        'messages' => [['role' => 'user', 'content' => 'ping']],
                        'stream' => false,
                        'max_tokens' => 1,
                    ]);
            } else {
                $baseUrl = $aiModel->base_url ?: (string) config('ai.ollama.url');
                $response = Http::timeout(15)->acceptJson()->get(rtrim($baseUrl, '/').'/api/tags');
            }

            if ($response->successful()) {
                return back()->with('status', 'ai-model-test-ok');
            }

            return back()->withErrors([
                'ai_model_test' => 'Verbindung fehlgeschlagen (Status '.$response->status().').',
            ]);
        } catch (Throwable $e) {
            return back()->withErrors([
                'ai_model_test' => 'Verbindung fehlgeschlagen: '.$e->getMessage(),
            ]);
        }
    }

    public function destroy(Request $request, AiModel $aiModel): RedirectResponse
    {
        $this->authorizeAdmin($request);

        if ($aiModel->is_default) {
            return back()->withErrors([
                'ai_model' => 'Das Standard-Modell (Gemma, lokal) kann nicht gelöscht werden.',
            ]);
        }

        $wasActive = $aiModel->is_active;
        $aiModel->delete();

        // Fällt das aktive Modell weg, wieder auf den Standard zurückschalten.
        if ($wasActive) {
            AiModel::query()->where('is_default', true)->update(['is_active' => true]);
            app(AiHealthService::class)->forget();
        }

        return back()->with('status', 'ai-model-deleted');
    }

    private function ensureDefault(): void
    {
        $default = AiModel::query()->firstOrCreate(
            ['is_default' => true],
            [
                'label' => 'Gemma (lokal)',
                'provider' => AiProvider::Ollama->value,
                'model' => (string) config('ai.ollama.model'),
                'base_url' => null,
                'api_key' => null,
                'is_active' => true,
            ],
        );

        if (! AiModel::query()->where('is_active', true)->exists()) {
            $default->update(['is_active' => true]);
        }
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasRole('Admin') ?? false, HttpResponse::HTTP_FORBIDDEN);
    }
}
