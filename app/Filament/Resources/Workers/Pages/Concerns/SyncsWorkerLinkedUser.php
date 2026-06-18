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

        $state = $this->form->getState();

        $updates = [
            'name' => $this->record->first_name,
        ];

        if (filled($state['user_phone'] ?? null)) {
            $updates['phone'] = $state['user_phone'];
        }

        if (filled($state['user_password'] ?? null)) {
            $updates['password'] = $state['user_password'];
        }

        $user->forceFill($updates)->saveQuietly();
    }

    protected function mutateLinkedUserFormDataBeforeFill(array $data): array
    {
        $data['user_phone'] = $this->record->user?->phone;

        return $data;
    }
}
