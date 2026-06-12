<?php

namespace App\Services\Rosters\Planning;

/**
 * Kleiner xorshift32-Zufallsgenerator mit eigenem Zustand: gleiche Saat,
 * gleiche Zugfolge, gleicher Dienstplan — unabhängig vom globalen PHP-Zufall.
 */
class DeterministicRandom
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = ($seed & 0xFFFFFFFF) ?: 0x9E3779B9;
    }

    /**
     * Ganzzahl im Bereich [0, $maxExclusive).
     */
    public function nextInt(int $maxExclusive): int
    {
        $this->state ^= ($this->state << 13) & 0xFFFFFFFF;
        $this->state ^= $this->state >> 17;
        $this->state ^= ($this->state << 5) & 0xFFFFFFFF;

        return $maxExclusive <= 1 ? 0 : $this->state % $maxExclusive;
    }
}
