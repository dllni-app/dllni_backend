<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Schemas;

use App\Filament\Support\ArabicDashboardLabels;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Permission;

final class RoleForm
{
    private const string PermissionFieldPrefix = 'permission_group_';

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
                ...self::permissionSections(),
            ]);
    }

    /**
     * Extract the selected permission names from the section-specific form fields.
     *
     * @return array<int, string>
     */
    public static function extractSelectedPermissions(array &$data): array
    {
        $selected = [];

        foreach (self::permissionGroups() as $section => $options) {
            $field = self::permissionFieldName($section);

            foreach ((array) ($data[$field] ?? []) as $permission) {
                if (is_string($permission) && array_key_exists($permission, $options)) {
                    $selected[] = $permission;
                }
            }

            unset($data[$field]);
        }

        return array_values(array_unique($selected));
    }

    /**
     * Build the section-specific form state for an existing role.
     *
     * @param  iterable<int, string>  $selectedPermissions
     * @return array<string, array<int, string>>
     */
    public static function selectedPermissionState(iterable $selectedPermissions): array
    {
        $selectedLookup = [];

        foreach ($selectedPermissions as $permission) {
            if (is_string($permission)) {
                $selectedLookup[$permission] = true;
            }
        }

        $state = [];

        foreach (self::permissionGroups() as $section => $options) {
            $state[self::permissionFieldName($section)] = array_values(array_filter(
                array_keys($options),
                static fn (string $permission): bool => isset($selectedLookup[$permission]),
            ));
        }

        return $state;
    }

    public static function permissionFieldFor(string $permission, ?string $group = null): string
    {
        return self::permissionFieldName(
            ArabicDashboardLabels::permissionSectionName($permission, $group),
        );
    }

    /** @return array<int, Section> */
    private static function permissionSections(): array
    {
        $sections = [];

        foreach (self::permissionGroups() as $section => $options) {
            $sections[] = Section::make($section)
                ->schema([
                    CheckboxList::make(self::permissionFieldName($section))
                        ->hiddenLabel()
                        ->options($options)
                        ->columns(2)
                        ->searchable()
                        ->bulkToggleable()
                        ->dehydrated(true),
                ])
                ->collapsible()
                ->columnSpanFull();
        }

        return $sections;
    }

    /** @return array<string, array<string, string>> */
    private static function permissionGroups(): array
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

    private static function permissionFieldName(string $section): string
    {
        return self::PermissionFieldPrefix.md5($section);
    }
}
