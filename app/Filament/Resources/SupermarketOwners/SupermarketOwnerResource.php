<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupermarketOwners;

use App\Enums\UserModuleType;
use App\Filament\Resources\SupermarketOwners\Pages\CreateSupermarketOwner;
use App\Filament\Resources\SupermarketOwners\Pages\EditSupermarketOwner;
use App\Filament\Resources\SupermarketOwners\Pages\ListSupermarketOwners;
use App\Filament\Resources\SupermarketOwners\Pages\ViewSupermarketOwner;
use App\Filament\Resources\SupermarketOwners\Schemas\SupermarketOwnerForm;
use App\Filament\Resources\Users\Schemas\UserInfolist;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class SupermarketOwnerResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return 'إدارة المتاجر';
    }

    public static function getNavigationLabel(): string
    {
        return 'مالكو المتاجر';
    }

    public static function form(Schema $schema): Schema
    {
        return SupermarketOwnerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('module_type', UserModuleType::SupermarketSeller);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupermarketOwners::route('/'),
            'create' => CreateSupermarketOwner::route('/create'),
            'view' => ViewSupermarketOwner::route('/{record}'),
            'edit' => EditSupermarketOwner::route('/{record}/edit'),
        ];
    }
}
