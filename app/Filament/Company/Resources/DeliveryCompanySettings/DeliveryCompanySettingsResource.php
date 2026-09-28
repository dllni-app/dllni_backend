<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryCompanySettings;

use App\Filament\Company\Resources\DeliveryCompanySettings\Pages\EditDeliveryCompanySettings;
use App\Filament\Company\Resources\DeliveryCompanySettings\Pages\ListDeliveryCompanySettings;
use App\Filament\Company\Resources\DeliveryCompanySettings\Schemas\DeliveryCompanySettingsForm;
use App\Filament\Company\Resources\DeliveryCompanySettings\Tables\DeliveryCompanySettingsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Services\DeliveryCompanyContextService;

final class DeliveryCompanySettingsResource extends Resource
{
    protected static ?string $model = DeliveryCompany::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return __('delivery_company.nav_groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('delivery_company.company.nav_label');
    }

    public static function getModelLabel(): string
    {
        return __('delivery_company.company.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('delivery_company.company.nav_label');
    }

    public static function form(Schema $schema): Schema
    {
        return DeliveryCompanySettingsForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeliveryCompanySettingsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        if (! $user) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        $companyId = app(DeliveryCompanyContextService::class)->companyIdForUser($user);

        return parent::getEloquentQuery()->whereKey($companyId);
    }

    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        if (! auth()->user()?->hasRole('delivery_company_admin')) {
            return false;
        }

        $companyId = app(DeliveryCompanyContextService::class)->companyIdForUser(auth()->user());

        return (int) $record->getKey() === $companyId;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeliveryCompanySettings::route('/'),
            'edit' => EditDeliveryCompanySettings::route('/{record}/edit'),
        ];
    }
}
