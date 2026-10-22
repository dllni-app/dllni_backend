<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserModuleType;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

final class AdminAccountPasswordResetService
{
    public function canReset(?User $admin, User|Worker $target): bool
    {
        if (! $admin?->hasAnyRole(['admin', 'Super Admin'])) {
            return false;
        }

        $account = $target instanceof Worker ? $target->user : $target;

        if (! $account instanceof User || $account->roles()->exists()) {
            return false;
        }

        if ($target instanceof Worker) {
            return true;
        }

        return $account->module_type === null
            || $account->module_type === UserModuleType::CleaningWorker;
    }

    /**
     * Set a new password without relying on the SMS/OTP recovery flow.
     * Never log or return the plaintext password.
     */
    public function reset(User $admin, User|Worker $target, string $newPassword): void
    {
        if (! $this->canReset($admin, $target)) {
            throw new AuthorizationException('غير مسموح لك بإعادة تعيين كلمة مرور هذا الحساب.');
        }

        Validator::make(
            ['new_password' => $newPassword],
            ['new_password' => ['required', 'string', 'min:8', 'max:255']],
        )->validate();

        $account = $target instanceof Worker ? $target->user : $target;

        DB::transaction(function () use ($account, $admin, $newPassword): void {
            $account->forceFill([
                'password' => $newPassword,
                'remember_token' => Str::random(60),
            ])->save();

            // Previously issued mobile API tokens must no longer work.
            $account->tokens()->delete();

            activity('admin_account_password_resets')
                ->causedBy($admin)
                ->performedOn($account)
                ->withProperties([
                    'admin_id' => $admin->getKey(),
                    'account_id' => $account->getKey(),
                ])
                ->log('admin_reset_account_password');
        });
    }
}
