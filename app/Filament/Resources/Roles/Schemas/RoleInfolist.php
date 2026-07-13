<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Schemas;

use App\Filament\Support\ArabicDashboardLabels;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;

final class RoleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('بيانات الدور')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')
                            ->label('اسم الدور')
                            ->formatStateUsing(fn (?string $state): string => ArabicDashboardLabels::roleName($state)),
                        TextEntry::make('permissions_count')
                            ->label('عدد الصلاحيات')
                            ->state(fn (Role $record): int => $record->permissions()->count()),
                    ]),
                Section::make('الصلاحيات')
                    ->schema([
                        TextEntry::make('translated_permissions')
                            ->hiddenLabel()
                            ->state(fn (Role $record): string => $record->permissions
                                ->map(fn ($permission): string => ArabicDashboardLabels::permissionName(
                                    $permission->name,
                                    $permission->slug,
                                ))
                                ->sort()
                                ->implode('، '))
                            ->placeholder('لا توجد صلاحيات')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
