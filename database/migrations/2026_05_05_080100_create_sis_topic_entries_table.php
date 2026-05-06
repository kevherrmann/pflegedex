<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Themenfelder einer SIS (1..6 lt. Beikirch/Roes-Konzept).
 *
 * Pro SIS gibt es immer 6 Eintraege; sie werden bei SIS-Anlage
 * vorinitialisiert (alle 6 mit content=null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sis_topic_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('sis_id')
                ->constrained('sis_assessments')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('topic_number');
            $table->text('content')->nullable();
            $table->timestamps();

            $table->unique(['sis_id', 'topic_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sis_topic_entries');
    }
};
