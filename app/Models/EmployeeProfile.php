<?php

namespace App\Models;

use App\Enums\EmploymentArea;
use App\Enums\QualificationLevel;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeProfile extends Model
{
    use HasFactory;
    use HasUuidV7;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'employment_area',
        'is_nursing_specialist',
        'qualification_level',
        'weekly_hours',
        'regular_work_days_per_week',
        'annual_vacation_days',
        'vacation_days_carried_over',
        'overtime_minutes_balance',
        'can_work_early',
        'can_work_late',
        'can_work_night',
        'avoids_weekends',
        'week_rotation',
        'fixed_free_weekdays',
        'max_consecutive_days_override',
        'scheduling_note',
        'maternity_protection',
        'active',
    ];

    protected $attributes = [
        'employment_area' => 'nursing',
        'is_nursing_specialist' => false,
        'weekly_hours' => 39.00,
        'annual_vacation_days' => 30,
        'vacation_days_carried_over' => 0,
        'overtime_minutes_balance' => 0,
        'can_work_early' => true,
        'can_work_late' => true,
        'can_work_night' => false,
        'avoids_weekends' => false,
        'active' => true,
    ];

    protected function casts(): array
    {
        return [
            'employment_area' => EmploymentArea::class,
            'is_nursing_specialist' => 'boolean',
            'qualification_level' => QualificationLevel::class,
            'weekly_hours' => 'decimal:2',
            'regular_work_days_per_week' => 'integer',
            'annual_vacation_days' => 'integer',
            'vacation_days_carried_over' => 'integer',
            'overtime_minutes_balance' => 'integer',
            'can_work_early' => 'boolean',
            'can_work_late' => 'boolean',
            'can_work_night' => 'boolean',
            'avoids_weekends' => 'boolean',
            'fixed_free_weekdays' => 'array',
            'max_consecutive_days_override' => 'integer',
            'maternity_protection' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canRequestAbsence(): bool
    {
        return $this->active && $this->employment_area->canRequestAbsence();
    }

    public function isNursing(): bool
    {
        return $this->employment_area === EmploymentArea::Nursing;
    }

    public function isCaregiverEligibleForRoster(): bool
    {
        return $this->active && $this->employment_area === EmploymentArea::Nursing;
    }
}
