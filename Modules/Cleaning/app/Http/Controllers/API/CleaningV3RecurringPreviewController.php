<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Modules\Cleaning\Services\CleaningV3WeeklyRecurrencePreviewService;

/**
 * Authenticated, no-cost recurring calendar preview for V3 customers.
 * Does not issue quotes, reserve staff, save visits or affect V2 bookings.
 */
final class CleaningV3RecurringPreviewController
{
    public function __invoke(
        Request $request,
        CleaningV3WeeklyRecurrencePreviewService $recurring,
    ): JsonResponse {
        $headers = ['Cache-Control' => 'private, no-store'];
        $digest = (string) \Modules\Cleaning\Services\CleaningV3AdminCatalogPublication::digest();
        if (! config('cleaning_v3_catalog.read_enabled', false)
            || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            return $this->unavailable($headers);
        }

        $key = $request->input('catalogVersionKey');
        $version = is_string($key)
            ? CleaningV3CatalogVersion::query()
                ->where('version_key', $key)
                ->where('published_digest', $digest)
                ->whereNotNull('published_at')
                ->where('status', CleaningV3CatalogVersion::PUBLISHED)
                ->first()
            : null;

        if ($version === null) {
            return $this->unavailable($headers);
        }
        if (! is_string($request->input('catalogRevision'))
            || ! hash_equals($digest, $request->input('catalogRevision'))) {
            return response()->json([
                'success' => false,
                'code' => 'CATALOG_CHANGED',
                'message' => 'حدّث بيانات خدمة التنظيف قبل مراجعة الجدول.',
            ], 409, $headers);
        }

        try {
            $plan = $recurring->preview($request->all());
        } catch (DomainException $exception) {
            return response()->json([
                'success' => false,
                'code' => $exception->getMessage(),
                'message' => 'يرجى مراجعة تاريخ الحجز الدوري وأيامه ومدته.',
            ], 422, $headers);
        }

        return response()->json([
            'success' => true,
            'data' => [
                ...$plan,
                'quoteId' => null,
                'quoteSignature' => null,
                'canCreateBooking' => false,
                'requiresApprovedPricing' => true,
                'requiresFundingAdmission' => true,
            ],
        ], 200, $headers);
    }

    /** @param array<string,string> $headers */
    private function unavailable(array $headers): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'V3_CATALOG_UNAVAILABLE',
            'message' => 'لا يوجد كتالوج تنظيف ديناميكي معتمد حالياً.',
        ], 503, $headers);
    }
}
