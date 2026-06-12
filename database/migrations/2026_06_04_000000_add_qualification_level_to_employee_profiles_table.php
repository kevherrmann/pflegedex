<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fuegt die Qualifikationsstufe (PeBeM-Qualifikationsmix) hinzu. Nur fuer
     * Pflegekraefte relevant, daher nullable. is_nursing_specialist bleibt als
     * vom Generator genutztes Fachkraft-Flag bestehen und wird konsistent
     * gehalten (Stufe "specialist" entspricht Fachkraft).
     */
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table): void {
            $table->string('qualification_level')
                ->nullable()
                ->after('is_nursing_specialist');
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table): void {
            $table->dropColumn('qualification_level');
        });
    }
};
