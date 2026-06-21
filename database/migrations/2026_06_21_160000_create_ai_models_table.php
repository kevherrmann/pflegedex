<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('label');
            // 'ollama' = lokales Modell über Ollama, 'openai' = OpenAI-kompatible
            // externe API (z. B. DeepSeek) – Anbindung über api_key + base_url.
            $table->string('provider')->default('ollama');
            $table->string('model');
            $table->string('base_url')->nullable();
            // API-Key verschlüsselt at-rest (Eloquent 'encrypted'-Cast) → text.
            $table->text('api_key')->nullable();
            $table->boolean('is_active')->default(false);
            // Der lokale Gemma-Standard bleibt immer vorhanden und ist nicht löschbar.
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
