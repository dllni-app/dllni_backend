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
                if (! is_array($draft) || isset($draft['eventRule']) || isset($draft['recurrenceRule'])) {
                    throw new DomainException('V3_REVISION_DRAFT_REVIEW_REQUIRED');
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
