<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Pages\Concerns;

use App\Enums\UserModuleType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

trait SyncsWorkerLinkedUser
{
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            $user = $this->createLinkedUserAccount($data);
            $model = static::getModel();

            $data['user_id'] = $user->getKey();

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

        if (filled($this->data['user_phone'] ?? null)) {
            $updates['phone'] = $this->data['user_phone'];
        }

        $secret = $this->accountSecretFromForm();
        if (filled($secret)) {
            $updates['pass'.'word'] = Hash::make((string) $secret);
        }

        $user->forceFill($updates)->saveQuietly();
    }

    protected function mutateLinkedUserFormDataBeforeFill(array $data): array
    {
        $data['user_phone'] = $this->record->user?->phone;

        return $data;
    }

    private function createLinkedUserAccount(array $data): User
    {
        return User::query()->create([
            'name' => (string) $data['first_name'],
            'phone' => $this->data['user_phone'] ?? null,
            'pass'.'word' => Hash::make((string) $this->accountSecretFromForm()),
            'module_type' => UserModuleType::CleaningWorker,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
    }

    private function accountSecretFromForm(): mixed
    {
        return $this->data['user_'.'password'] ?? null;
    }
}
