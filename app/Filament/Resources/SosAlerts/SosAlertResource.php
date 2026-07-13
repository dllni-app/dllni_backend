<?php

declare(strict_types=1);

namespace App\Filament\Resources\SosAlerts;

use App\Enums\SOSStatus;
use App\Filament\Resources\SosAlerts\Pages\ListSosAlerts;
use App\Filament\Resources\SosAlerts\Pages\ViewSosAlert;
use App\Filament\Resources\SosAlerts\Schemas\SosAlertInfolist;
use App\Filament\Resources\SosAlerts\Tables\SosAlertsTable;
use App\Models\SosAlert;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\CleaningBooking;

final class SosAlertResource extends Resource
{
    protected static ?string $model = SosAlert::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 5;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return 'بلاغات الطوارئ (SOS)';
    }

    public static function getNavigationTooltip(): ?string
    {
        return 'متابعة بلاغات الطوارئ المرسلة من العملاء وعمال التنظيف.';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = SosAlert::query()
            ->whereIn('booking_type', ['cleaning_booking', CleaningBooking::class])
            ->whereIn('status', [
                SOSStatus::Pending->value,
                SOSStatus::Triggered->value,
                SOSStatus::Acknowledged->value,
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
        return 'بلاغ طوارئ';
    }

    public static function getPluralModelLabel(): string
    {
        return 'بلاغات الطوارئ';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function infolist(Schema $schema): Schema
    {
        return SosAlertInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SosAlertsTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return self::isDashboardAdmin();
    }

    public static function canView(Model $record): bool
    {
        return self::isDashboardAdmin();
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('booking_type', ['cleaning_booking', CleaningBooking::class])
            ->with([
                'user',
                'booking',
                'acknowledgedBy',
                'resolvedBy',
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSosAlerts::route('/'),
            'view' => ViewSosAlert::route('/{record}'),
        ];
    }

    private static function isDashboardAdmin(): bool
    {
        $user = auth()->user();

        return $user?->hasAnyRole(['admin', 'Super Admin']) ?? false;
    }
}
