<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_staffing_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('location_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignUuid('shift_template_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('required_total_staff');
            $table->unsignedTinyInteger('required_specialists');
            $table->timestamps();

            $table->index(['location_id', 'weekday']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX shift_staffing_rules_template_weekday_unique
            ON shift_staffing_rules (shift_template_id, weekday)
            WHERE weekday IS NOT NULL',
        );

        DB::statement(
            'CREATE UNIQUE INDEX shift_staffing_rules_template_default_unique
            ON shift_staffing_rules (shift_template_id)
            WHERE weekday IS NULL',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_staffing_rules');
    }
};
