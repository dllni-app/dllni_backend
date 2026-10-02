<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Delivery\Models\DeliveryDriver;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class EnsureDeliveryDriver
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $driver = DeliveryDriver::query()
            ->with('company', 'user')
            ->where('user_id', $user->id)
            ->first();

        if (! $driver) {
            abort(Response::HTTP_FORBIDDEN);
        }

        if (! $driver->is_active || $driver->is_suspended) {
            return response()->json([
                'message' => 'This delivery driver account is inactive or suspended.',
            ], Response::HTTP_FORBIDDEN);
        }

        if (! $driver->company?->is_active || $driver->company?->is_suspended) {
            return response()->json([
                'message' => 'This delivery company is inactive or suspended.',
            ], Response::HTTP_FORBIDDEN);
        }

        $request->attributes->set('deliveryDriver', $driver);

        return $next($request);
    }
}
