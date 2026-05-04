<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audits-Tabelle fuer owen-it/laravel-auditing, angepasst an Pflegedex:
 *  - user_id ist UUID (statt unsigned bigint), da unser User-Modell UUIDv7 nutzt
 *  - audits.id ist UUIDv7, konsistent mit allen anderen Domain-Tabellen
 *  - auditable_id ist UUID (fuer alle aktuell auditierten Modelle: User, Resident,
 *    CareReport, Location). Falls spaeter ein bigInt-Modell auditiert werden soll,
 *    muss das hier zu morphs() umgestellt oder per separater Tabelle abgebildet werden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_type')->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('event');
            $table->string('auditable_type');
            $table->uuid('auditable_id');
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->text('url')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 1023)->nullable();
            $table->string('tags')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'user_type']);
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audits');
    }
};
