<?php

use App\Enums\AbsenceRequestStatus;
use App\Enums\AbsenceRequestType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('absence_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('user_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignUuid('location_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->string('type')->default(AbsenceRequestType::Vacation->value);

            $table->date('starts_on');
            $table->date('ends_on');

            $table->decimal('days_count', 5, 2);

            $table->string('status')->default(AbsenceRequestStatus::Requested->value);

            $table->foreignUuid('requested_by')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignUuid('decided_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->timestamp('decided_at')->nullable();

            $table->text('rejection_reason')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'starts_on', 'ends_on']);
            $table->index(['location_id', 'starts_on', 'ends_on']);
            $table->index(['status', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absence_requests');
    }
};
