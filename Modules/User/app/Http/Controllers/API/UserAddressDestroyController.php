<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\Response;
use Modules\User\Models\UserAddress;
use Modules\User\Services\UserAddressService;

final class UserAddressDestroyController
{
    public function __invoke(UserAddress $userAddress, UserAddressService $addressService): Response
    {
        $addressService->delete($userAddress);

        return response()->noContent();
    }
}
