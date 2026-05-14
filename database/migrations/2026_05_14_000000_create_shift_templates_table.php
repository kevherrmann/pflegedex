<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('location_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('code');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('color')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['location_id', 'code']);
            $table->index(['location_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_templates');
    }
};
