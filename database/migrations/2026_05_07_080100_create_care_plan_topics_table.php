<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Themenbloecke eines Massnahmenplans (1..16 lt. Handlungsanleitung).
 *
 * Anders als sis_topic_entries werden hier NICHT alle 16 Themen pro MP
 * vorinitialisiert. Themen entstehen on-demand: erst sobald ein Inhalt
 * eingetragen wird (Handlungsanleitung: "Themen, die der Bewohner
 * komplett selbststaendig verrichtet, sind hier nicht beschrieben").
 *
 * unique(care_plan_id, topic_number) verhindert Duplikate pro Thema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_plan_topics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('care_plan_id')
                ->constrained('care_plans')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('topic_number');
            $table->text('content');
            $table->timestamps();

            $table->unique(['care_plan_id', 'topic_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_plan_topics');
    }
};
