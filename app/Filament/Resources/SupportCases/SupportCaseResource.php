<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportCases;

use App\Enums\SupportCaseStatus;
use App\Filament\Resources\SupportCases\Pages\ListSupportCases;
use App\Filament\Resources\SupportCases\Pages\ViewSupportCase;
use App\Filament\Resources\SupportCases\Schemas\SupportCaseInfolist;
use App\Filament\Resources\SupportCases\Tables\SupportCasesTable;
use App\Models\SupportCase;
use App\Support\SupportCaseBookingPresentation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class SupportCaseResource extends Resource
{
    protected static ?string $model = SupportCase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return 'العمليات والدعم';
    }

    public static function getNavigationLabel(): string
    {
        return 'البلاغات والنزاعات';
    }

    public static function getNavigationTooltip(): ?string
    {
        return 'واجهة موحدة لبلاغات الطوارئ والدعم المرتبطة بالتنظيف والمطاعم والسوبرماركت والتوصيل.';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()
            ->whereIn('status', SupportCaseStatus::activeValues())
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'danger';
    }

    public static function getModelLabel(): string
    {
        return 'بلاغ دعم';
    }

    public static function getPluralModelLabel(): string
    {
        return 'البلاغات والنزاعات';
    }

    public static function infolist(Schema $schema): Schema
    {
        return SupportCaseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SupportCasesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'reporter',
                'booking' => function (MorphTo $morphTo): void {
                    $morphTo->morphWith(SupportCaseBookingPresentation::morphRelations());
                },
            ]);
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

    public static function getPages(): array
    {
        return [
            'index' => ListSupportCases::route('/'),
            'view' => ViewSupportCase::route('/{record}'),
        ];
    }

    private static function isDashboardAdmin(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'Super Admin']) ?? false;
    }
}
