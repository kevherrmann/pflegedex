<?php

use App\Enums\BlackoutScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Erweitert Urlaubssperren um einen Geltungsbereich: ganzer Wohnbereich
     * (bisheriges Verhalten), bestimmte Qualifikationsstufen oder einzelne
     * Mitarbeiter.
     */
    public function up(): void
    {
        Schema::table('roster_blackout_days', function (Blueprint $table): void {
            $table->string('scope')->default(BlackoutScope::All->value)->after('date');
            // Liste von QualificationLevel-Werten, nur bei scope = qualification.
            $table->json('qualification_levels')->nullable()->after('scope');
        });

        Schema::create('roster_blackout_day_user', function (Blueprint $table): void {
            $table->foreignUuid('roster_blackout_day_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignUuid('user_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->primary(['roster_blackout_day_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_blackout_day_user');

        Schema::table('roster_blackout_days', function (Blueprint $table): void {
            $table->dropColumn(['scope', 'qualification_levels']);
        });
    }
};
