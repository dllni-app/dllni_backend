<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use Filament\Resources\Pages\CreateRecord;

final class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /** @var array<int, string> */
    private array $selectedPermissions = [];

    public function getTitle(): string
    {
        return 'إضافة دور';
    }

    public function mutateFormDataBeforeCreate(array $data): array
    {
        $this->selectedPermissions = RoleForm::extractSelectedPermissions($data);
        $data['guard_name'] = 'web';

        return $data;
    }

    public function afterCreate(): void
    {
        $this->record->syncPermissions($this->selectedPermissions);
    }
}
