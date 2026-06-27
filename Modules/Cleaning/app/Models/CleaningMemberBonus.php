<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\CleaningAutomationRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Cleaning\Enums\CleaningMemberBonusStatus;

final class CleaningMemberBonus extends Model
{
    protected $fillable = [
        'customer_id',
        'cleaning_automation_rule_id',
        'status',
        'trigger_type',
        'reward_type',
        'reward_value',
        'earned_hours',
        'required_hours',
        'period_months',
        'qualifying_started_at',
        'qualifying_ended_at',
        'activated_by',
        'activated_at',
        'expires_at',
        'admin_note',
    ];

    public function casts(): array
    {
        return [
            'status' => CleaningMemberBonusStatus::class,
            'reward_value' => 'decimal:2',
            'earned_hours' => 'decimal:2',
            'required_hours' => 'decimal:2',
            'period_months' => 'integer',
            'qualifying_started_at' => 'datetime',
            'qualifying_ended_at' => 'datetime',
            'activated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CleaningAutomationRule::class, 'cleaning_automation_rule_id');
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function isPending(): bool
    {
        return $this->status === CleaningMemberBonusStatus::Pending;
    }
}
