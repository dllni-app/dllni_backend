<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOrderDisputes;

use App\Filament\Concerns\ResolvesSupermarketNavigationGroup;
use App\Filament\Resources\SmOrderDisputes\Pages\EditSmOrderDispute;
use App\Filament\Resources\SmOrderDisputes\Pages\ListSmOrderDisputes;
use App\Filament\Resources\SmOrderDisputes\Pages\ViewSmOrderDispute;
use App\Filament\Resources\SmOrderDisputes\RelationManagers\SmOrderDisputeMessagesRelationManager;
use App\Filament\Resources\SmOrderDisputes\Schemas\SmOrderDisputeForm;
use App\Filament\Resources\SmOrderDisputes\Schemas\SmOrderDisputeInfolist;
use App\Filament\Resources\SmOrderDisputes\Tables\SmOrderDisputesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\Supermarket\Models\SmOrderDispute;

final class SmOrderDisputeResource extends Resource
{
    use ResolvesSupermarketNavigationGroup;

    protected static ?string $model = SmOrderDispute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 4;

    protected static bool $shouldRegisterNavigation = true;

    public static function getNavigationLabel(): string
    {
        return __('supermarket_admin.disputes');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('supermarket_admin.tooltips.disputes');
    }

    public static function form(Schema $schema): Schema
    {
        return SmOrderDisputeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SmOrderDisputeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SmOrderDisputesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SmOrderDisputeMessagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmOrderDisputes::route('/'),
            'view' => ViewSmOrderDispute::route('/{record}'),
            'edit' => EditSmOrderDispute::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
