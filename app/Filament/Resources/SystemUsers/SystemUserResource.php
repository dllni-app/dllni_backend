<?php

declare(strict_types=1);

namespace App\Filament\Resources\SystemUsers;

use App\Filament\Resources\SystemUsers\Pages\ListSystemUsers;
use App\Filament\Resources\SystemUsers\Pages\ViewSystemUser;
use App\Filament\Resources\SystemUsers\Schemas\SystemUserInfolist;
use App\Filament\Resources\SystemUsers\Tables\SystemUsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class SystemUserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 15;

    public static function getNavigationGroup(): ?string
    {
        return __('restaurant_admin.general_sections');
    }

    public static function getNavigationLabel(): string
    {
        return 'مستخدمو النظام';
    }

    public static function getNavigationTooltip(): ?string
    {
        return 'عرض حسابات المستخدمين وإلغاء تفعيلها أو إعادة تفعيلها.';
    }

    public static function getModelLabel(): string
    {
        return 'مستخدم';
    }

    public static function getPluralModelLabel(): string
    {
        return 'مستخدمو النظام';
    }

    public static function infolist(Schema $schema): Schema
    {
        return SystemUserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SystemUsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereDoesntHave('roles', fn (Builder $query) => $query->whereIn('name', [
                'admin',
                'Super Admin',
                'Cleaning Ops Manager',
                'Customer Support',
                'Onboarding Specialist',
                'Accountant',
                'delivery_company_admin',
                'delivery_company_staff',
            ]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSystemUsers::route('/'),
            'view' => ViewSystemUser::route('/{record}'),
        ];
    }
}
