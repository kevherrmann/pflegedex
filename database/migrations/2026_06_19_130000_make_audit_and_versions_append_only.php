<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1 / K3: Revisionssicherheit auf Datenbankebene.
 *
 * - `audits`: weder UPDATE noch DELETE (owen-it schreibt nur INSERTs).
 * - Versions-Tabellen (`report_versions`, `sis_versions`, `care_plan_versions`):
 *   kein DELETE; UPDATE nur erlaubt, solange Inhalt/Begruendung unveraendert bleiben.
 *   Die Attributions-Nullung `created_by` (FK nullOnDelete beim Loeschen eines Nutzers)
 *   bleibt damit moeglich, die Beweis-Inhalte sind aber unveraenderlich.
 *
 * Trigger gibt es nur unter PostgreSQL (Produktion + Test-DB). Unter SQLite (lokale Dev)
 * greifen die Eloquent-Guards aus dem AppendOnly-Trait.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $versionTables = ['report_versions', 'sis_versions', 'care_plan_versions'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pflegedex_audits_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Audit-Eintraege sind revisionssicher und duerfen nicht geaendert oder geloescht werden (%).', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS audits_append_only ON audits;
            CREATE TRIGGER audits_append_only
                BEFORE UPDATE OR DELETE ON audits
                FOR EACH ROW EXECUTE FUNCTION pflegedex_audits_append_only();

            CREATE OR REPLACE FUNCTION pflegedex_versions_append_only() RETURNS trigger AS $$
            BEGIN
                IF (TG_OP = 'DELETE') THEN
                    RAISE EXCEPTION 'Versionseintraege sind revisionssicher und duerfen nicht geloescht werden.';
                END IF;
                IF (NEW.content_snapshot IS DISTINCT FROM OLD.content_snapshot
                    OR NEW.snapshot_reason IS DISTINCT FROM OLD.snapshot_reason) THEN
                    RAISE EXCEPTION 'Versionsinhalte sind unveraenderlich (append-only).';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        foreach ($this->versionTables as $table) {
            DB::unprepared(
                "DROP TRIGGER IF EXISTS {$table}_append_only ON {$table};
                 CREATE TRIGGER {$table}_append_only
                     BEFORE UPDATE OR DELETE ON {$table}
                     FOR EACH ROW EXECUTE FUNCTION pflegedex_versions_append_only();"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS audits_append_only ON audits;');

        foreach ($this->versionTables as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_append_only ON {$table};");
        }

        DB::unprepared('DROP FUNCTION IF EXISTS pflegedex_audits_append_only();');
        DB::unprepared('DROP FUNCTION IF EXISTS pflegedex_versions_append_only();');
    }
};
