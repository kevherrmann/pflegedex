<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table): void {
            // Aktiver Mutterschutz / Beschäftigungsverbot: kein Nacht-/Sonn-/Feiertagsdienst (MuSchG).
            $table->boolean('maternity_protection')->default(false)->after('can_work_night');
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table): void {
            $table->dropColumn('maternity_protection');
        });
    }
};
