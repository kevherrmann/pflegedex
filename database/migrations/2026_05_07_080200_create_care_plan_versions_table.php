<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only Versionsarchiv fuer Massnahmenplaene.
 *
 * Bei jeder Aenderung (Update oder Evaluation) wird vor dem Schreiben
 * ein vollstaendiger JSON-Snapshot des bisherigen Zustands (Header +
 * Themenbloecke) angelegt. Analog zu sis_versions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_plan_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('care_plan_id')
                ->constrained('care_plans')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->longText('content_snapshot');
            $table->string('snapshot_reason', 80);
            $table->foreignUuid('created_by')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['care_plan_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_plan_versions');
    }
};
