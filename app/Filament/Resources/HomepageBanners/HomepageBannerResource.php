<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageBanners;

use App\Filament\Resources\HomepageBanners\Pages\ListHomepageBanners;
use App\Filament\Resources\HomepageBanners\Schemas\HomepageBannerForm;
use App\Filament\Resources\HomepageBanners\Tables\HomepageBannersTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\User\Models\MarketingOffer;

final class HomepageBannerResource extends Resource
{
    protected static ?string $model = MarketingOffer::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static ?int $navigationSort = 14;

    public static function getNavigationGroup(): ?string
    {
        return __('restaurant_admin.general_sections');
    }

    public static function getNavigationLabel(): string
    {
        return app()->isLocale('ar') ? 'بنرات الصفحة الرئيسية' : 'Homepage Banners';
    }

    public static function getNavigationTooltip(): ?string
    {
        return app()->isLocale('ar')
            ? 'إضافة وحذف وترتيب بنرات الصفحة الرئيسية لتطبيق المستخدم.'
            : 'Add, delete, and reorder user app homepage banners.';
    }

    public static function getModelLabel(): string
    {
        return app()->isLocale('ar') ? 'بانر الصفحة الرئيسية' : 'Homepage Banner';
    }

    public static function getPluralModelLabel(): string
    {
        return app()->isLocale('ar') ? 'بنرات الصفحة الرئيسية' : 'Homepage Banners';
    }

    public static function form(Schema $schema): Schema
    {
        return HomepageBannerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HomepageBannersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('media');
    }

    public static function canViewAny(): bool
    {
        return self::hasPermission('offers.view');
    }

    public static function canView(Model $record): bool
    {
        return false;
    }

    public static function canCreate(): bool
    {
        return self::hasPermission('offers.create');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return self::hasPermission('offers.delete');
    }

    public static function canReorderBanners(): bool
    {
        return self::hasPermission('offers.update');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHomepageBanners::route('/'),
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
