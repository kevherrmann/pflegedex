<?php

namespace App\Support\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

/**
 * UUIDv7 als Primary-Key.
 *
 * Erweitert Laravels HasUuids (das standardmaessig UUIDv4 erzeugt) um die
 * zeitlich sortierbare UUIDv7-Variante. UUIDv7 enthaelt einen Unix-Timestamp
 * im Praefix, wodurch Inserts B-Tree-freundlich sind und IDs implizit nach
 * Erstellungszeit sortiert werden koennen. RFC 9562, Mai 2024.
 */
trait HasUuidV7
{
    use HasUuids;

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return [$this->getKeyName()];
    }
}
