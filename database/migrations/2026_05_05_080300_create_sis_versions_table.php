<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only Versionsarchiv fuer SIS.
 *
 * Bei jeder Aenderung der SIS (Update oder Evaluation) wird vor dem
 * Schreiben ein vollstaendiger JSON-Snapshot des bisherigen Zustands
 * (Header + Themenfelder + Risiken) angelegt. Analog zu report_versions
 * fuer CareReport.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sis_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('sis_id')
                ->constrained('sis_assessments')
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

            $table->index(['sis_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sis_versions');
    }
};
