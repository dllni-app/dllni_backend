<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Http\Resources\UserAddressResource;
use Illuminate\Http\JsonResponse;
use Modules\User\Models\UserAddress;
use Modules\User\Services\UserAddressService;

final class UserAddressSetDefaultController
{
    public function __invoke(UserAddress $userAddress, UserAddressService $addressService): JsonResponse
    {
        $address = $addressService->setDefault($userAddress);

        return response()->json([
            'address' => UserAddressResource::make($address),
        ]);
    }
}
