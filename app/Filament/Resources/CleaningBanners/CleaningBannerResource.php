<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBanners;

use App\Filament\Resources\CleaningBanners\Pages\ListCleaningBanners;
use App\Filament\Resources\CleaningBanners\Schemas\CleaningBannerForm;
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
        return app()->isLocale('ar')
            ? 'إضافة وحذف وترتيب بنرات قسم التنظيف في تطبيق المستخدم.'
            : 'Add, delete, and reorder cleaning banners in the user app.';
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
        return false;
    }

    public static function canCreate(): bool
    {
        return self::hasPermission('banners.create');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return self::hasPermission('banners.delete');
    }

    public static function canReorderBanners(): bool
    {
        return self::hasPermission('banners.update');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningBanners::route('/'),
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
