<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_reports', function (Blueprint $table): void {
            $table->boolean('signed')->default(false)->after('body');
            $table->timestamp('signed_at')->nullable()->after('signed');
            $table->foreignUuid('signed_by')
            ->nullable()
            ->after('signed_at')
            ->constrained('users')
            ->cascadeOnUpdate()
            ->nullOnDelete();

            $table->index(['signed', 'signed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('care_reports', function (Blueprint $table): void {
            $table->dropForeign(['signed_by']);
            $table->dropIndex(['signed', 'signed_at']);
            $table->dropColumn(['signed', 'signed_at', 'signed_by']);
        });
    }
};
