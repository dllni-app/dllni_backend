<?php

declare(strict_types=1);

namespace App\Services\DeepLinks;

use App\Models\DeepLinkEvent;
use Illuminate\Http\Request;

final class DeepLinkAnalyticsService
{
    /**
     * @param  array<string, mixed>  $resolution
     * @param  array<string, mixed>  $overrides
     */
    public function log(string $action, Request $request, array $resolution = [], array $overrides = []): void
    {
        $query = $request->query();

        DeepLinkEvent::query()->create(array_merge([
            'action' => $action,
            'status' => $resolution['status'] ?? null,
            'resource_type' => $resolution['type'] ?? null,
            'resource_id' => $resolution['id'] ?? null,
            'resource_slug' => $resolution['slug'] ?? null,
            'source' => $query['source'] ?? $request->input('source'),
            'medium' => $query['medium'] ?? $request->input('medium'),
            'campaign' => $query['campaign'] ?? $request->input('campaign'),
            'sharer_id' => $query['sharer_id'] ?? $request->input('sharer_id'),
            'platform' => $request->header('X-Platform') ?? $request->input('platform'),
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
            'referer' => $request->headers->get('referer'),
            'full_url' => $request->fullUrl(),
            'path' => '/' . $request->path(),
            'query_params' => $query,
            'meta' => $resolution,
        ], $overrides));
    }
}
