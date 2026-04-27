<?php

declare(strict_types=1);

namespace App\Services\DeepLinks;

use App\Models\DeepLinkShortUrl;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Modules\Resturants\Enums\RestaurantGroupOrderStatus;
use Modules\Resturants\Enums\RestaurantGroupVoteStatus;
use Modules\Resturants\Models\Product as RestaurantProduct;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantGroupOrder;
use Modules\Resturants\Models\RestaurantGroupOrderParticipant;
use Modules\Resturants\Models\RestaurantGroupVote;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;

final class DeepLinkResolverService
{
    private CanonicalDeepLinkGenerator $urlGenerator;

    public function __construct(
        CanonicalDeepLinkGenerator $urlGenerator,
    ) {
        $this->urlGenerator = $urlGenerator;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $urlOrPath, ?int $currentUserId = null): array
    {
        [$path, $query] = $this->normalizeInput($urlOrPath);

        $cacheKey = null;
        $shouldCache = $currentUserId === null;

        if ($shouldCache) {
            $cacheKey = 'deep_link:resolve:' . sha1($path);
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $result = $this->resolvePath($path, $currentUserId);
        $result['query'] = $query;

        if ($shouldCache && $cacheKey !== null && Arr::get($result, 'status') !== 'forbidden') {
            Cache::put($cacheKey, $result, now()->addSeconds((int) config('deep_links.resolver_cache_ttl_seconds', 300)));
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvePath(string $path, ?int $currentUserId = null): array
    {
        $cleanPath = '/' . mb_trim($path, '/');
        $parts = array_values(array_filter(explode('/', mb_trim($cleanPath, '/'))));

        if (count($parts) < 2) {
            return $this->invalid('not_found', 'unknown', null, null);
        }

        $type = mb_strtolower((string) $parts[0]);
        $identifier = urldecode((string) $parts[1]);

        return match ($type) {
            'product' => $this->resolveProduct($identifier),
            'restaurant' => $this->resolveRestaurant($identifier),
            'store' => $this->resolveStore($identifier),
            'vote' => $this->resolveVote($identifier),
            'group-order' => $this->resolveGroupOrder($identifier, $currentUserId),
            default => $this->invalid('not_found', $type, null, null),
        };
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private function normalizeInput(string $urlOrPath): array
    {
        $trimmed = mb_trim($urlOrPath);

        if ($trimmed === '') {
            return ['/', []];
        }

        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            $parts = parse_url($trimmed);
            $path = (string) ($parts['path'] ?? '/');
            $query = [];
            if (isset($parts['query'])) {
                parse_str((string) $parts['query'], $query);
            }

            [$expandedPath, $expandedQuery] = $this->expandShortPath($this->normalizePath($path));

            return [$expandedPath, array_merge($expandedQuery, $query)];
        }

        $path = str_starts_with($trimmed, '/') ? $trimmed : '/' . $trimmed;

        [$expandedPath, $expandedQuery] = $this->expandShortPath($this->normalizePath($path));

        return [$expandedPath, $expandedQuery];
    }

    private function normalizePath(string $path): string
    {
        $normalized = '/' . mb_trim($path, '/');

        $maps = [
            '#^/api/v1/user/products/([^/]+)$#' => '/product/$1',
            '#^/api/v1/user/supermarket/products/([^/]+)$#' => '/product/$1',
            '#^/api/v1/user/supermarket/stores/([^/]+)$#' => '/store/$1',
            '#^/api/v1/user/restaurants/([^/]+)$#' => '/restaurant/$1',
            '#^/api/v1/user/restaurants/votes/([^/]+)$#' => '/vote/$1',
            '#^/api/v1/user/restaurants/group-orders/([^/]+)$#' => '/group-order/$1',
        ];

        foreach ($maps as $pattern => $replacement) {
            if (preg_match($pattern, $normalized) === 1) {
                return (string) preg_replace($pattern, $replacement, $normalized);
            }
        }

        return $normalized;
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private function expandShortPath(string $path): array
    {
        if (preg_match('#^/s/([A-Za-z0-9\-_]+)$#', $path, $matches) !== 1) {
            return [$path, []];
        }

        $code = (string) $matches[1];

        $short = DeepLinkShortUrl::query()->where('code', $code)->first();
        if ($short === null || ! $short->is_active) {
            return [$path, []];
        }

        if ($short->expires_at !== null && now()->greaterThan($short->expires_at)) {
            return [$path, []];
        }

        if ($short->max_clicks !== null && $short->clicks >= $short->max_clicks) {
            return [$path, []];
        }

        $targetParts = parse_url((string) $short->target_url);
        $targetPath = (string) ($targetParts['path'] ?? '/');
        $targetQuery = [];

        if (isset($targetParts['query'])) {
            parse_str((string) $targetParts['query'], $targetQuery);
        }

        return [$this->normalizePath($targetPath), $targetQuery];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveProduct(string $identifier): array
    {
        $smProduct = null;
        if (ctype_digit($identifier)) {
            $smProduct = SmProduct::query()
                ->whereKey((int) $identifier)
                ->where('is_available', true)
                ->whereHas('store', fn($q) => $q
                    ->where('is_active', true)
                    ->where(fn($sq) => $sq->whereNull('suspension_until')->orWhere('suspension_until', '<=', now())))
                ->first();
        }

        if ($smProduct !== null) {
            return [
                'type' => 'product',
                'target' => 'supermarket_product',
                'id' => (int) $smProduct->id,
                'slug' => null,
                'status' => 'ok',
                'requires_auth' => false,
                'canonical_url' => $this->urlGenerator->product((int) $smProduct->id),
                'fallback_url' => (string) config('deep_links.web_landing_url'),
            ];
        }

        $restaurantProduct = null;
        if (ctype_digit($identifier)) {
            $restaurantProduct = RestaurantProduct::query()
                ->whereKey((int) $identifier)
                ->where('is_available', true)
                ->whereHas('restaurant', fn($q) => $q
                    ->where('is_active', true)
                    ->where(fn($sq) => $sq->whereNull('suspension_until')->orWhere('suspension_until', '<=', now())))
                ->first();
        }

        if ($restaurantProduct !== null) {
            return [
                'type' => 'product',
                'target' => 'restaurant_product',
                'id' => (int) $restaurantProduct->id,
                'slug' => null,
                'status' => 'ok',
                'requires_auth' => false,
                'canonical_url' => $this->urlGenerator->product((int) $restaurantProduct->id),
                'fallback_url' => (string) config('deep_links.web_landing_url'),
            ];
        }

        return $this->invalid('not_found', 'product', null, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRestaurant(string $identifier): array
    {
        $restaurant = Restaurant::query()
            ->when(ctype_digit($identifier), fn($q) => $q->whereKey((int) $identifier), fn($q) => $q->where('slug', $identifier))
            ->first();

        if ($restaurant === null) {
            return $this->invalid('not_found', 'restaurant', null, $identifier);
        }

        $visible = (bool) $restaurant->is_active
            && ($restaurant->suspension_until === null || $restaurant->suspension_until->lte(now()));

        if (! $visible) {
            return $this->invalid('forbidden', 'restaurant', (int) $restaurant->id, (string) ($restaurant->slug ?? null));
        }

        return [
            'type' => 'restaurant',
            'id' => (int) $restaurant->id,
            'slug' => (string) $restaurant->slug,
            'status' => 'ok',
            'requires_auth' => false,
            'canonical_url' => $this->urlGenerator->restaurant((string) $restaurant->slug),
            'fallback_url' => (string) config('deep_links.web_landing_url'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveStore(string $identifier): array
    {
        if (! ctype_digit($identifier)) {
            return $this->invalid('not_found', 'store', null, $identifier);
        }

        $store = SmStore::query()->find((int) $identifier);
        if ($store === null) {
            return $this->invalid('not_found', 'store', null, $identifier);
        }

        $visible = (bool) $store->is_active
            && ($store->suspension_until === null || $store->suspension_until->lte(now()));

        if (! $visible) {
            return $this->invalid('forbidden', 'store', (int) $store->id, (string) ($store->slug ?? null));
        }

        return [
            'type' => 'store',
            'target' => 'supermarket_store',
            'id' => (int) $store->id,
            'slug' => (string) ($store->slug ?? ''),
            'status' => 'ok',
            'requires_auth' => false,
            'canonical_url' => $this->urlGenerator->store((int) $store->id),
            'fallback_url' => (string) config('deep_links.web_landing_url'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveVote(string $identifier): array
    {
        if (! ctype_digit($identifier)) {
            return $this->invalid('not_found', 'vote', null, $identifier);
        }

        $vote = RestaurantGroupVote::query()->find((int) $identifier);
        if ($vote === null) {
            return $this->invalid('not_found', 'vote', null, $identifier);
        }

        if ($vote->status === RestaurantGroupVoteStatus::Cancelled) {
            return $this->invalid('forbidden', 'vote', (int) $vote->id, null);
        }

        if ($vote->status !== RestaurantGroupVoteStatus::Active || now()->greaterThan($vote->ends_at)) {
            return $this->invalid('expired', 'vote', (int) $vote->id, null);
        }

        return [
            'type' => 'vote',
            'id' => (int) $vote->id,
            'slug' => null,
            'status' => 'ok',
            'requires_auth' => false,
            'canonical_url' => $this->urlGenerator->vote((int) $vote->id),
            'fallback_url' => (string) config('deep_links.web_landing_url'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveGroupOrder(string $identifier, ?int $currentUserId): array
    {
        $groupOrder = RestaurantGroupOrder::query()
            ->when(ctype_digit($identifier), fn($q) => $q->whereKey((int) $identifier), fn($q) => $q->where('share_token', $identifier))
            ->first();

        if ($groupOrder === null) {
            return $this->invalid('not_found', 'group-order', null, $identifier);
        }

        if (
            in_array($groupOrder->status, [RestaurantGroupOrderStatus::Expired, RestaurantGroupOrderStatus::Cancelled], true)
            || now()->greaterThan($groupOrder->ends_at)
        ) {
            return $this->invalid('expired', 'group-order', (int) $groupOrder->id, (string) $groupOrder->share_token);
        }

        if (ctype_digit($identifier)) {
            if ($currentUserId === null) {
                return $this->invalid('forbidden', 'group-order', (int) $groupOrder->id, (string) $groupOrder->share_token, true);
            }

            $allowed = (int) $groupOrder->user_id === $currentUserId
                || RestaurantGroupOrderParticipant::query()
                ->where('group_order_id', $groupOrder->id)
                ->where('user_id', $currentUserId)
                ->exists();

            if (! $allowed) {
                return $this->invalid('forbidden', 'group-order', (int) $groupOrder->id, (string) $groupOrder->share_token, true);
            }
        }

        return [
            'type' => 'group-order',
            'id' => (int) $groupOrder->id,
            'slug' => (string) $groupOrder->share_token,
            'status' => 'ok',
            'requires_auth' => true,
            'canonical_url' => $this->urlGenerator->groupOrder((string) $groupOrder->share_token),
            'fallback_url' => (string) config('deep_links.web_landing_url'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invalid(string $status, string $type, ?int $id, ?string $slug, bool $requiresAuth = false): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'slug' => $slug,
            'status' => $status,
            'requires_auth' => $requiresAuth,
            'fallback_url' => (string) config('deep_links.invalid_fallback_url'),
        ];
    }
}
