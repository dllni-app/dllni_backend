<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningAutomationRule;
use App\Models\User;
use App\Notifications\NewCleaningMemberBonusDashboardNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningMemberBonusStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningMemberBonus;

final class CleaningLoyaltyAutomationService
{
    public function evaluateCompletedBooking(CleaningBooking $booking): void
    {
        if ($booking->status !== CleaningBookingStatus::Completed || $booking->customer_id === null) {
            return;
        }

        $completedAt = $booking->customer_confirmed_at
            ?? $booking->work_finished_at
            ?? $booking->updated_at
            ?? now();

        CleaningAutomationRule::query()
            ->where('is_active', true)
            ->where('type', CleaningAutomationRule::TYPE_REWARD)
            ->where('trigger_type', CleaningAutomationRule::TRIGGER_TOTAL_HOURS)
            ->whereNotNull('min_hours')
            ->where('min_hours', '>', 0)
            ->orderBy('min_hours')
            ->get()
            ->each(function (CleaningAutomationRule $rule) use ($booking, $completedAt): void {
                $bonus = $this->createPendingBonusIfQualified($booking, $rule, $completedAt);

                if ($bonus instanceof CleaningMemberBonus) {
                    $this->notifyAdmins($bonus);
                }
            });
    }

    public function activate(CleaningMemberBonus $bonus, ?User $admin = null, ?string $adminNote = null): CleaningMemberBonus
    {
        return DB::transaction(function () use ($bonus, $admin, $adminNote): CleaningMemberBonus {
            $lockedBonus = CleaningMemberBonus::query()
                ->whereKey($bonus->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedBonus->isPending()) {
                return $lockedBonus;
            }

            $lockedBonus->forceFill([
                'status' => CleaningMemberBonusStatus::Activated,
                'activated_by' => $admin?->id,
                'activated_at' => now(),
                'admin_note' => $adminNote,
            ])->save();

            return $lockedBonus->fresh(['customer', 'rule', 'activatedBy']) ?? $lockedBonus;
        });
    }

    private function createPendingBonusIfQualified(CleaningBooking $booking, CleaningAutomationRule $rule, CarbonInterface $completedAt): ?CleaningMemberBonus
    {
        $periodMonths = max(1, (int) ($rule->period_months ?? 1));
        $periodStart = $completedAt->copy()->subMonths($periodMonths);
        $requiredHours = (float) $rule->min_hours;
        $earnedHours = $this->earnedHours($booking->customer_id, $periodStart, $completedAt);

        if ($earnedHours < $requiredHours) {
            return null;
        }

        return DB::transaction(function () use ($booking, $rule, $periodMonths, $periodStart, $completedAt, $requiredHours, $earnedHours): ?CleaningMemberBonus {
            $alreadyAwardedInPeriod = CleaningMemberBonus::query()
                ->where('customer_id', $booking->customer_id)
                ->where('cleaning_automation_rule_id', $rule->id)
                ->whereIn('status', CleaningMemberBonusStatus::activeValues())
                ->where('created_at', '>=', $periodStart)
                ->lockForUpdate()
                ->exists();

            if ($alreadyAwardedInPeriod) {
                return null;
            }

            return CleaningMemberBonus::query()->create([
                'customer_id' => $booking->customer_id,
                'cleaning_automation_rule_id' => $rule->id,
                'status' => CleaningMemberBonusStatus::Pending,
                'trigger_type' => $rule->trigger_type,
                'reward_type' => $rule->reward_type,
                'reward_value' => $rule->reward_value,
                'earned_hours' => $earnedHours,
                'required_hours' => $requiredHours,
                'period_months' => $periodMonths,
                'qualifying_started_at' => $periodStart,
                'qualifying_ended_at' => $completedAt,
            ]);
        });
    }

    private function earnedHours(int $customerId, CarbonInterface $periodStart, CarbonInterface $periodEnd): float
    {
        return (float) CleaningBooking::query()
            ->where('customer_id', $customerId)
            ->where('status', CleaningBookingStatus::Completed->value)
            ->whereNotNull('customer_confirmed_at')
            ->whereBetween('customer_confirmed_at', [$periodStart, $periodEnd])
            ->sum('total_hours');
    }

    private function notifyAdmins(CleaningMemberBonus $bonus): void
    {
        $bonus->loadMissing(['customer', 'rule']);

        User::role(['admin', 'Super Admin', 'Cleaning Ops Manager'])
            ->where('is_active', true)
            ->get()
            ->each(static function (User $admin) use ($bonus): void {
                $admin->notify(new NewCleaningMemberBonusDashboardNotification($bonus));
            });
    }
}
