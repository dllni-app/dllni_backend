<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Pages\Concerns;

trait SyncsWorkerLinkedUser
{
    protected function syncLinkedUserAccount(): void
    {
        $user = $this->record->user;

        if ($user === null) {
            return;
        }

        $updates = [
            'name' => $this->record->first_name,
        ];

        if (filled($this->data['user_phone'] ?? null)) {
            $updates['phone'] = $this->data['user_phone'];
        }

        if (filled($this->data['user_password'] ?? null)) {
            $updates['password'] = $this->data['user_password'];
        }

        $user->forceFill($updates)->saveQuietly();
    }

    protected function mutateLinkedUserFormDataBeforeFill(array $data): array
    {
        $data['user_phone'] = $this->record->user?->phone;

        return $data;
    }
}
