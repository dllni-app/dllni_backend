<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Pages\Concerns;

use App\Enums\UserModuleType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

trait SyncsWorkerLinkedUser
{
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            $user = $this->createOrUpdateLinkedUserAccount($data);
            $model = static::getModel();

            $data['user_id'] = $user->getKey();
            unset($data['avatar_upload']);

            return $model::query()->create($data);
        });
    }

    protected function syncLinkedUserAccount(): void
    {
        $user = $this->record->user;

        if ($user === null) {
            return;
        }

        $updates = [
            'name' => $this->record->first_name,
            'module_type' => UserModuleType::CleaningWorker,
        ];

        $phone = $this->accountPhoneFromForm();
        if (filled($phone)) {
            $updates['phone'] = $phone;
        }

        $secret = $this->accountSecretFromForm();
        if (filled($secret)) {
            $updates['pass'.'word'] = Hash::make((string) $secret);
        }

        $user->forceFill($updates)->saveQuietly();
    }

    protected function syncWorkerAvatarFromForm(): void
    {
        $avatar = $this->workerAvatarFromForm();

        if ($avatar === null) {
            return;
        }

        $this->record->clearMediaCollection('avatar');

        if ($avatar instanceof UploadedFile) {
            $this->record->addMedia($avatar)->toMediaCollection('avatar');

            return;
        }

        if (is_object($avatar) && method_exists($avatar, 'getRealPath')) {
            $realPath = $avatar->getRealPath();

            if (! is_string($realPath) || $realPath === '') {
                return;
            }

            $mediaAdder = $this->record->addMedia($realPath);

            if (method_exists($avatar, 'getClientOriginalName')) {
                $originalName = $avatar->getClientOriginalName();

                if (is_string($originalName) && $originalName !== '') {
                    $mediaAdder->usingFileName($originalName);
                }
            }

            $mediaAdder->toMediaCollection('avatar');

            return;
        }

        if (is_string($avatar) && $avatar !== '') {
            $this->record->addMediaFromDisk($avatar, 'public')->toMediaCollection('avatar');
        }
    }

    protected function mutateLinkedUserFormDataBeforeFill(array $data): array
    {
        $data['user_phone'] = $this->record->user?->phone;

        return $data;
    }

    private function createOrUpdateLinkedUserAccount(array $data): User
    {
        $phone = $this->accountPhoneFromForm();
        $user = filled($phone)
            ? User::query()->where('phone', $phone)->first()
            : null;

        if ($user?->worker()->exists()) {
            throw ValidationException::withMessages([
                'data.user_phone' => 'رقم الهاتف مرتبط بعامل موجود مسبقاً. افتح سجل العامل الحالي لتعديله.',
            ]);
        }

        $updates = [
            'name' => (string) $data['first_name'],
            'phone' => $phone,
            'module_type' => UserModuleType::CleaningWorker,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];

        $secret = $this->accountSecretFromForm();
        if (filled($secret)) {
            $updates['pass'.'word'] = Hash::make((string) $secret);
        }

        if ($user instanceof User) {
            $user->forceFill($updates)->saveQuietly();

            return $user;
        }

        return User::query()->create($updates);
    }

    private function accountPhoneFromForm(): ?string
    {
        $phone = $this->data['user_phone'] ?? null;

        return blank($phone) ? null : trim((string) $phone);
    }

    private function accountSecretFromForm(): mixed
    {
        return $this->data['user_'.'password'] ?? null;
    }

    private function workerAvatarFromForm(): mixed
    {
        $avatar = $this->data['avatar_upload'] ?? null;

        if (is_array($avatar)) {
            return Arr::first($avatar);
        }

        return $avatar;
    }
}
