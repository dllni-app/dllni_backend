<?php

declare(strict_types=1);

namespace App\Filament\Resources\Disputes;

use App\Enums\DisputeStatus;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\EventBooking;

final class DisputeResource extends Resource
{
    protected static ?string $model = Dispute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 4;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.disputes.nav_label');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('cleaning_admin.disputes.tooltip');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()
            ->whereIn('status', [
                DisputeStatus::Open->value,
                DisputeStatus::UnderReview->value,
            ])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'danger';
    }

    public static function getModelLabel(): string
    {
        return __('cleaning_admin.disputes.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cleaning_admin.disputes.plural');
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('booking_type', [
                'cleaning_booking',
                CleaningBooking::class,
                'event_booking',
                EventBooking::class,
            ]);
    }

    public static function canViewAny(): bool
    {
        return self::hasPermission('disputes.view');
    }

    public static function canView(Model $record): bool
    {
        return self::hasPermission('disputes.view');
    }

    public static function canCreate(): bool
    {
        return self::hasPermission('disputes.create');
    }

    public static function canEdit(Model $record): bool
    {
        return self::hasPermission('disputes.update');
    }

    public static function canDelete(Model $record): bool
    {
        return self::hasPermission('disputes.delete');
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
