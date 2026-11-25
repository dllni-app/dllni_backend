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
        $digest = (string) \Modules\Cleaning\Services\CleaningV3AdminCatalogPublication::digest();
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
            $payload = $request->all();
            $mode = $payload['cleaningMode'] ?? null;
            $scopes = array_key_exists('scopeSelections', $payload) ? $payload['scopeSelections'] : [];
            $checkRequested = array_key_exists('cleaningMode', $payload) || array_key_exists('scopeSelections', $payload);
            $preferences = array_key_exists('preferences', $payload)
                ? app(\Modules\Cleaning\Services\CleaningV3CustomerPreferences::class)->resolve((int) $request->user()->id, $payload['preferences']) : null;
            $recurrence = null;
            if (array_key_exists('recurrenceRule', $payload)) {
                $rule = $payload['recurrenceRule'];
                if (! is_array($rule) || ($rule['catalogVersionKey'] ?? null) !== $versionKey || ($rule['catalogRevision'] ?? null) !== $digest) {
                    throw new DomainException('INVALID_V3_RECURRENCE_RULE');
                }
                $recurrence = app(\Modules\Cleaning\Services\CleaningV3WeeklyRecurrencePreviewService::class)->preview($rule);
            }
            $event = null;
            if (array_key_exists('eventRule', $payload)) {
                $rule = $payload['eventRule'];
                if (! is_array($rule) || $recurrence !== null || $checkRequested
                    || ($rule['catalogVersionKey'] ?? null) !== $versionKey
                    || ($rule['catalogRevision'] ?? null) !== $digest
                    || ($rule['propertyKey'] ?? null) !== $propertyKey) {
                    throw new DomainException('INVALID_V3_EVENT_SCHEDULE');
                }
                $event = app(\Modules\Cleaning\Services\CleaningV3EventSchedulePreviewService::class)->preview($version, $rule);
            }
            // Event selection and hours belong exclusively to the validated
            // schedule; event types are alternatives, not required form rows.
            $answerFields = $fields;
            if ($event !== null) {
                $answerFields = array_values(array_filter($fields, static fn ($field) => $field['sourceType'] !== 'event_type'));
            }
            foreach ($fields as $field) {
                if ($field['sourceType'] === 'event_type' && array_key_exists($field['key'], $rawAnswers)) {
                    throw new DomainException('V3_EVENT_SCHEDULE_REQUIRED');
                }
            }
            $answers = $forms->normalize($answerFields, $rawAnswers);
            $pricingRequested = array_key_exists('pricingPreviewRequested', $payload);
            $couponCode = $payload['couponCode'] ?? null;
            $expectedPricing = $payload['expectedPricingFingerprint'] ?? null;
            if (($pricingRequested && $payload['pricingPreviewRequested'] !== true)
                || (! $pricingRequested && (array_key_exists('couponCode', $payload) || array_key_exists('expectedPricingFingerprint', $payload)))
                || ($expectedPricing !== null && (! is_string($expectedPricing) || preg_match('/^[a-f0-9]{64}$/D', $expectedPricing) !== 1))
                || ($pricingRequested && ($preferences === null || (! $checkRequested && $event === null)))) {
                throw new DomainException('INVALID_V3_PRICING_REVIEW_REQUEST');
            }
            unset($payload['cleaningMode'], $payload['scopeSelections'], $payload['preferences'], $payload['recurrenceRule'],
                $payload['pricingPreviewRequested'], $payload['couponCode'], $payload['expectedPricingFingerprint'], $payload['eventRule']);
            $plan = $planner->plan($payload, allowMultipleLocalDates: $checkRequested);
            if ($recurrence !== null && $recurrence['appointments'] !== $plan['appointments']) {
                throw new DomainException('V3_RECURRENCE_PLAN_MISMATCH');
            }
            if ($event !== null && $event['appointments'] !== $plan['appointments']) {
                throw new DomainException('V3_EVENT_PLAN_MISMATCH');
            }
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
            ['propertyKey' => $propertyKey, 'answers' => $answers, 'cleaningMode' => $mode, 'scopeSelections' => $scopes, 'capacityCheck' => $capacityCheck, 'preferences' => $preferences, 'recurrenceRule' => $recurrence['recurrenceRule'] ?? null, 'event' => $event['event'] ?? null], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
        $commercialPrice = null;
        if ($pricingRequested) {
            try {
                $commercialPrice = app(\Modules\Cleaning\Services\CleaningV3CommercialPricePreviewService::class)->preview(
                    $version, $plan, $capacityCheck, (int) $request->user()->id, $propertyKey, $mode, $couponCode, $draftFingerprint, $event['event'] ?? null, $answers, $fields,
                );
            } catch (DomainException $exception) {
                return response()->json([
                    'success' => false, 'code' => $exception->getMessage(),
                    'message' => 'تعذر حساب التقدير المالي. تحقق من إعدادات السعر أو رمز الخصم.',
                ], $exception->getMessage() === 'V3_FINANCIAL_SETTINGS_UNAVAILABLE' ? 503 : 422, $noStore);
            }
            if ($expectedPricing !== null && ! hash_equals($expectedPricing, $commercialPrice['pricingFingerprint'])) {
                return response()->json([
                    'success' => false, 'code' => 'REQUOTE_REQUIRED',
                    'message' => 'تغير التقدير المالي. راجع الإجمالي الجديد قبل المتابعة.',
                ], 409, $noStore);
            }
        }
        unset($plan['draftFingerprint']);

        return response()->json([
            'success' => true,
            'data' => [
                ...$plan,
                'draftFingerprint' => $draftFingerprint,
                'validatedAnswerCount' => count($answers),
                'capacityCheck' => $capacityCheck,
                'preferencesCheck' => $preferences,
                'commercialPricePreview' => $commercialPrice,
                'event' => $event['event'] ?? null,
                'recurrenceRule' => $recurrence['recurrenceRule'] ?? null,
                'skippedOccurrences' => $recurrence['skippedOccurrences'] ?? [],
                'additionalWorkerFeePreview' => app(\Modules\Cleaning\Services\CleaningV3AdditionalWorkerFeeCalculator::class)->previewPlan($plan['appointments']),
                'quoteId' => null,
                'quoteSignature' => null,
                'currency' => 'SYP',
                'note' => 'This is a non-financial draft. No worker or funding has been reserved.',
            ],
        ], 200, $noStore);
    }
}
