<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('care_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resident_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->dateTime('occurred_at');
            $table->string('category', 80);
            $table->text('body');
            $table->timestamps();

            $table->index(['location_id', 'occurred_at']);
            $table->index(['resident_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('care_reports');
    }
};
