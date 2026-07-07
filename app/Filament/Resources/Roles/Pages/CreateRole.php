<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    public function getTitle(): string
    {
        return 'إضافة دور';
    }

    public function afterCreate(): void
    {
        $permissions = $this->form->getState()['permissions'] ?? [];
        $this->record->syncPermissions($permissions);
    }
}
