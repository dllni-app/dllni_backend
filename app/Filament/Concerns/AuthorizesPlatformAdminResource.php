<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

trait AuthorizesPlatformAdminResource
{
    protected static function dashboardAllowed(string $permission): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        if ($user->hasAnyRole(['admin', 'Super Admin'])) {
            return true;
        }

        return $user->can($permission);
    }
}
