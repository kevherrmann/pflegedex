<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('resident_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('period', 7);   // Erhebungshalbjahr "YYYY-H1" / "YYYY-H2"
            $table->date('assessed_on');
            $table->text('answers');       // verschlüsselt (encrypted:array): Indikator => Wert
            $table->text('note')->nullable(); // verschlüsselt
            $table->foreignUuid('assessed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->unique(['resident_id', 'period']);
            $table->index(['location_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_assessments');
    }
};
