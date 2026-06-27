<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Cleaning\Models\CleaningMemberBonus;

final class CleaningAutomationRule extends Model
{
    public const TYPE_REWARD = 'reward';

    public const TRIGGER_TOTAL_HOURS = 'total_hours';

    public const REWARD_FREE_HOURS = 'free_hours';

    protected $fillable = [
        'name',
        'type',
        'trigger_type',
        'reward_type',
        'reward_value',
        'min_hours',
        'period_months',
        'is_active',
    ];

    public function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'reward_value' => 'decimal:2',
            'min_hours' => 'decimal:2',
            'period_months' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (self $rule): void {
            $rule->type = $rule->type ?: self::TYPE_REWARD;
            $rule->trigger_type = $rule->trigger_type ?: self::TRIGGER_TOTAL_HOURS;
            $rule->reward_type = $rule->reward_type ?: self::REWARD_FREE_HOURS;
        });
    }

    public function memberBonuses(): HasMany
    {
        return $this->hasMany(CleaningMemberBonus::class, 'cleaning_automation_rule_id');
    }
}
