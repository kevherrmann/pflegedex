<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('care_report_id')
                ->constrained()
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

            $table->index(['care_report_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_versions');
    }
};
