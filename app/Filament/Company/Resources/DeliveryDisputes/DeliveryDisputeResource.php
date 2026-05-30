<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryDisputes;

use App\Enums\PermissionGroup;
use App\Filament\Company\Concerns\ScopesDeliveryCompanyDisputes;
use App\Filament\Company\Resources\DeliveryDisputes\Pages\CreateDeliveryDispute;
use App\Filament\Company\Resources\DeliveryDisputes\Pages\ListDeliveryDisputes;
use App\Filament\Company\Resources\DeliveryDisputes\Pages\ViewDeliveryDispute;
use App\Filament\Company\Resources\DeliveryDisputes\Schemas\DeliveryDisputeForm;
use App\Filament\Company\Resources\DeliveryDisputes\Schemas\DeliveryDisputeInfolist;
use App\Filament\Company\Resources\DeliveryDisputes\Tables\DeliveryDisputesTable;
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
    use ScopesDeliveryCompanyDisputes;

    protected static ?string $model = Dispute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('delivery_company.nav_groups.disputes');
    }

    public static function getNavigationLabel(): string
    {
        return __('delivery_company.disputes.nav_label');
    }

    public static function getModelLabel(): string
    {
        return __('delivery_company.disputes.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('delivery_company.disputes.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return DeliveryDisputeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DeliveryDisputeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeliveryDisputesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return self::companyDisputesQuery(
            parent::getEloquentQuery()->with(['booking', 'messages.sender']),
        );
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(PermissionGroup::DeliveryDisputes->value.'.view') ?? false;
    }

    public static function canView(Model $record): bool
    {
        if (! auth()->user()?->can(PermissionGroup::DeliveryDisputes->value.'.view')) {
            return false;
        }

        return $record instanceof Dispute && self::disputeBelongsToCompany($record);
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(PermissionGroup::DeliveryDisputes->value.'.create') ?? false;
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
            'index' => ListDeliveryDisputes::route('/'),
            'create' => CreateDeliveryDispute::route('/create'),
            'view' => ViewDeliveryDispute::route('/{record}'),
        ];
    }
}
