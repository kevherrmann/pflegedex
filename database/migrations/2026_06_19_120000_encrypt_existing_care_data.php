<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1 / K1: Bestehende Gesundheits-Freitextdaten werden at-rest verschluesselt.
 *
 * Die zugehoerigen Models haben jetzt 'encrypted'-Casts. Bestandszeilen liegen aber
 * noch im Klartext vor; ein Lesen ueber den Cast wuerde fehlschlagen. Diese Migration
 * verschluesselt die vorhandenen Werte einmalig mit demselben Mechanismus (Crypt).
 * Idempotent: bereits verschluesselte Werte werden uebersprungen.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> Tabelle => zu verschluesselnde Spalten */
    private array $map = [
        'care_reports' => ['body'],
        'report_versions' => ['content_snapshot'],
        'sis_assessments' => ['opening_question'],
        'sis_topic_entries' => ['content'],
        'sis_risks' => ['notes'],
        'sis_versions' => ['content_snapshot'],
        'care_plans' => ['grundbotschaft'],
        'care_plan_topics' => ['content'],
        'care_plan_versions' => ['content_snapshot'],
    ];

    public function up(): void
    {
        foreach ($this->map as $table => $columns) {
            DB::table($table)->orderBy('id')->each(function (object $row) use ($table, $columns): void {
                $updates = [];

                foreach ($columns as $column) {
                    $value = $row->{$column} ?? null;

                    if ($value === null || $value === '' || $this->looksEncrypted((string) $value)) {
                        continue;
                    }

                    $updates[$column] = Crypt::encryptString((string) $value);
                }

                if ($updates !== []) {
                    DB::table($table)->where('id', $row->id)->update($updates);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->map as $table => $columns) {
            DB::table($table)->orderBy('id')->each(function (object $row) use ($table, $columns): void {
                $updates = [];

                foreach ($columns as $column) {
                    $value = $row->{$column} ?? null;

                    if ($value === null || $value === '') {
                        continue;
                    }

                    try {
                        $updates[$column] = Crypt::decryptString((string) $value);
                    } catch (DecryptException) {
                        // War bereits Klartext -> nichts zu tun.
                    }
                }

                if ($updates !== []) {
                    DB::table($table)->where('id', $row->id)->update($updates);
                }
            });
        }
    }

    /**
     * Ein vom Laravel-Encrypter erzeugter Wert ist Base64 eines JSON-Objekts
     * mit den Schluesseln iv/value/mac. Daran erkennen wir bereits verschluesselte Werte.
     */
    private function looksEncrypted(string $value): bool
    {
        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac']);
    }
};
