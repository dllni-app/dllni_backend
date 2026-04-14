<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Supermarket\Data\SmCartData;
use Modules\Supermarket\Http\Requests\SmCartRequest;
use Modules\Supermarket\Http\Requests\SmCartRequests\SmCartFilterRequest;
use Modules\Supermarket\Http\Resources\SmCartResource;
use Modules\Supermarket\Models\SmCart;
use Modules\Supermarket\Services\SmCartService;

final class SmCartController
{
    public function __construct(
        private SmCartService $service
    ) {}

    public function index(SmCartFilterRequest $request): AnonymousResourceCollection
    {
        $carts = SmCart::getQuery()->paginate($request->get('perPage', 20));

        return SmCartResource::collection($carts);
    }

    public function store(SmCartRequest $request): SmCartResource
    {
        $cart = $this->service->store(SmCartData::from($request->validated()));

        return SmCartResource::make($cart->load(['user', 'items']));
    }

    public function show(SmCart $smCart): SmCartResource
    {
        return SmCartResource::make($smCart->load(['user', 'items']));
    }

    public function update(SmCartRequest $request, SmCart $smCart): SmCartResource
    {
        $cart = $this->service->update(SmCartData::from($request->validated()), $smCart);

        return SmCartResource::make($cart->load(['user', 'items']));
    }

    public function destroy(SmCart $smCart): Response
    {
        $smCart->delete();

        return response()->noContent();
    }
}
