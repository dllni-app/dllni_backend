<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\Users\Pages;

use App\Filament\CleaningAdmin\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\Models\Role;

final class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    public function afterCreate(): void
    {
        $roleId = $this->form->getState()['role_id'] ?? null;
        $role = $roleId ? Role::find($roleId) : null;
        if ($role) {
            $this->record->assignRole($role->name);
        }
    }
}
