<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CleaningWorkerDeposit;
use App\Notifications\WorkerDepositCriticalDashboardNotification;
use App\Support\DashboardAdminRecipients;
use Illuminate\Support\Facades\Notification;

final class CleaningWorkerDepositObserver
{
    public function updated(CleaningWorkerDeposit $deposit): void
    {
        if (! $deposit->wasChanged('debt_balance')) {
            return;
        }

        if (! $this->justBecameCritical($deposit)) {
            return;
        }

        $admins = DashboardAdminRecipients::all();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new WorkerDepositCriticalDashboardNotification($deposit));
        }
    }

    /**
     * A deposit account is "critical" once the worker's debt has increased and
     * reached or exceeded their allowed negative balance while they have no
     * deposit balance left to cover it.
     */
    private function justBecameCritical(CleaningWorkerDeposit $deposit): bool
    {
        $maxNegativeBalance = (float) $deposit->max_negative_balance;
        $debtBalanceBefore = (float) $deposit->getOriginal('debt_balance');
        $debtBalanceAfter = (float) $deposit->debt_balance;

        if ($debtBalanceAfter <= $debtBalanceBefore) {
            return false;
        }

        if ((float) $deposit->current_balance > 0) {
            return false;
        }

        return $debtBalanceAfter >= $maxNegativeBalance && $debtBalanceBefore < $maxNegativeBalance;
    }
}
