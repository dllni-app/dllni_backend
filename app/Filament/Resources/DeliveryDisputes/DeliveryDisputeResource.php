<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryDisputes;

use App\Filament\Concerns\AuthorizesPlatformAdminResource;
use App\Filament\Resources\DeliveryDisputes\Pages\ListDeliveryDisputes;
use App\Filament\Resources\DeliveryDisputes\Pages\ViewDeliveryDispute;
use App\Filament\Resources\DeliveryDisputes\Schemas\DeliveryDisputeInfolist;
use App\Filament\Resources\DeliveryDisputes\Tables\DeliveryDisputesTable;
use App\Models\Dispute;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class DeliveryDisputeResource extends Resource
{
    use AuthorizesPlatformAdminResource;

    protected static ?string $model = Dispute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'نزاعات التوصيل';

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::delivery();
    }

    public static function getModelLabel(): string
    {
        return __('admin_resources.delivery_dispute.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin_resources.delivery_dispute.plural');
    }

    public static function table(Table $table): Table
    {
        return DeliveryDisputesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DeliveryDisputeInfolist::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('booking_type', 'delivery_order')
            ->with(['booking.company', 'booking.driver.user', 'messages.sender', 'trustLogs']);
    }

    public static function canViewAny(): bool
    {
        return self::dashboardAllowed('platform_delivery_operations.view');
    }

    public static function canView(Model $record): bool
    {
        return self::dashboardAllowed('platform_delivery_operations.view') && $record instanceof Dispute && $record->booking_type === 'delivery_order';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canIntervene(): bool
    {
        return self::dashboardAllowed('platform_delivery_operations.update');
    }

    public static function getPages(): array
    {
        return ['index' => ListDeliveryDisputes::route('/'), 'view' => ViewDeliveryDispute::route('/{record}')];
    }
}
