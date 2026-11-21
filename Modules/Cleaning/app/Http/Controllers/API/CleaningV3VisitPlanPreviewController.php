<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Modules\Cleaning\Services\CleaningV3CatalogPublicSerializer;
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
            $answers = $forms->normalize($catalog['items'], $rawAnswers);
            $plan = $planner->plan($request->all());
        } catch (CleaningV3VisitPlanException $exception) {
            return response()->json([
                'success' => false,
                'code' => $exception->reasonCode,
                'errors' => [$exception->field => [$exception->reasonCode]],
            ], 422, $noStore);
        }

        // Bind both validated customer answers and the normalized schedule.
        // The resulting digest is NOT a quote signature and cannot be submitted.
        $draftFingerprint = hash('sha256', $plan['draftFingerprint'].'|'.json_encode(
            $answers, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
        unset($plan['draftFingerprint']);

        return response()->json([
            'success' => true,
            'data' => [
                ...$plan,
                'draftFingerprint' => $draftFingerprint,
                'validatedAnswerCount' => count($answers),
                'quoteId' => null,
                'quoteSignature' => null,
                'currency' => 'SYP',
                'note' => 'This is a non-financial draft. No worker or funding has been reserved.',
            ],
        ], 200, $noStore);
    }
}
