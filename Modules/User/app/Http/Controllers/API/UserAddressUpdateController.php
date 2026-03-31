<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Http\Resources\UserAddressResource;
use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserAddressUpdateRequest;
use Modules\User\Models\UserAddress;
use Modules\User\Services\UserAddressService;

final class UserAddressUpdateController
{
    public function __invoke(UserAddressUpdateRequest $request, UserAddress $userAddress, UserAddressService $addressService): JsonResponse
    {
        $address = $addressService->update($userAddress, $request->validated());

        return response()->json([
            'address' => UserAddressResource::make($address),
        ]);
    }
}
