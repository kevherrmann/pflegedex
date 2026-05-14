<?php

use App\Enums\RosterStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rosters', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('location_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status')->default(RosterStatus::Draft->value);
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->foreignUuid('created_by')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['location_id', 'year', 'month']);
            $table->index('status');
            $table->index(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rosters');
    }
};
