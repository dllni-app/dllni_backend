<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserModuleType;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureSellerPermission
{
    /**
     * Owners bypass employee permission checks. Employees must have at least one
     * of the permissions supplied to the middleware.
     *
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            throw new AuthorizationException('Unauthenticated.');
        }

        if ($this->isOwner($user)) {
            return $next($request);
        }

        $grantedPermissions = $user->getAllPermissions()->pluck('name');
        $hasPermission = $permissions !== []
            && $grantedPermissions->intersect($permissions)->isNotEmpty();

        if (! $hasPermission) {
            throw new AuthorizationException('You do not have permission to perform this action.');
        }

        return $next($request);
    }

    private function isOwner(User $user): bool
    {
        return match ($user->module_type) {
            UserModuleType::RestaurantSeller => $user->restaurants()->exists(),
            UserModuleType::SupermarketSeller => $user->smStores()->exists(),
            default => false,
        };
    }
}
