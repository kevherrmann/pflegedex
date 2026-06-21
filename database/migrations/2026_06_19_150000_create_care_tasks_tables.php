<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Geplante Pflegemassnahmen (Leistungen) je Bewohner.
        Schema::create('care_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('resident_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('title', 200);
            $table->string('category', 40);
            $table->string('schedule', 120)->nullable(); // z.B. "täglich morgens", "3x täglich"
            $table->text('description')->nullable();      // Freitext-Gesundheitsdatum -> verschluesselt (K1)
            $table->boolean('active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['resident_id', 'active']);
            $table->index(['location_id', 'active']);
        });

        // Tägliche Durchfuehrungs-Quittierung (geplant != geleistet).
        Schema::create('care_task_completions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('care_task_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignUuid('resident_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->date('performed_on');
            $table->string('status', 20);
            $table->text('note')->nullable();             // verschluesselt (K1)
            $table->foreignUuid('performed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->dateTime('performed_at');
            $table->timestamps();

            $table->index(['care_task_id', 'performed_on']);
            $table->index(['resident_id', 'performed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_task_completions');
        Schema::dropIfExists('care_tasks');
    }
};
