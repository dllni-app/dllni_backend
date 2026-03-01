<?php

declare(strict_types=1);

namespace App\Filament\Resources\Disputes;

use App\Filament\Resources\Disputes\Pages\CreateDispute;
use App\Filament\Resources\Disputes\Pages\EditDispute;
use App\Filament\Resources\Disputes\Pages\ListDisputes;
use App\Filament\Resources\Disputes\Pages\ViewDispute;
use App\Filament\Resources\Disputes\Schemas\DisputeForm;
use App\Filament\Resources\Disputes\Schemas\DisputeInfolist;
use App\Filament\Resources\Disputes\Tables\DisputesTable;
use App\Models\Dispute;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

final class DisputeResource extends Resource
{
    protected static ?string $model = Dispute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.disputes.nav_label');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('cleaning_admin.disputes.tooltip');
    }

    public static function form(Schema $schema): Schema
    {
        return DisputeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DisputeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DisputesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDisputes::route('/'),
            'create' => CreateDispute::route('/create'),
            'view' => ViewDispute::route('/{record}'),
            'edit' => EditDispute::route('/{record}/edit'),
        ];
    }
}
