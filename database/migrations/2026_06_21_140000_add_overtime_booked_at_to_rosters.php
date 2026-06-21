<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rosters', function (Blueprint $table): void {
            // Zeitpunkt, zu dem die Überstunden dieses Plans auf die Mitarbeiterkonten
            // gebucht wurden (beim Veröffentlichen). NULL = noch nicht gebucht.
            $table->timestamp('overtime_booked_at')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('rosters', function (Blueprint $table): void {
            $table->dropColumn('overtime_booked_at');
        });
    }
};
