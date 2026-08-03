<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBanners;

use App\Filament\Resources\CleaningBanners\Pages\CreateCleaningBanner;
use App\Filament\Resources\CleaningBanners\Pages\EditCleaningBanner;
use App\Filament\Resources\CleaningBanners\Pages\ListCleaningBanners;
use App\Filament\Resources\CleaningBanners\Pages\ViewCleaningBanner;
use App\Filament\Resources\CleaningBanners\Schemas\CleaningBannerForm;
use App\Filament\Resources\CleaningBanners\Schemas\CleaningBannerInfolist;
use App\Filament\Resources\CleaningBanners\Tables\CleaningBannersTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\CleaningBanner;

final class CleaningBannerResource extends Resource
{
    protected static ?string $model = CleaningBanner::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static ?int $navigationSort = 23;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.cleaning_banners.nav_label');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('cleaning_admin.cleaning_banners.tooltip');
    }

    public static function getModelLabel(): string
    {
        return __('cleaning_admin.cleaning_banners.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cleaning_admin.cleaning_banners.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return CleaningBannerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CleaningBannerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CleaningBannersTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return self::hasPermission('banners.view');
    }

    public static function canView(Model $record): bool
    {
        return self::hasPermission('banners.view');
    }

    public static function canCreate(): bool
    {
        return self::hasPermission('banners.create');
    }

    public static function canEdit(Model $record): bool
    {
        return self::hasPermission('banners.update');
    }

    public static function canDelete(Model $record): bool
    {
        return self::hasPermission('banners.delete');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningBanners::route('/'),
            'create' => CreateCleaningBanner::route('/create'),
            'view' => ViewCleaningBanner::route('/{record}'),
            'edit' => EditCleaningBanner::route('/{record}/edit'),
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
