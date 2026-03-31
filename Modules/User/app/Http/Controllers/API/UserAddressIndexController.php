<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Http\Resources\UserAddressResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UserAddressIndexController
{
    public function __invoke(Request $request): JsonResponse
    {
        $addresses = $request->user()
            ->addresses()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        return response()->json([
            'addresses' => UserAddressResource::collection($addresses),
        ]);
    }
}
