<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dokumentierte Begruendung, wenn eine PDL einen Antrag trotz Urlaubssperre
     * als Einzelfall-Ausnahme genehmigt (rechtlich geforderte Einzelpruefung).
     */
    public function up(): void
    {
        Schema::table('absence_requests', function (Blueprint $table): void {
            $table->text('override_reason')->nullable()->after('rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('absence_requests', function (Blueprint $table): void {
            $table->dropColumn('override_reason');
        });
    }
};
