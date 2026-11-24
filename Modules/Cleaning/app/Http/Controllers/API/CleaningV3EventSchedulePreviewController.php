<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Modules\Cleaning\Services\CleaningV3EventSchedulePreviewService;

final class CleaningV3EventSchedulePreviewController
{
    public function __invoke(Request $request, CleaningV3EventSchedulePreviewService $service): JsonResponse
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $digest = (string) config('cleaning_v3_catalog.approved_publication_digest', '');
        $key = $request->input('catalogVersionKey');
        $catalog = config('cleaning_v3_catalog.read_enabled', false)
            && preg_match('/^[a-f0-9]{64}$/D', $digest) === 1 && is_string($key)
            ? CleaningV3CatalogVersion::query()->where('version_key', $key)
                ->where('published_digest', $digest)->whereNotNull('published_at')
                ->where('status', CleaningV3CatalogVersion::PUBLISHED)->first()
            : null;
        if ($catalog === null) {
            return response()->json([
                'success' => false, 'code' => 'V3_CATALOG_UNAVAILABLE',
                'message' => 'لا يوجد كتالوج معتمد للمناسبات حالياً.',
            ], 503, $headers);
        }
        if (! is_string($request->input('catalogRevision'))
            || ! hash_equals($digest, $request->input('catalogRevision'))) {
            return response()->json([
                'success' => false, 'code' => 'CATALOG_CHANGED',
                'message' => 'أعد تحميل الكتالوج قبل معاينة المناسبة.',
            ], 409, $headers);
        }
        try {
            $plan = $service->preview($catalog, $request->all());
        } catch (DomainException $exception) {
            $message = match ($exception->getMessage()) {
                'V3_EVENT_CROSS_MIDNIGHT' => 'يجب أن تنتهي زيارات المناسبة ضمن اليوم نفسه. اختر موعداً أبكر أو مواعيد منفصلة.',
                'V3_EVENT_START_INVALID' => 'اختر تاريخاً ووقتاً مستقبليين ضمن السنة القادمة.',
                'V3_EVENT_ITEM_UNAVAILABLE' => 'نوع المناسبة غير متاح في الكتالوج الحالي.',
                default => 'تحقق من مدة المناسبة وعدد العمال والموعد.',
            };
            return response()->json([
                'success' => false, 'code' => $exception->getMessage(), 'message' => $message,
            ], 422, $headers);
        }

        return response()->json(['success' => true, 'data' => $plan], 200, $headers);
    }
}
