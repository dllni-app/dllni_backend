<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserModuleType;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCustomerOrCleaningWorkerAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! in_array($user->module_type, [null, UserModuleType::CleaningWorker], true)) {
            return response()->json([
                'message' => 'هذا الحساب غير مصرح له باستخدام هذا المسار.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
