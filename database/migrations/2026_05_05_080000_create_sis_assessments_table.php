<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Strukturierte Informationssammlung (SIS) - Header pro Bewohner.
 *
 * 1:1-Beziehung zu Resident (unique). Bei Evaluation wird der bisherige
 * Stand in sis_versions snapshot, dann der Datensatz aktualisiert.
 *
 * Fristen lt. Immerso:
 * - Beginn: Tag der Aufnahme (started_at)
 * - Fertigstellung: nach 14 Tagen (completed_at)
 * - Evaluation: turnusmaessig alle 8 Wochen oder bei Bedarf
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sis_assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('resident_id')
                ->unique()
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignUuid('location_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->text('opening_question')->nullable();
            $table->date('started_at');
            $table->date('completed_at')->nullable();
            $table->date('evaluated_at')->nullable();
            $table->date('next_evaluation_due')->nullable();
            $table->foreignUuid('created_by')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignUuid('updated_by')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['location_id', 'next_evaluation_due']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sis_assessments');
    }
};
