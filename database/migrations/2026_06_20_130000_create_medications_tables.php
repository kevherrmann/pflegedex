<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Medikationsplan je Bewohner.
        Schema::create('medications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('resident_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name', 200);
            $table->string('form', 20);
            $table->string('strength', 60)->nullable();   // z.B. "5 mg"
            // Einnahmeschema (1-1-1-1), als Text um "1/2", "10 Tropfen" zu erlauben.
            $table->string('dose_morning', 30)->nullable();
            $table->string('dose_noon', 30)->nullable();
            $table->string('dose_evening', 30)->nullable();
            $table->string('dose_night', 30)->nullable();
            $table->boolean('prn')->default(false);        // bei Bedarf
            $table->text('prn_instruction')->nullable();   // verschluesselt (K1)
            $table->boolean('is_btm')->default(false);     // Betäubungsmittel
            $table->string('prescriber', 150)->nullable(); // verordnender Arzt
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->text('note')->nullable();              // verschluesselt (K1)
            $table->boolean('active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['resident_id', 'active']);
            $table->index(['location_id', 'active']);
        });

        // Verabreichungsnachweis (MAR): jede Gabe wird quittiert.
        Schema::create('medication_administrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('medication_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignUuid('resident_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->date('administered_on');
            $table->string('slot', 10);
            $table->string('status', 20);
            $table->foreignUuid('administered_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->dateTime('administered_at');
            // Zweitkraft (Vier-Augen-Prinzip), bei BTM verpflichtend.
            $table->foreignUuid('witness_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->text('note')->nullable();              // verschluesselt (K1)
            $table->timestamps();

            $table->index(['medication_id', 'administered_on']);
            $table->index(['resident_id', 'administered_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_administrations');
        Schema::dropIfExists('medications');
    }
};
