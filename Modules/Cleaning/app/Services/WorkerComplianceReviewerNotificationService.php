<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Filament\Notifications\Notification;

/**
 * Staff-only notification for a potential compliance review.
 * Contains a generic case reference, never site/customer/precise location.
 */
final class WorkerComplianceReviewerNotificationService
{
    public function notifyForNewCase(string $caseReference): void
    {
        if (! (bool) config('cleaning.worker_compliance.notifications_enabled', false)) {
            return;
        }

        $reviewers = User::query()
            ->permission('cleaning.worker_compliance.cases.view')
            ->where('is_active', true)
            ->whereNull('module_type')
            ->get();
        if ($reviewers->isEmpty()) {
            return;
        }

        Notification::make()
            ->title('مراجعة التزام العاملين')
            ->body('حالة جديدة تحتاج إلى مراجعة. المرجع: '.$caseReference)
            ->sendToDatabase($reviewers);
    }
}
