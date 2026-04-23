<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Supermarket\Services\StoreOwnerContextService;
use Symfony\Component\HttpFoundation\Response;

final class InjectStoreIdFromOwnerContext
{
    public function __construct(
        private StoreOwnerContextService $context
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $storeId = $this->context->ownedStore()->id;

        // Force consistent store scoping from authenticated owner context.
        $request->merge([
            'storeId' => $storeId,
            'store_id' => $storeId,
        ]);

        return $next($request);
    }
}
