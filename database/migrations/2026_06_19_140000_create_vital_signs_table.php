<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vital_signs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('resident_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->dateTime('measured_at');

            // Messwerte – alle optional, mindestens einer wird im Controller erzwungen.
            $table->unsignedSmallInteger('systolic')->nullable();           // RR systolisch (mmHg)
            $table->unsignedSmallInteger('diastolic')->nullable();          // RR diastolisch (mmHg)
            $table->unsignedSmallInteger('pulse')->nullable();              // Puls (/min)
            $table->unsignedSmallInteger('respiratory_rate')->nullable();   // Atemfrequenz (/min)
            $table->unsignedSmallInteger('oxygen_saturation')->nullable();  // SpO2 (%)
            $table->unsignedSmallInteger('blood_sugar')->nullable();        // Blutzucker (mg/dl)
            $table->decimal('temperature', 4, 1)->nullable();               // Temperatur (°C)
            $table->decimal('weight', 5, 1)->nullable();                    // Gewicht (kg)

            // Freitext – Gesundheitsdatum, daher verschluesselt (K1).
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['resident_id', 'measured_at']);
            $table->index(['location_id', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vital_signs');
    }
};
