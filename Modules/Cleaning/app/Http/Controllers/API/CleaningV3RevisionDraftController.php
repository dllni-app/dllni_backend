<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningV3PendingRevisionService;

final class CleaningV3RevisionDraftController
{
    /** Authoritative read-only capability, using the same locked draft
     * validation as the revision endpoint. No draft data leaves this check. */
    public function available(int $customerId, int $bookingId): bool
    {
        if (! config('cleaning_v3_catalog.new_booking_admission_enabled', false)) {
            return false;
        }
        try {
            return DB::transaction(function () use ($customerId, $bookingId): bool {
                $owned = CleaningBooking::query()->whereKey($bookingId)
                    ->where('customer_id', $customerId)->lockForUpdate()->first();
                if ($owned === null) {
                    return false;
                }
                $revision = (int) $owned->sessions()->max('price_revision');
                [$booking, $visits, $quote] = app(CleaningV3PendingRevisionService::class)
                    ->lock($customerId, $bookingId, $revision);
                return $this->revisionDraftAvailable($booking, $visits, $quote);
            }, 3);
        } catch (DomainException) {
            return false;
        }
    }

    private function revisionDraftAvailable(CleaningBooking $booking, $visits, object $quote): bool
    {
        $draft = json_decode((string) $quote->v3_confirmed_draft, true);
        return is_array($draft) && ! isset($draft['eventRule'])
            && (! isset($draft['recurrenceRule'])
                || (data_get($booking->property_details, 'v3_daily_origin_quote_id') === $quote->id
                    && $visits->count() === 1));
    }

    public function __invoke(Request $request, int $bookingId): JsonResponse
    {
        abort_unless(config('cleaning_v3_catalog.new_booking_admission_enabled', false), 503);
        $customerId = (int) $request->user()->id;
        try {
            $data = DB::transaction(function () use ($bookingId, $customerId) {
                $owned = CleaningBooking::query()->whereKey($bookingId)->where('customer_id', $customerId)->lockForUpdate()->firstOrFail();
                $revision = (int) $owned->sessions()->max('price_revision');
                [$booking, $visits, $quote] = app(CleaningV3PendingRevisionService::class)->lock($customerId, $bookingId, $revision);
                $draft = json_decode((string) $quote->v3_confirmed_draft, true);
                if (! $this->revisionDraftAvailable($booking, $visits, $quote)) {
                    throw new DomainException('V3_REVISION_DRAFT_REVIEW_REQUIRED');
                }
                if (isset($draft['recurrenceRule'])) {
                    if (data_get($booking->property_details, 'v3_daily_origin_quote_id') !== $quote->id || $visits->count() !== 1) {
                        throw new DomainException('V3_REVISION_DRAFT_REVIEW_REQUIRED');
                    }
                    $visit = $visits->first();
                    unset($draft['recurrenceRule']);
                    $draft['appointments'] = [[
                        'appointmentKey' => $visit->appointment_key,
                        'visits' => [[
                            'occurrenceKey' => $visit->occurrence_key,
                            'scheduledDate' => $visit->scheduled_date->format('Y-m-d'),
                            'scheduledTime' => mb_substr((string) $visit->scheduled_time, 0, 5),
                            'durationMinutes' => (int) round((float) $visit->duration_hours * 60),
                            'requiredWorkers' => $visit->requiredWorkerCount(),
                        ]],
                    ]];
                }
                unset($draft['expectedPricingFingerprint']);
                return ['bookingId' => $bookingId, 'previousQuoteId' => $quote->id,
                    'priceRevision' => $revision, 'totalSyp' => bcadd((string) $booking->getRawOriginal('total_price'), '0', 2), 'draft' => $draft];
            }, 3);
            return response()->json(['success' => true, 'data' => $data], 200, ['Cache-Control' => 'private, no-store']);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'code' => $e->getMessage(),
                'message' => 'لا يمكن تعديل هذا الحجز من هذا المسار. تواصل مع الإدارة.'], 409, ['Cache-Control' => 'private, no-store']);
        }
    }
}
