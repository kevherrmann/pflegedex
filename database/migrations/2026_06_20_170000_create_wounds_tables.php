<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wounds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('resident_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('body_site', 150);          // Lokalisation, z.B. "Steiß", "Ferse links"
            $table->string('type', 30);
            $table->boolean('acquired_in_house')->default(false); // im Haus entstanden (QI-relevant)
            $table->date('opened_on');                  // Entstehungs-/Feststellungsdatum
            $table->date('closed_on')->nullable();
            $table->string('status', 20)->default('open');
            $table->text('note')->nullable();           // verschlüsselt (K1)
            $table->foreignUuid('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['resident_id', 'status']);
            $table->index(['location_id', 'status']);
        });

        // Verlaufseinträge / Wunddoku je Wunde.
        Schema::create('wound_assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('wound_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignUuid('resident_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->date('assessed_on');
            $table->string('stage', 20)->nullable();    // Kategorie/Stadium
            $table->unsignedSmallInteger('length_mm')->nullable();
            $table->unsignedSmallInteger('width_mm')->nullable();
            $table->unsignedSmallInteger('depth_mm')->nullable();
            $table->unsignedTinyInteger('pain')->nullable(); // NRS 0–10
            $table->text('wound_description')->nullable(); // Wundgrund/Beschreibung – verschlüsselt
            $table->text('measures')->nullable();          // Maßnahmen/Verbandwechsel – verschlüsselt
            $table->foreignUuid('assessed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['wound_id', 'assessed_on']);
            $table->index(['resident_id', 'assessed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wound_assessments');
        Schema::dropIfExists('wounds');
    }
};
