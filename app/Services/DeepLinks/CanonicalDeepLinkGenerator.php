<?php

declare(strict_types=1);

namespace App\Services\DeepLinks;

use Illuminate\Support\Str;

final class CanonicalDeepLinkGenerator
{
    public function product(int|string $idOrSlug): string
    {
        return $this->build('product', (string) $idOrSlug);
    }

    public function restaurant(int|string $idOrSlug): string
    {
        return $this->build('restaurant', (string) $idOrSlug);
    }

    public function vote(int|string $idOrSlug): string
    {
        return $this->build('vote', (string) $idOrSlug);
    }

    public function groupOrder(int|string $idOrSlug): string
    {
        return $this->build('group-order', (string) $idOrSlug);
    }

    public function store(int|string $idOrSlug): string
    {
        return $this->build('store', (string) $idOrSlug);
    }

    private function build(string $resourceType, string $identifier): string
    {
        $host = (string) config('deep_links.canonical_host', 'app.dllni.com');
        $scheme = (string) config('deep_links.canonical_scheme', 'https');

        return sprintf(
            '%s://%s/%s/%s',
            $scheme,
            $host,
            trim($resourceType, '/'),
            rawurlencode(Str::of($identifier)->trim('/')->toString()),
        );
    }
}
