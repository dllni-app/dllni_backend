<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOffers;

use App\Filament\Concerns\AuthorizesPlatformAdminResource;
use App\Filament\Concerns\ResolvesSupermarketNavigationGroup;
use App\Filament\Resources\SmOffers\Pages\EditSmOffer;
use App\Filament\Resources\SmOffers\Pages\ListSmOffers;
use App\Filament\Resources\SmOffers\Pages\ViewSmOffer;
use App\Filament\Resources\SmOffers\Schemas\SmOfferForm;
use App\Filament\Resources\SmOffers\Schemas\SmOfferInfolist;
use App\Filament\Resources\SmOffers\Tables\SmOffersTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Supermarket\Models\SmOffer;

final class SmOfferResource extends Resource
{
    use AuthorizesPlatformAdminResource;
    use ResolvesSupermarketNavigationGroup;

    protected static ?string $model = SmOffer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 11;

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::supermarkets();
    }

    public static function getNavigationLabel(): string
    {
        return __('supermarket_admin.offers');
    }

    public static function getModelLabel(): string
    {
        return __('admin_resources.supermarket_offer.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin_resources.supermarket_offer.plural');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('supermarket_admin.tooltips.offers');
    }

    public static function form(Schema $schema): Schema
    {
        return SmOfferForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SmOfferInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SmOffersTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return self::dashboardAllowed('supermarket_catalog.view');
    }

    public static function canView(Model $record): bool
    {
        return self::dashboardAllowed('supermarket_catalog.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canModerate(): bool
    {
        return self::dashboardAllowed('supermarket_catalog.update');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmOffers::route('/'),
            'view' => ViewSmOffer::route('/{record}'),
            'edit' => EditSmOffer::route('/{record}/edit'),
        ];
    }
}
