<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residents', function (Blueprint $table): void {
            // Aufenthaltsstatus + Aufnahme/Entlassung
            $table->string('status', 20)->default('present')->after('care_level');
            $table->date('admitted_on')->nullable()->after('status');
            $table->date('discharged_on')->nullable()->after('admitted_on');

            // Kostenträger / Versicherung
            $table->string('health_insurance', 150)->nullable()->after('discharged_on'); // Pflegekasse
            // text statt varchar: verschlüsselte Werte (encrypted cast) sind deutlich länger.
            $table->text('insurance_number')->nullable()->after('health_insurance');

            // Ärztliche Versorgung & rechtliche Vertretung
            $table->string('family_doctor', 150)->nullable()->after('insurance_number');
            $table->string('family_doctor_phone', 50)->nullable()->after('family_doctor');
            $table->string('guardian_name', 150)->nullable()->after('family_doctor_phone'); // Betreuer/Bevollmächtigter
            $table->string('guardian_phone', 50)->nullable()->after('guardian_name');

            // Verfügungen
            $table->boolean('has_living_will')->default(false)->after('guardian_phone'); // Patientenverfügung
            $table->boolean('has_healthcare_proxy')->default(false)->after('has_living_will'); // Vorsorgevollmacht

            // Gesundheitsdaten (verschlüsselt)
            $table->text('allergies')->nullable()->after('has_healthcare_proxy');
            $table->text('diagnoses')->nullable()->after('allergies'); // Diagnosen / ICD (Freitext)

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('residents', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn([
                'status', 'admitted_on', 'discharged_on',
                'health_insurance', 'insurance_number',
                'family_doctor', 'family_doctor_phone', 'guardian_name', 'guardian_phone',
                'has_living_will', 'has_healthcare_proxy', 'allergies', 'diagnoses',
            ]);
        });
    }
};
