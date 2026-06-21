<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Macht ein Model revisionssicher (append-only): einmal erzeugte Eintraege duerfen
 * inhaltlich nicht mehr geaendert oder geloescht werden. Inhaltlich heisst: die
 * Snapshot-/Begruendungsspalten. Die Attribution (`created_by`) darf sich aendern,
 * damit das Loeschen eines Nutzers (FK nullOnDelete) moeglich bleibt.
 *
 * Erste Verteidigungslinie auf App-Ebene; auf PostgreSQL erzwingen zusaetzlich
 * Datenbank-Trigger dasselbe (siehe Migration *_make_audit_and_versions_append_only).
 */
trait AppendOnly
{
    /** @var list<string> Inhaltsspalten, die unveraenderlich sind. */
    private static array $appendOnlyImmutable = ['content_snapshot', 'snapshot_reason'];

    public static function bootAppendOnly(): void
    {
        static::deleting(function (Model $model): void {
            throw new RuntimeException(
                'Dieser Eintrag ist revisionssicher (append-only) und darf nicht geloescht werden.',
            );
        });

        static::updating(function (Model $model): void {
            foreach (self::$appendOnlyImmutable as $column) {
                if ($model->isDirty($column)) {
                    throw new RuntimeException(
                        'Versionsinhalte sind revisionssicher (append-only) und unveraenderlich.',
                    );
                }
            }
        });
    }
}
