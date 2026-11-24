<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Modules\Cleaning\Services\CleaningV3CatalogPublicSerializer;
use Modules\Cleaning\Services\CleaningV3CapacityException;
use Modules\Cleaning\Services\CleaningV3ScheduledWorkloadPreviewService;
use Modules\Cleaning\Services\CleaningV3PublishedFormValidator;
use Modules\Cleaning\Services\CleaningV3VisitPlanException;
use Modules\Cleaning\Services\CleaningV3VisitPlanner;

/**
 * Authenticated, read-only customer V3 draft preview.
 * Admission is bound to the exact approved immutable published catalog.
 * No quote, booking, address access, dispatch, money or reservation writes.
 */
final class CleaningV3VisitPlanPreviewController
{
    public function __invoke(
        Request $request,
        CleaningV3VisitPlanner $planner,
        CleaningV3PublishedFormValidator $forms,
        CleaningV3CatalogPublicSerializer $serializer,
    ): JsonResponse {
        $noStore = ['Cache-Control' => 'private, no-store'];
        $digest = (string) config('cleaning_v3_catalog.approved_publication_digest', '');
        if (! config('cleaning_v3_catalog.read_enabled', false)
            || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            return response()->json([
                'success' => false,
                'code' => 'V3_CATALOG_UNAVAILABLE',
                'message' => 'No approved immutable V3 catalog is available.',
            ], 503, $noStore);
        }

        $versionKey = $request->input('catalogVersionKey');
        $version = is_string($versionKey)
            ? CleaningV3CatalogVersion::query()
                ->where('version_key', $versionKey)
                ->where('published_digest', $digest)
                ->where('status', CleaningV3CatalogVersion::PUBLISHED)
                ->whereNotNull('published_at')
                ->first()
            : null;
        if ($version === null) {
            return response()->json([
                'success' => false,
                'code' => 'V3_CATALOG_UNAVAILABLE',
                'message' => 'The requested catalog is not an approved published version.',
            ], 503, $noStore);
        }
        if (! is_string($request->input('catalogRevision'))
            || ! hash_equals($digest, $request->input('catalogRevision'))) {
            return response()->json([
                'success' => false,
                'code' => 'CATALOG_CHANGED',
                'message' => 'Refresh the catalog before reviewing this draft.',
            ], 409, $noStore);
        }

        $locale = str_starts_with(mb_strtolower((string) $request->header('Accept-Language', 'ar')), 'en')
            ? 'en' : 'ar';
        try {
            $rawAnswers = $request->input('answers');
            if (! is_array($rawAnswers)) {
                throw new CleaningV3VisitPlanException('answers', 'ANSWERS_OBJECT_REQUIRED');
            }
            $catalog = $serializer->serialize($version, $locale);
            $propertyKey = $request->input('propertyKey');
            $fields = app(\Modules\Cleaning\Services\CleaningV3PropertyFormSelector::class)->select($catalog['items'], $propertyKey);
            $answers = $forms->normalize($fields, $rawAnswers);
            $payload = $request->all();
            $mode = $payload['cleaningMode'] ?? null;
            $scopes = array_key_exists('scopeSelections', $payload) ? $payload['scopeSelections'] : [];
            $checkRequested = array_key_exists('cleaningMode', $payload) || array_key_exists('scopeSelections', $payload);
            unset($payload['cleaningMode'], $payload['scopeSelections']);
            $plan = $planner->plan($payload, allowMultipleLocalDates: $checkRequested);
            $capacityCheck = $checkRequested
                ? app(CleaningV3ScheduledWorkloadPreviewService::class)->check(
                    $version, $digest, $rawAnswers, $mode, $scopes, $propertyKey, $plan['appointments'],
                )
                : ['checked' => false, 'previewOnly' => true, 'requiresActualWorkerConflictCheck' => true, 'visits' => []];
        } catch (CleaningV3VisitPlanException $exception) {
            return response()->json([
                'success' => false,
                'code' => $exception->reasonCode,
                'message' => $exception->reasonCode === 'V3_EQUIPMENT_UNAVAILABLE'
                    ? 'المعدات المطلوبة غير متوفرة لكل الزيارات المحددة. عدّل العدد أو المواعيد.'
                    : 'تحقق من بيانات التنظيف والمواعيد المطلوبة.',
                'errors' => [$exception->field => [$exception->reasonCode]],
            ], 422, $noStore);
        } catch (CleaningV3CapacityException $exception) {
            return response()->json([
                'success' => false, 'code' => $exception->reasonCode,
                'message' => 'عدد العمال أو مدة الزيارة لا يكفيان لتنفيذ البنود المختارة، أو يلزم تقسيم المهام أولاً.',
                'errors' => [$exception->field => [$exception->reasonCode]],
            ], $exception->httpStatus, $noStore);
        } catch (DomainException $exception) {
            return response()->json([
                'success' => false, 'code' => $exception->getMessage(),
                'message' => 'تعذر التحقق من قدرة تنفيذ البنود وفق الكتالوج الحالي.',
            ], 422, $noStore);
        }

        // Bind both validated customer answers and the normalized schedule.
        // The resulting digest is NOT a quote signature and cannot be submitted.
        $draftFingerprint = hash('sha256', $plan['draftFingerprint'].'|'.json_encode(
            ['propertyKey' => $propertyKey, 'answers' => $answers, 'cleaningMode' => $mode, 'scopeSelections' => $scopes, 'capacityCheck' => $capacityCheck], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
        unset($plan['draftFingerprint']);

        return response()->json([
            'success' => true,
            'data' => [
                ...$plan,
                'draftFingerprint' => $draftFingerprint,
                'validatedAnswerCount' => count($answers),
                'capacityCheck' => $capacityCheck,
                'additionalWorkerFeePreview' => app(\Modules\Cleaning\Services\CleaningV3AdditionalWorkerFeeCalculator::class)->previewPlan($plan['appointments']),
                'quoteId' => null,
                'quoteSignature' => null,
                'currency' => 'SYP',
                'note' => 'This is a non-financial draft. No worker or funding has been reserved.',
            ],
        ], 200, $noStore);
    }
}
