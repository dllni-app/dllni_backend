<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningPriceAdjustmentRequests;

use App\Filament\Resources\CleaningPriceAdjustmentRequests\Pages\ListCleaningPriceAdjustmentRequests;
use App\Filament\Resources\CleaningPriceAdjustmentRequests\Pages\ViewCleaningPriceAdjustmentRequest;
use App\Filament\Resources\CleaningPriceAdjustmentRequests\Schemas\CleaningPriceAdjustmentRequestInfolist;
use App\Filament\Resources\CleaningPriceAdjustmentRequests\Tables\CleaningPriceAdjustmentRequestsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Enums\CleaningPriceAdjustmentRequestStatus;
use Modules\Cleaning\Models\CleaningBookingPriceAdjustmentRequest;

final class CleaningPriceAdjustmentRequestResource extends Resource
{
    protected static ?string $model = CleaningBookingPriceAdjustmentRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return 'طلبات تعديل السعر';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = CleaningBookingPriceAdjustmentRequest::query()
            ->where('status', CleaningPriceAdjustmentRequestStatus::Pending->value)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getModelLabel(): string
    {
        return 'طلب تعديل سعر';
    }

    public static function getPluralModelLabel(): string
    {
        return 'طلبات تعديل السعر';
    }

    public static function infolist(Schema $schema): Schema
    {
        return CleaningPriceAdjustmentRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CleaningPriceAdjustmentRequestsTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return self::hasPermission('bookings.view');
    }

    public static function canView(Model $record): bool
    {
        return self::hasPermission('bookings.view');
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

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningPriceAdjustmentRequests::route('/'),
            'view' => ViewCleaningPriceAdjustmentRequest::route('/{record}'),
        ];
    }

    private static function hasPermission(string $permission): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(['admin', 'Super Admin'])) {
            return true;
        }

        return $user->can($permission);
    }
}
