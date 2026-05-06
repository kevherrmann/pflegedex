<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIS-Generierungen: Job-Status fuer KI-Ausformulierung.
 *
 * Wird vom GenerateSisJob beschrieben (status, progress, finished_at, error).
 * Das Frontend pollt den letzten Eintrag pro SIS, bis status='completed' oder 'failed'.
 *
 * input_snapshot enthaelt die Stichpunkte/Texte zum Zeitpunkt des Job-Starts
 * (zur Nachvollziehbarkeit). output_snapshot enthaelt die KI-Antworten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sis_generations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('sis_id')
                ->constrained('sis_assessments')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignUuid('triggered_by')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            // pending | running | completed | failed
            $table->string('status', 16)->default('pending');
            // 0 .. 7 (Eingangsfrage + 6 Themenfelder)
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedTinyInteger('total_steps')->default(7);
            $table->longText('input_snapshot')->nullable();
            $table->longText('output_snapshot')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['sis_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sis_generations');
    }
};
