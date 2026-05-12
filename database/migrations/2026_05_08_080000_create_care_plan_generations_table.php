<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MP-Generierungen: Job-Status fuer KI-Erstellung des Massnahmenplans
 * aus der fertiggestellten SIS.
 *
 * Wird vom GenerateCarePlanJob beschrieben (status, progress,
 * finished_at, error). Frontend pollt den letzten Eintrag, bis
 * status='completed' oder 'failed'.
 *
 * input_snapshot: SIS-Inhalt zum Zeitpunkt des Job-Starts.
 * output_snapshot: Was die KI fuer Grundbotschaft + 16 Themenbloecke
 * geliefert hat.
 *
 * total_steps = 17 (Grundbotschaft + 16 Themen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_plan_generations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('care_plan_id')
                ->constrained('care_plans')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignUuid('triggered_by')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            // pending | running | completed | failed
            $table->string('status', 16)->default('pending');
            // 0 .. 17 (Grundbotschaft + 16 Themenbloecke)
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedTinyInteger('total_steps')->default(17);
            $table->longText('input_snapshot')->nullable();
            $table->longText('output_snapshot')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['care_plan_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_plan_generations');
    }
};
