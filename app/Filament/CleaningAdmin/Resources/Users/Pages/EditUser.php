<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\Users\Pages;

use App\Filament\CleaningAdmin\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\Models\Role;

final class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    public function mutateFormDataBeforeFill(array $data): array
    {
        $data['role_id'] = $this->record->roles()->first()?->id;

        return $data;
    }

    public function afterSave(): void
    {
        $roleId = $this->form->getState()['role_id'] ?? null;
        $role = $roleId ? Role::find($roleId) : null;
        $this->record->syncRoles($role ? [$role->name] : []);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
