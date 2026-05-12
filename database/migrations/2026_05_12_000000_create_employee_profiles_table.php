<?php

use App\Enums\EmploymentArea;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('user_id')
                ->unique()
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->string('employment_area')->default(EmploymentArea::Nursing->value);

            $table->boolean('is_nursing_specialist')->default(false);

            $table->decimal('weekly_hours', 5, 2)->default(39.00);
            $table->unsignedTinyInteger('regular_work_days_per_week')->nullable();

            $table->unsignedSmallInteger('annual_vacation_days')->default(30);
            $table->unsignedSmallInteger('vacation_days_carried_over')->default(0);

            $table->integer('overtime_minutes_balance')->default(0);

            $table->boolean('can_work_early')->default(true);
            $table->boolean('can_work_late')->default(true);
            $table->boolean('can_work_night')->default(false);

            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->index(['employment_area', 'active']);
            $table->index(['employment_area', 'is_nursing_specialist']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profiles');
    }
};