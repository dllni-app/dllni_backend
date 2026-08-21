<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

final class DashboardAdminRecipients
{
    /**
     * @return Collection<int, User>
     */
    public static function all(): Collection
    {
        return User::query()
            ->whereHas('roles', static function ($query): void {
                $query->whereIn('name', ['admin', 'Super Admin']);
            })
            ->get();
    }
}
