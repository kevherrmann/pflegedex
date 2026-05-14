<?php

use App\Enums\ShiftSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('roster_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignUuid('location_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignUuid('user_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignUuid('shift_template_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->date('date');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('source')->default(ShiftSource::Manual->value);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['roster_id', 'date']);
            $table->index(['user_id', 'date']);
            $table->index(['location_id', 'date']);
            $table->unique(['user_id', 'date', 'shift_template_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
