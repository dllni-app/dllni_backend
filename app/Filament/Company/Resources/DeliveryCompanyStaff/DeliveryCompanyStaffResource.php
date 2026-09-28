<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryCompanyStaff;

use App\Filament\Company\Concerns\InteractsWithDeliveryCompany;
use App\Filament\Company\Resources\DeliveryCompanyStaff\Pages\CreateDeliveryCompanyStaff;
use App\Filament\Company\Resources\DeliveryCompanyStaff\Pages\EditDeliveryCompanyStaff;
use App\Filament\Company\Resources\DeliveryCompanyStaff\Pages\ListDeliveryCompanyStaff;
use App\Filament\Company\Resources\DeliveryCompanyStaff\Schemas\DeliveryCompanyStaffForm;
use App\Filament\Company\Resources\DeliveryCompanyStaff\Tables\DeliveryCompanyStaffTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Delivery\Models\DeliveryCompanyStaff;
use Modules\Delivery\Services\DeliveryCompanyContextService;

final class DeliveryCompanyStaffResource extends Resource
{
    use InteractsWithDeliveryCompany;

    protected static ?string $model = DeliveryCompanyStaff::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('delivery_company.nav_groups.team');
    }

    public static function getNavigationLabel(): string
    {
        return __('delivery_company.staff.nav_label');
    }

    public static function getModelLabel(): string
    {
        return __('delivery_company.staff.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('delivery_company.staff.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return DeliveryCompanyStaffForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeliveryCompanyStaffTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return self::companyScopedQuery(parent::getEloquentQuery()->with('user'));
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('delivery_company_admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        if (! self::canViewAny() || ! $record instanceof DeliveryCompanyStaff) {
            return false;
        }

        $companyId = app(DeliveryCompanyContextService::class)->companyIdForUser(auth()->user());

        return (int) $record->company_id === $companyId;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeliveryCompanyStaff::route('/'),
            'create' => CreateDeliveryCompanyStaff::route('/create'),
            'edit' => EditDeliveryCompanyStaff::route('/{record}/edit'),
        ];
    }
}
