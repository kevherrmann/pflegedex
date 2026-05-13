<?php

namespace App\Enums;

enum AbsenceRequestStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Beantragt',
            self::Approved => 'Genehmigt',
            self::Rejected => 'Abgelehnt',
            self::Cancelled => 'Storniert',
        };
    }

    public function blocksOverlappingRequests(): bool
    {
        return in_array($this, [
            self::Requested,
            self::Approved,
        ], true);
    }
}