<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Massnahmenplan (MP) - Header pro Bewohner.
 *
 * 1:1-Beziehung zu Resident (resident_id unique). Voraussetzung fuer
 * die Anlage: die SIS des Bewohners muss bereits fertiggestellt sein
 * (sis_assessments.completed_at IS NOT NULL). Dies wird im Controller
 * geprueft, nicht per DB-Constraint, damit der MP unabhaengig von
 * SIS-Aenderungen weiterleben kann.
 *
 * Lifecycle analog SIS:
 *   - Anlage: started_at = today() (typisch automatisch ueber den
 *     "MP generieren"-Workflow in einem spaeteren Schritt 3c).
 *   - Versionsarchiv: care_plan_versions, append-only.
 *   - Evaluation: turnusmaessig alle 8 Wochen (markEvaluated()),
 *     setzt evaluated_at + next_evaluation_due, schreibt Snapshot.
 *
 * Felder:
 *   - grundbotschaft: kurze Bewohner-uebergreifende Hinweise
 *     ("immer Becher mit Aufsatz", "Pflege nur zu zweit", ...).
 *     Wird im UI ueber den Themenbloecken angezeigt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_plans', function (Blueprint $table): void {
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
            $table->text('grundbotschaft')->nullable();
            $table->date('started_at');
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
        Schema::dropIfExists('care_plans');
    }
};
