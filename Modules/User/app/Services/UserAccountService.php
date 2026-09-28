<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\User;
use App\Services\DashboardUserAccountNotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\User\Models\UserOtp;
use Mrmarchone\LaravelAutoCrud\Helpers\MediaHelper;

final class UserAccountService
{
    public function __construct(
        private readonly DashboardUserAccountNotificationService $dashboardNotificationService,
    ) {}

    /**
     * @param  array{name?: string, phone?: string}  $validated
     */
    public function updateProfile(User $user, array $validated, ?UploadedFile $primaryImage): User
    {
        return DB::transaction(function () use ($user, $validated, $primaryImage): User {
            $updates = [];
            $previousName = (string) $user->name;

            if (array_key_exists('name', $validated)) {
                $updates['name'] = $validated['name'];
            }

            if (array_key_exists('phone', $validated)) {
                $newPhone = $validated['phone'];
                if ($user->phone !== $newPhone) {
                    $updates['phone'] = $newPhone;
                    $updates['phone_verified_at'] = null;
                }
            }

            if ($updates !== []) {
                $user->update($updates);

                if ($user->wasChanged('name')) {
                    $this->dashboardNotificationService->nameChanged($user, $previousName);
                }
            }

            if ($primaryImage !== null) {
                MediaHelper::updateMedia($primaryImage, $user, 'primary-image');
            }

            return $user->fresh(['media']);
        });
    }

    public function updatePassword(User $user, string $newPasswordPlain): void
    {
        $user->update([
            'password' => $newPasswordPlain,
        ]);
    }

    /**
     * Permanently removes the user's personal account data while retaining only
     * anonymized transactional references that may be required for accounting,
     * fraud prevention, or other legal obligations.
     */
    public function deleteAccount(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $originalEmail = $user->email;
            $originalPhone = $user->phone;

            $user->tokens()->delete();
            $user->notifications()->delete();
            $user->addresses()->delete();
            $user->favorites()->delete();
            $user->carts()->delete();
            $user->smCarts()->delete();
            $user->smSmartLists()->delete();
            $user->smRecurringOrders()->delete();
            $user->smAssistantQueries()->delete();
            $user->reviews()->delete();
            $user->smsMessages()->delete();
            $user->syncRoles([]);

            if (is_string($originalPhone) && $originalPhone !== '') {
                UserOtp::query()->where('phone', $originalPhone)->delete();
            }

            if (is_string($originalEmail) && $originalEmail !== '') {
                DB::table('password_reset_tokens')
                    ->where('email', $originalEmail)
                    ->delete();
            }

            DB::table('sessions')->where('user_id', $user->id)->delete();

            $user->clearMediaCollection('primary-image');

            $user->forceFill([
                'name' => 'Deleted User',
                'email' => null,
                'phone' => null,
                'email_verified_at' => null,
                'phone_verified_at' => null,
                'fcm_token' => null,
                'remember_token' => null,
                'module_type' => null,
                'is_active' => false,
                'password' => Str::random(64),
            ])->saveQuietly();
        });
    }
}
