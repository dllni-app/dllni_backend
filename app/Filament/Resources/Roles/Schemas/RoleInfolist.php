<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Schemas;

use App\Filament\Support\ArabicDashboardLabels;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Spatie\Permission\Models\Role;

final class RoleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(12)
                    ->schema([
                        Section::make('بيانات الدور')
                            ->schema([
                                TextEntry::make('name')
                                    ->label('اسم الدور')
                                    ->formatStateUsing(fn (?string $state): string => ArabicDashboardLabels::roleName($state))
                                    ->icon(Heroicon::OutlinedShieldCheck)
                                    ->weight('bold')
                                    ->size('lg'),
                                TextEntry::make('permissions_count')
                                    ->label('عدد الصلاحيات')
                                    ->state(fn (Role $record): int => (int) ($record->permissions_count ?? $record->permissions->count()))
                                    ->icon(Heroicon::OutlinedKey)
                                    ->weight('bold'),
                            ])
                            ->columns([
                                'default' => 1,
                                'md' => 2,
                            ])
                            ->columnSpanFull(),
                        ...self::permissionMainSections(),
                    ]),
            ]);
    }

    /**
     * @return array<int, Section>
     */
    private static function permissionMainSections(): array
    {
        $sections = [];

        foreach (RoleForm::groupedPermissionOptions() as $mainSection => $resourceSections) {
            $entries = [];

            foreach ($resourceSections as $resourceSection => $options) {
                $entries[] = TextEntry::make('permission_section_'.md5($mainSection.'|'.$resourceSection))
                    ->label($resourceSection)
                    ->state(fn (Role $record): array => self::selectedPermissionLabels($record, $options))
                    ->badge()
                    ->color('gray')
                    ->placeholder('لا توجد صلاحيات')
                    ->hidden(fn (Role $record): bool => self::selectedPermissionLabels($record, $options) === [])
                    ->columnSpan(1);
            }

            $sections[] = Section::make($mainSection)
                ->description(fn (Role $record): string => 'إجمالي صلاحيات القسم: '.self::selectedPermissionCount($record, $resourceSections))
                ->schema($entries)
                ->columns([
                    'default' => 1,
                    'md' => 2,
                    'xl' => 3,
                    '2xl' => 4,
                ])
                ->hidden(fn (Role $record): bool => self::selectedPermissionCount($record, $resourceSections) === 0)
                ->collapsible()
                ->columnSpanFull();
        }

        return $sections;
    }

    /**
     * @param  array<string, string>  $options
     * @return array<int, string>
     */
    private static function selectedPermissionLabels(Role $record, array $options): array
    {
        $selectedNames = $record->permissions
            ->pluck('name')
            ->filter(fn (mixed $name): bool => is_string($name))
            ->all();

        return array_values(array_intersect_key(
            $options,
            array_fill_keys($selectedNames, true),
        ));
    }

    /**
     * @param  array<string, array<string, string>>  $resourceSections
     */
    private static function selectedPermissionCount(Role $record, array $resourceSections): int
    {
        return array_sum(array_map(
            fn (array $options): int => count(self::selectedPermissionLabels($record, $options)),
            $resourceSections,
        ));
    }
}
