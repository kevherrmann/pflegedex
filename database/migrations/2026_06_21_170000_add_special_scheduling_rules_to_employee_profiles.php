<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table): void {
            // Sonderregelungen zwischen Mitarbeiter und PDL für die Dienstplanung.
            $table->boolean('avoids_weekends')->default(false)->after('can_work_night');
            // null = kein Rhythmus, 'even'/'odd' = arbeitet nur in geraden/ungeraden KW.
            $table->string('week_rotation')->nullable()->after('avoids_weekends');
            // ISO-Wochentage (1=Mo … 7=So), an denen der Mitarbeiter immer frei hat.
            $table->json('fixed_free_weekdays')->nullable()->after('week_rotation');
            // Individuelles Limit aufeinanderfolgender Arbeitstage (überschreibt das globale, wenn kleiner).
            $table->unsignedTinyInteger('max_consecutive_days_override')->nullable()->after('fixed_free_weekdays');
            // Freitext für sonstige Absprachen (nur informativ, nicht erzwungen).
            $table->text('scheduling_note')->nullable()->after('max_consecutive_days_override');
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'avoids_weekends',
                'week_rotation',
                'fixed_free_weekdays',
                'max_consecutive_days_override',
                'scheduling_note',
            ]);
        });
    }
};
