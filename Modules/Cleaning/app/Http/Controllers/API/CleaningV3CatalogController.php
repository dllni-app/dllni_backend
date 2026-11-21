<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Modules\Cleaning\Services\CleaningV3CatalogPublicSerializer;

final class CleaningV3CatalogController
{
    public function __invoke(Request $request, CleaningV3CatalogPublicSerializer $serializer): JsonResponse|Response
    {
        $headers = [
            'Cache-Control' => 'private, no-cache, must-revalidate',
            'Vary' => 'Accept-Language',
        ];

        if (! config('cleaning_v3_catalog.read_enabled', false)) {
            return response()->json([
                'success' => false,
                'code' => 'V3_CATALOG_UNAVAILABLE',
                'message' => 'The dynamic cleaning catalog is not yet available.',
            ], 503, ['Cache-Control' => 'private, no-store']);
        }

        $approvedDigest = (string) config('cleaning_v3_catalog.approved_publication_digest');
        if (! preg_match('/^[a-f0-9]{64}$/D', $approvedDigest)) {
            return response()->json([
                'success' => false,
                'code' => 'V3_CATALOG_UNAVAILABLE',
                'message' => 'No approved V3 publication digest has been registered.',
            ], 503, ['Cache-Control' => 'private, no-store']);
        }

        $version = CleaningV3CatalogVersion::query()
            ->where('status', CleaningV3CatalogVersion::PUBLISHED)
            ->where('published_digest', $approvedDigest)
            ->whereNotNull('published_at')
            ->whereNotNull('published_digest')
            ->latest('published_at')
            ->first();

        if ($version === null) {
            return response()->json([
                'success' => false,
                'code' => 'V3_CATALOG_UNAVAILABLE',
                'message' => 'No verified dynamic cleaning catalog is published.',
            ], 503, ['Cache-Control' => 'private, no-store']);
        }

        $locale = str_starts_with(mb_strtolower((string) $request->header('Accept-Language', 'ar')), 'en')
            ? 'en' : 'ar';
        $etag = '"'.hash('sha256', $version->version_key.':'.$version->published_digest.':'.$locale.':renderer-1').'"';
        $headers['ETag'] = $etag;

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        return response()->json([
            'success' => true,
            'data' => $serializer->serialize($version, $locale),
        ], 200, $headers);
    }
}
