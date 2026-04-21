<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RedirectBrowserDeepLinkApiRequests
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET')) {
            return $next($request);
        }

        if ($this->shouldKeepJsonResponse($request)) {
            return $next($request);
        }

        if (! $this->isBrowserLikeRequest($request)) {
            return $next($request);
        }

        return $this->redirectForDeepLinkPath($request);
    }

    private function shouldKeepJsonResponse(Request $request): bool
    {
        $accept = mb_strtolower((string) $request->header('Accept', ''));

        if (str_contains($accept, 'application/json') || str_contains($accept, '+json')) {
            return true;
        }

        return $request->expectsJson()
            || $request->wantsJson()
            || $request->isJson()
            || $request->header('Authorization') !== null;
    }

    private function isBrowserLikeRequest(Request $request): bool
    {
        $accept = mb_strtolower((string) $request->header('Accept', ''));

        return $accept === ''
            || str_contains($accept, 'text/html')
            || str_contains($accept, '*/*');
    }

    private function redirectForDeepLinkPath(Request $request): RedirectResponse
    {
        $path = '/' . $request->path();

        $canonicalPath = $this->canonicalPathFor($path);
        if ($canonicalPath !== null) {
            $target = $request->getSchemeAndHttpHost() . $canonicalPath;
            $query = $request->getQueryString();

            if ($query !== null && $query !== '') {
                $target .= '?' . $query;
            }

            return redirect()->away($target);
        }

        $landing = (string) config('deep_links.web_landing_url');

        $query = array_filter([
            'deep_link' => $request->fullUrl(),
            'store_url' => (string) config('deep_links.store_landing_url'),
            'source' => $request->query('source'),
            'medium' => $request->query('medium'),
            'campaign' => $request->query('campaign'),
            'sharer_id' => $request->query('sharer_id'),
        ], static fn($value): bool => $value !== null && $value !== '');

        return redirect()->away($landing . '?' . http_build_query($query));
    }

    private function canonicalPathFor(string $path): ?string
    {
        $patterns = [
            '#^/api/v1/user/products/([^/]+)$#' => '/product/$1',
            '#^/api/v1/user/supermarket/products/([^/]+)$#' => '/product/$1',
            '#^/api/v1/user/supermarket/stores/([^/]+)$#' => '/store/$1',
            '#^/api/v1/user/restaurants/([^/]+)$#' => '/restaurant/$1',
            '#^/api/v1/user/restaurants/votes/([^/]+)$#' => '/vote/$1',
            '#^/api/v1/user/restaurants/group-orders/([^/]+)$#' => '/group-order/$1',
        ];

        foreach ($patterns as $pattern => $replacement) {
            if (preg_match($pattern, $path) === 1) {
                return (string) preg_replace($pattern, $replacement, $path);
            }
        }

        return null;
    }
}
