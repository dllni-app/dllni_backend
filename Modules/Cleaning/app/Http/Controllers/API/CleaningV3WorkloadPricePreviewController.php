<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Modules\Cleaning\Services\CleaningV3CapacityException;
use Modules\Cleaning\Services\CleaningV3PublishedWorkloadPreviewService;

/**
 * Authenticated immutable published-catalog effort and base tariff preview.
 * It is NOT an approved quote or an offer with any financial legal effect.
 */
final class CleaningV3WorkloadPricePreviewController
{
    public function __invoke(
        Request $request,
        CleaningV3PublishedWorkloadPreviewService $preview,
    ): JsonResponse {
        $headers = ['Cache-Control' => 'private, no-store'];
        $digest = (string) config('cleaning_v3_catalog.approved_publication_digest', '');

        if (! config('cleaning_v3_catalog.read_enabled', false)
            || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            return $this->unavailable($headers);
        }

        $key = $request->input('catalogVersionKey');
        $catalog = is_string($key)
            ? CleaningV3CatalogVersion::query()
                ->where('version_key', $key)
                ->where('published_digest', $digest)
                ->whereNotNull('published_at')
                ->where('status', CleaningV3CatalogVersion::PUBLISHED)
                ->first()
            : null;

        if ($catalog === null) {
            return $this->unavailable($headers);
        }

        if (! is_string($request->input('catalogRevision'))
            || ! hash_equals($digest, $request->input('catalogRevision'))) {
            return response()->json([
                'success' => false,
                'code' => 'CATALOG_CHANGED',
                'message' => 'أعد تحميل الكتالوج قبل حساب المدة والسعر.',
            ], 409, $headers);
        }

        $payload = $request->all();
        $formMode = array_key_exists('answers', $payload) || array_key_exists('cleaningMode', $payload);
        $allowed = $formMode
            ? ['catalogVersionKey', 'catalogRevision', 'answers', 'cleaningMode', 'scopeSelections']
            : ['catalogVersionKey', 'catalogRevision', 'lines'];
        if (array_diff(array_keys($payload), $allowed) !== []
            || ! is_array($payload[$formMode ? 'answers' : 'lines'] ?? null)) {
            return response()->json([
                'success' => false,
                'code' => 'INVALID_V3_PREVIEW_FIELDS',
                'message' => 'بيانات تقدير خدمة التنظيف غير مكتملة أو غير معتمدة.',
            ], 422, $headers);
        }

        try {
            // The server derives all prices/effort from immutable tariff
            // records, never accepting monetary or worker-count overrides.
            $result = $formMode
                ? $preview->previewForm($catalog, $digest, $payload['answers'], $payload['cleaningMode'] ?? null, (array_key_exists('scopeSelections', $payload) ? $payload['scopeSelections'] : []))
                : $preview->preview($catalog, $digest, $payload['lines']);
        } catch (CleaningV3CapacityException $exception) {
            return response()->json([
                'success' => false,
                'code' => $exception->reasonCode,
                'message' => 'لا يمكن إنجاز العمل المطلوب ضمن حدود الزيارة الحالية.',
            ], $exception->httpStatus, $headers);
        } catch (DomainException $exception) {
            return response()->json([
                'success' => false,
                'code' => $exception->getMessage(),
                'message' => 'تحقق من القياسات والبنود قبل إعادة حساب التقدير.',
            ], 422, $headers);
        }

        return response()->json([
            'success' => true,
            'data' => [
                ...$result,
                'quoteId' => null,
                'quoteSignature' => null,
                'quoteExpiresAt' => null,
                'financialState' => 'preview_only',
                'message' => 'تقدير أولي من الكتالوج. السعر النهائي يتطلب عرض سعر معتمداً.',
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
