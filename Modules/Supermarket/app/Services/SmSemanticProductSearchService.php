<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SmSemanticProductSearchService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array{id: int, score: float|null}>|null
     */
    public function search(array $payload): ?array
    {
        $baseUrl = (string) config('services.dallelni_search.products_base_url');
        $authToken = (string) config('services.dallelni_search.auth_token');
        $timeout = (int) config('services.dallelni_search.timeout', 10);

        if ($baseUrl === '' || $authToken === '') {
            Log::warning('Semantic product search configuration is incomplete.');

            return null;
        }

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders([
                    'auth-token' => $authToken,
                ])
                ->post(mb_rtrim($baseUrl, '/').'/search', $payload)
                ->throw();

            $results = $response->json('results');

            if (! is_array($results)) {
                return [];
            }

            $normalized = [];

            foreach ($results as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $id = $item['product_id'] ?? $item['id'] ?? null;

                if (! is_numeric($id)) {
                    continue;
                }

                $score = $item['score'] ?? null;

                $normalized[] = [
                    'id' => (int) $id,
                    'score' => is_numeric($score) ? (float) $score : null,
                ];
            }

            return $normalized;
        } catch (Throwable $exception) {
            Log::warning('Semantic product search request failed.', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
