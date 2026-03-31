<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Mrmarchone\LaravelAutoCrud\Helpers\MediaHelper;

final class UserAccountService
{
    /**
     * @param  array{name?: string, phone?: string}  $validated
     */
    public function updateProfile(User $user, array $validated, ?UploadedFile $primaryImage): User
    {
        return DB::transaction(function () use ($user, $validated, $primaryImage): User {
            $updates = [];

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
}
