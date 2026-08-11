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

        foreach (self::groupedPermissionOptions() as $mainSection => $resourceSections) {
            foreach ($resourceSections as $resourceSection => $options) {
                $field = self::permissionFieldName($mainSection, $resourceSection);

                foreach ((array) ($data[$field] ?? []) as $permission) {
                    if (is_string($permission) && array_key_exists($permission, $options)) {
                        $selected[] = $permission;
                    }
                }

                unset($data[$field]);
            }
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

        foreach (self::groupedPermissionOptions() as $mainSection => $resourceSections) {
            foreach ($resourceSections as $resourceSection => $options) {
                $state[self::permissionFieldName($mainSection, $resourceSection)] = array_values(array_filter(
                    array_keys($options),
                    static fn (string $permission): bool => isset($selectedLookup[$permission]),
                ));
            }
        }

        return $state;
    }

    public static function permissionFieldFor(string $permission, ?string $group = null): string
    {
        $mainSection = ArabicDashboardLabels::permissionMainSectionName($permission, $group);
        $resourceSection = ArabicDashboardLabels::permissionSectionName($permission, $group);

        return self::permissionFieldName($mainSection, $resourceSection);
    }

    /**
     * @return array<string, array<string, array<string, string>>>
     */
    public static function groupedPermissionOptions(): array
    {
        $sections = [];

        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get(['name', 'slug', 'group']);

        foreach ($permissions as $permission) {
            $mainSection = ArabicDashboardLabels::permissionMainSectionName(
                $permission->name,
                $permission->group,
            );
            $resourceSection = ArabicDashboardLabels::permissionSectionName(
                $permission->name,
                $permission->group,
            );

            $sections[$mainSection][$resourceSection][$permission->name] = ArabicDashboardLabels::permissionName(
                $permission->name,
                $permission->slug,
            );
        }

        foreach ($sections as &$resourceSections) {
            ksort($resourceSections, SORT_NATURAL);

            foreach ($resourceSections as &$options) {
                asort($options, SORT_NATURAL);
            }
        }

        $ordered = [];

        foreach (ArabicDashboardLabels::permissionMainSectionOrder() as $mainSection) {
            if (isset($sections[$mainSection])) {
                $ordered[$mainSection] = $sections[$mainSection];
                unset($sections[$mainSection]);
            }
        }

        foreach ($sections as $mainSection => $resourceSections) {
            $ordered[$mainSection] = $resourceSections;
        }

        return $ordered;
    }

    /** @return array<int, Section> */
    private static function permissionSections(): array
    {
        $sections = [];

        foreach (self::groupedPermissionOptions() as $mainSection => $resourceSections) {
            $resourceCards = [];

            foreach ($resourceSections as $resourceSection => $options) {
                $resourceCards[] = Section::make($resourceSection)
                    ->schema([
                        CheckboxList::make(self::permissionFieldName($mainSection, $resourceSection))
                            ->hiddenLabel()
                            ->options($options)
                            ->columns(2)
                            ->searchable()
                            ->bulkToggleable()
                            ->dehydrated(true),
                    ])
                    ->collapsible()
                    ->columnSpan(1);
            }

            $sections[] = Section::make($mainSection)
                ->description('الصلاحيات الخاصة بهذا القسم من لوحة الإدارة.')
                ->schema($resourceCards)
                ->columns([
                    'default' => 1,
                    'xl' => 2,
                ])
                ->collapsible()
                ->columnSpanFull();
        }

        return $sections;
    }

    private static function permissionFieldName(string $mainSection, string $resourceSection): string
    {
        return self::PermissionFieldPrefix.md5($mainSection.'|'.$resourceSection);
    }
}
