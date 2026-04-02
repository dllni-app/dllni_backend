<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Http\Resources\UserAddressResource;
use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserAddressPatchRequest;
use Modules\User\Models\UserAddress;
use Modules\User\Services\UserAddressService;

final class UserAddressPatchController
{
    public function __invoke(UserAddressPatchRequest $request, UserAddress $userAddress, UserAddressService $addressService): JsonResponse
    {
        $address = $addressService->patch($userAddress, $request->validated());

        return response()->json([
            'address' => UserAddressResource::make($address),
        ]);
    }
}
