<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_wishes', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('user_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignUuid('location_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->date('date');

            // wish_free oder wish_shift, siehe App\Enums\ShiftWishKind.
            $table->string('kind');

            // Optional: gewünschte Schichtvorlage bei Wunschdiensten.
            $table->foreignUuid('shift_template_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->text('note')->nullable();

            $table->foreignUuid('created_by')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->timestamps();

            // Ein Wunsch pro Mitarbeiter und Tag.
            $table->unique(['user_id', 'date']);
            $table->index(['location_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_wishes');
    }
};
