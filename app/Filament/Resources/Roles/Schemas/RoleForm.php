<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Schemas;

use App\Filament\Support\ArabicDashboardLabels;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Permission;

final class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('permissions.form.role_name'))
                    ->helperText(__('permissions.form.role_name_helper'))
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                CheckboxList::make('permissions')
                    ->label(__('permissions.form.permissions'))
                    ->helperText(__('permissions.form.permissions_helper'))
                    ->options(self::permissionOptions())
                    ->columns(2)
                    ->searchable()
                    ->bulkToggleable()
                    ->dehydrated(true),
            ]);
    }

    /** @return array<string, array<string, string>> */
    private static function permissionOptions(): array
    {
        $sections = [];

        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get(['name', 'slug', 'group']);

        foreach ($permissions as $permission) {
            $section = ArabicDashboardLabels::permissionSectionName(
                $permission->name,
                $permission->group,
            );

            $sections[$section][$permission->name] = ArabicDashboardLabels::permissionName(
                $permission->name,
                $permission->slug,
            );
        }

        ksort($sections, SORT_NATURAL);

        foreach ($sections as &$options) {
            asort($options, SORT_NATURAL);
        }

        return $sections;
    }
}
