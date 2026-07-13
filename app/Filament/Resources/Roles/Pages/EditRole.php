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

    /** @var array<int, string> */
    private array $selectedPermissions = [];

    public function getTitle(): string
    {
        return 'تعديل الدور';
    }

    public function mutateFormDataBeforeFill(array $data): array
    {
        $data['permissions'] = $this->record->permissions->pluck('name')->all();

        unset($data['guard_name']);

        return $data;
    }

    public function mutateFormDataBeforeSave(array $data): array
    {
        $this->selectedPermissions = array_values($data['permissions'] ?? []);

        unset($data['permissions']);

        $data['guard_name'] = 'web';

        return $data;
    }

    public function afterSave(): void
    {
        $this->record->syncPermissions($this->selectedPermissions);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
