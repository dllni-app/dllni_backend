<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    public function getTitle(): string
    {
        return 'تعديل الدور';
    }

    public function mutateFormDataBeforeFill(array $data): array
    {
        $data['permissions'] = $this->record->permissions->pluck('name')->toArray();

        return $data;
    }

    public function afterSave(): void
    {
        $permissions = $this->form->getState()['permissions'] ?? [];
        $this->record->syncPermissions($permissions);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
