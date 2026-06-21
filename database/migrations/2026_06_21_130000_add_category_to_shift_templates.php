<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_templates', function (Blueprint $table): void {
            // Kategorie (early/late/night) – mehrere Vorlagen je Kategorie möglich.
            $table->string('category')->nullable()->after('code');
        });

        // Bestand: bisher trug der code die Kategorie (early/late/night).
        DB::table('shift_templates')->update(['category' => DB::raw('code')]);
    }

    public function down(): void
    {
        Schema::table('shift_templates', function (Blueprint $table): void {
            $table->dropColumn('category');
        });
    }
};
