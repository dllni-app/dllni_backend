<?php

declare(strict_types=1);

namespace App\Filament\Resources\AppCustomers;

use App\Filament\Resources\AppCustomers\Pages\EditAppCustomer;
use App\Filament\Resources\AppCustomers\Pages\ListAppCustomers;
use App\Filament\Resources\AppCustomers\Pages\ViewAppCustomer;
use App\Filament\Resources\AppCustomers\RelationManagers\AddressesRelationManager;
use App\Filament\Resources\AppCustomers\RelationManagers\CleaningBookingsRelationManager;
use App\Filament\Resources\AppCustomers\Schemas\AppCustomerForm;
use App\Filament\Resources\AppCustomers\Schemas\AppCustomerInfolist;
use App\Filament\Resources\AppCustomers\Tables\AppCustomersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class AppCustomerResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 33;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return 'عملاء التطبيق';
    }

    public static function getModelLabel(): string
    {
        return 'عميل';
    }

    public static function getPluralModelLabel(): string
    {
        return 'عملاء التطبيق';
    }

    public static function getNavigationTooltip(): ?string
    {
        return 'إدارة عملاء تطبيق المستخدم النهائي (الاسم والبريد والهاتف والعناوين والطلبات).';
    }

    public static function form(Schema $schema): Schema
    {
        return AppCustomerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AppCustomerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AppCustomersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AddressesRelationManager::class,
            CleaningBookingsRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereNull('module_type')
            ->whereDoesntHave('roles', fn (Builder $query): Builder => $query->whereIn('name', [
                'admin',
                'Super Admin',
                'Cleaning Ops Manager',
                'Customer Support',
                'Onboarding Specialist',
                'Accountant',
                'delivery_company_admin',
                'delivery_company_staff',
            ]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppCustomers::route('/'),
            'view' => ViewAppCustomer::route('/{record}'),
            'edit' => EditAppCustomer::route('/{record}/edit'),
        ];
    }
}
