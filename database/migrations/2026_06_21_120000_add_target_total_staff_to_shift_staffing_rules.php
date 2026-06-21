<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_staffing_rules', function (Blueprint $table): void {
            // Idealbesetzung (Sollbesetzung) – der Generator füllt bis hierher auf,
            // solange Mitarbeitende ihr Monats-Soll noch nicht erreicht haben, geht
            // aber nie unter required_total_staff. NULL = keine Aufstockung (nur Mindestbesetzung).
            $table->unsignedTinyInteger('target_total_staff')
                ->nullable()
                ->after('required_total_staff');
        });
    }

    public function down(): void
    {
        Schema::table('shift_staffing_rules', function (Blueprint $table): void {
            $table->dropColumn('target_total_staff');
        });
    }
};
