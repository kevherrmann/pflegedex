<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_blackout_days', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('location_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->date('date');

            $table->text('reason')->nullable();

            $table->boolean('blocks_vacation')->default(true);
            $table->boolean('blocks_overtime_compensation')->default(true);

            $table->foreignUuid('created_by')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['location_id', 'date']);
            $table->index(['date', 'blocks_vacation']);
            $table->index(['date', 'blocks_overtime_compensation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_blackout_days');
    }
};
