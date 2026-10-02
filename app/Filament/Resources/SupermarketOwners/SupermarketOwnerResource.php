<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupermarketOwners;

use App\Enums\UserModuleType;
use App\Filament\Concerns\AuthorizesPlatformAdminResource;
use App\Filament\Resources\SupermarketOwners\Pages\CreateSupermarketOwner;
use App\Filament\Resources\SupermarketOwners\Pages\EditSupermarketOwner;
use App\Filament\Resources\SupermarketOwners\Pages\ListSupermarketOwners;
use App\Filament\Resources\SupermarketOwners\Pages\ViewSupermarketOwner;
use App\Filament\Resources\SupermarketOwners\RelationManagers\StoresRelationManager;
use App\Filament\Resources\SupermarketOwners\Schemas\SupermarketOwnerForm;
use App\Filament\Resources\SupermarketOwners\Schemas\SupermarketOwnerInfolist;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class SupermarketOwnerResource extends Resource
{
    use AuthorizesPlatformAdminResource;

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::supermarkets();
    }

    public static function getNavigationLabel(): string
    {
        return 'مالكو المتاجر';
    }

    public static function getModelLabel(): string
    {
        return __('admin_resources.supermarket_owner.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin_resources.supermarket_owner.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return SupermarketOwnerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SupermarketOwnerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('module_type', UserModuleType::SupermarketSeller)->whereDoesntHave('smStoreStaff');
    }

    public static function getRelations(): array
    {
        return [StoresRelationManager::class];
    }

    public static function canViewAny(): bool
    {
        return self::dashboardAllowed('supermarket_stores.view');
    }

    public static function canView(Model $record): bool
    {
        return self::dashboardAllowed('supermarket_stores.view');
    }

    public static function canCreate(): bool
    {
        return self::dashboardAllowed('supermarket_stores.create');
    }

    public static function canEdit(Model $record): bool
    {
        return self::dashboardAllowed('supermarket_stores.update');
    }

    public static function canDelete(Model $record): bool
    {
        return self::dashboardAllowed('supermarket_stores.delete');
    }

    public static function getPages(): array
    {
        return ['index' => ListSupermarketOwners::route('/'), 'create' => CreateSupermarketOwner::route('/create'), 'view' => ViewSupermarketOwner::route('/{record}'), 'edit' => EditSupermarketOwner::route('/{record}/edit')];
    }
}
