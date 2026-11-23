<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class WorkerComplianceHealthAlertService
{
    /**
     * Alert authorized operations staff about telemetry outages, not worker
     * misconduct. The notification intentionally contains no worker identity,
     * coordinates or customer address.
     */
    public function notifyIfNeeded(): int
    {
        if (! (bool) config('cleaning.worker_compliance.enabled', false)
            || ! (bool) config('cleaning.worker_compliance.notifications_enabled', false)) {
            return 0;
        }

        $affected = DB::table('worker_compliance_states')
            ->where('is_enrolled', true)
            ->whereNotNull('epoch')
            ->whereNull('revoked_at')
            ->where(static function ($query): void {
                $query->where('last_contact_at', '<', now()->subMinutes(20))
                    ->orWhere('last_reported_permission_state', 'denied')
                    ->orWhere('last_reported_location_services_enabled', false);
            })
            ->count();

        if ($affected === 0) {
            return 0;
        }

        $reviewers = User::query()
            ->permission('cleaning.worker_compliance.overview.view')
            ->where('is_active', true)
            ->whereNull('module_type')
            ->get();

        if ($reviewers->isEmpty()) {
            return 0;
        }

        // Cache::add is atomic and stops repeated scheduler/worker processes
        // from spamming the staff inbox for the same continuing interruption.
        if (! Cache::add('worker-compliance:health-alert:active', true, now()->addMinutes(55))) {
            return 0;
        }

        Notification::make()
            ->title('تحتاج حالة اتصال الموقع إلى مراجعة')
            ->body('يوجد '.$affected.' من حسابات التجربة بلا تحديث موثوق أو بإذن موقع متوقف. هذا ليس دليلاً على مخالفة.')
            ->sendToDatabase($reviewers);

        return (int) $affected;
    }
}
