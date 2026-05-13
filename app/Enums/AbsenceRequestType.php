<?php

namespace App\Enums;

enum AbsenceRequestType: string
{
    case Vacation = 'vacation';
    case OvertimeCompensation = 'overtime_compensation';
    case Sick = 'sick';
    case Blocked = 'blocked';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Vacation => 'Urlaub',
            self::OvertimeCompensation => 'Überstundenfrei',
            self::Sick => 'Krank',
            self::Blocked => 'Gesperrt',
            self::Other => 'Sonstiges',
        };
    }
}