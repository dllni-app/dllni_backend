<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCustomerAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->module_type !== null) {
            return response()->json([
                'message' => 'هذا الحساب غير مصرح له باستخدام تطبيق العميل.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
