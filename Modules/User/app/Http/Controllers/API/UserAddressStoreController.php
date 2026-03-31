<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Http\Resources\UserAddressResource;
use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserAddressStoreRequest;
use Modules\User\Services\UserAddressService;

final class UserAddressStoreController
{
    public function __invoke(UserAddressStoreRequest $request, UserAddressService $addressService): JsonResponse
    {
        $address = $addressService->store($request->user(), $request->validated());

        return response()->json([
            'address' => UserAddressResource::make($address),
        ], 201);
    }
}
