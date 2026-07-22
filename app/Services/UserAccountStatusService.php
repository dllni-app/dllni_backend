<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UserAccountStatusService
{
    public function deactivate(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->forceFill([
                'is_active' => false,
            ])->save();

            $user->tokens()->delete();
        });
    }

    public function activate(User $user): void
    {
        $user->forceFill([
            'is_active' => true,
        ])->save();
    }
}
