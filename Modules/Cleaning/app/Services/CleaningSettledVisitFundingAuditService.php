<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

/**
 * Read-only inspection of historical visits marked settled. Never derives an
 * external funding approval from the session's payment_status field.
 */
final class CleaningSettledVisitFundingAuditService
{
    public function __construct(private readonly CleaningSessionFundingConservationService $conservation) {}

    /** @return list<array{visit:string,status:string,quote:string,worker_amount:string,travel:string,gap:string,reason:string}> */
    public function unresolved(CleaningBooking $booking): array
    {
        $rows = [];
        foreach ($booking->sessions()->where('payment_status', 'settled')->orderBy('sequence')->get() as $session) {
            /** @var CleaningBookingSession $session */
            $assignments = $session->workerAssignments()
                ->where('status', CleaningBookingWorkerAssignmentStatus::Completed->value)
                ->whereNull('released_at')->get();
            $group = $booking->isV2OperationalGroup();
            $result = $this->conservation->assess(
                $session->getRawOriginal('total_price'),
                $assignments->map(static fn ($assignment): array => [
                    'workerAmount' => $assignment->getRawOriginal('worker_amount'),
                    'travelFee' => $assignment->getRawOriginal('travel_fee'),
                ])->all(),
                $session->requiredWorkerCount(),
                // A supervisor's projected hourly rate is not an approved
                // completed obligation or a verified settlement ledger.
                $group ? null : '0.00',
            );

            if ($result['status'] !== 'blocked') {
                continue;
            }
            $rows[] = [
                'visit' => 'الزيارة '.(int) $session->sequence,
                'status' => 'مسجل كمسدّد — يحتاج تدقيق',
                'quote' => (string) ($session->getRawOriginal('total_price') ?? 'غير محدد'),
                'worker_amount' => $result['workerObligations'] ?? 'غير مكتمل',
                'travel' => $result['workerTravelIncluded'] ?? 'غير مكتمل',
                'gap' => $result['minimumUnfunded'] ?? 'لا يمكن تحديده',
                'reason' => implode('، ', $result['reasonCodes']),
            ];
        }

        return $rows;
    }
}
