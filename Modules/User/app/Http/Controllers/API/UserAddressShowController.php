<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Http\Resources\UserAddressResource;
use Modules\User\Models\UserAddress;

final class UserAddressShowController
{
    public function __invoke(UserAddress $userAddress): UserAddressResource
    {
        return UserAddressResource::make($userAddress);
    }
}
