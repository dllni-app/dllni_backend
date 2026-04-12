<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOffers;

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
use Modules\Supermarket\Models\SmOffer;
use UnitEnum;

final class SmOfferResource extends Resource
{
    protected static ?string $model = SmOffer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = null;

    protected static string|UnitEnum|null $navigationGroup = 'قسم المتاجر';

    protected static ?int $navigationSort = 6;

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationLabel(): string
    {
        return __('supermarket_admin.offers');
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

    public static function getPages(): array
    {
        return [
            'index' => ListSmOffers::route('/'),
            'view' => ViewSmOffer::route('/{record}'),
            'edit' => EditSmOffer::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
