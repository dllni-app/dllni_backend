<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_automation_rules', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_automation_rules', 'trigger_type')) {
                $table->string('trigger_type')->default('total_hours')->after('type');
            }

            if (! Schema::hasColumn('cleaning_automation_rules', 'reward_type')) {
                $table->string('reward_type')->default('free_hours')->after('trigger_type');
            }

            if (! Schema::hasColumn('cleaning_automation_rules', 'reward_value')) {
                $table->decimal('reward_value', 10, 2)->default(0)->after('reward_type');
            }

            if (! Schema::hasColumn('cleaning_automation_rules', 'min_hours')) {
                $table->decimal('min_hours', 8, 2)->nullable()->after('reward_value');
            }

            if (! Schema::hasColumn('cleaning_automation_rules', 'period_months')) {
                $table->unsignedSmallInteger('period_months')->nullable()->after('min_hours');
            }
        });

        $this->copyJsonRuleValuesToColumns();

        Schema::table('cleaning_automation_rules', function (Blueprint $table): void {
            if (Schema::hasColumn('cleaning_automation_rules', 'conditions')) {
                $table->dropColumn('conditions');
            }

            if (Schema::hasColumn('cleaning_automation_rules', 'actions')) {
                $table->dropColumn('actions');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_automation_rules', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_automation_rules', 'conditions')) {
                $table->json('conditions')->nullable()->after('is_active');
            }

            if (! Schema::hasColumn('cleaning_automation_rules', 'actions')) {
                $table->json('actions')->nullable()->after('conditions');
            }
        });

        DB::table('cleaning_automation_rules')
            ->orderBy('id')
            ->each(function (object $rule): void {
                DB::table('cleaning_automation_rules')
                    ->where('id', $rule->id)
                    ->update([
                        'conditions' => json_encode([
                            'min_hours' => $rule->min_hours,
                            'period_months' => $rule->period_months,
                        ], JSON_THROW_ON_ERROR),
                        'actions' => json_encode([
                            'reward_type' => $rule->reward_type,
                            'reward_value' => $rule->reward_value,
                        ], JSON_THROW_ON_ERROR),
                    ]);
            });

        Schema::table('cleaning_automation_rules', function (Blueprint $table): void {
            foreach (['trigger_type', 'reward_type', 'reward_value', 'min_hours', 'period_months'] as $column) {
                if (Schema::hasColumn('cleaning_automation_rules', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function copyJsonRuleValuesToColumns(): void
    {
        if (! Schema::hasColumn('cleaning_automation_rules', 'conditions') && ! Schema::hasColumn('cleaning_automation_rules', 'actions')) {
            return;
        }

        DB::table('cleaning_automation_rules')
            ->orderBy('id')
            ->each(function (object $rule): void {
                $conditions = $this->decodeJsonColumn($rule->conditions ?? null);
                $actions = $this->decodeJsonColumn($rule->actions ?? null);

                DB::table('cleaning_automation_rules')
                    ->where('id', $rule->id)
                    ->update([
                        'trigger_type' => $this->stringValue($conditions['trigger_type'] ?? $conditions['triggerType'] ?? null, 'total_hours'),
                        'reward_type' => $this->stringValue($actions['reward_type'] ?? $actions['rewardType'] ?? $rule->reward_type ?? null, 'free_hours'),
                        'reward_value' => $this->numericValue($actions['reward_value'] ?? $actions['rewardValue'] ?? $rule->reward_value ?? null, 0.0),
                        'min_hours' => $this->numericValue($conditions['min_hours'] ?? $conditions['minHours'] ?? $rule->min_hours ?? null),
                        'period_months' => $this->integerValue($conditions['period_months'] ?? $conditions['periodMonths'] ?? $rule->period_months ?? null),
                    ]);
            });
    }

    /** @return array<string, mixed> */
    private function decodeJsonColumn(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function stringValue(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    private function numericValue(mixed $value, ?float $default = null): ?float
    {
        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        return $default;
    }

    private function integerValue(mixed $value): ?int
    {
        if (is_numeric($value)) {
            return max(1, (int) $value);
        }

        return null;
    }
};
