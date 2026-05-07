<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anrede des Bewohners (herr/frau).
 *
 * Wird genutzt fuer:
 *  - personalisierte KI-Ausformulierung der SIS (Bewohner/Bewohnerin)
 *  - spaetere Briefe/PDFs/Akten
 *
 * Pflichtfeld - keine leere Anrede zulaessig.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residents', function (Blueprint $table): void {
            // String mit Default 'herr' fuer existierende Datensaetze;
            // beim Fresh-Start (Pilot) seedt der Seeder die korrekte Anrede.
            $table->string('salutation', 8)->default('herr')->after('pseudonym');
        });
    }

    public function down(): void
    {
        Schema::table('residents', function (Blueprint $table): void {
            $table->dropColumn('salutation');
        });
    }
};
