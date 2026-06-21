<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('resident_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('type', 20);
            $table->date('assessed_on');
            $table->text('answers');                      // verschluesselt (encrypted:array)
            $table->unsignedSmallInteger('total_score')->nullable();
            $table->string('risk_level', 40)->nullable();
            $table->text('note')->nullable();             // verschluesselt (K1)
            $table->date('next_due')->nullable();
            $table->foreignUuid('assessed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['resident_id', 'type', 'assessed_on']);
            $table->index(['location_id', 'next_due']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};
