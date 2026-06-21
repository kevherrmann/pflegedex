<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            // Bundesland-Kürzel (z.B. BY) für bundeslandabhängige Feiertage.
            $table->string('state', 2)->nullable()->after('short_name');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropColumn('state');
        });
    }
};
