<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Risikomatrix pro SIS.
 *
 * Sechs feste Risikoarten (Dekubitus, Sturz, Inkontinenz, Schmerz,
 * Ernaehrung, Sonstiges). Bei SIS-Anlage werden alle 6 vorinitialisiert,
 * Defaults: is_at_risk=false, needs_further_assessment=false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sis_risks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('sis_id')
                ->constrained('sis_assessments')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('risk_kind', 32);
            $table->boolean('is_at_risk')->default(false);
            $table->boolean('needs_further_assessment')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['sis_id', 'risk_kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sis_risks');
    }
};
