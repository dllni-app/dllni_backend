<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryCompanies\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class DeliveryCompanyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('delivery_admin.companies.sections.profile'))
                ->columns(2)
                ->schema([
                    TextEntry::make('name')->label(__('delivery_admin.companies.fields.name')),
                    TextEntry::make('legal_name')->label(__('delivery_admin.companies.fields.legal_name')),
                    TextEntry::make('owner.name')->label(__('delivery_admin.companies.fields.owner')),
                    TextEntry::make('phone')->label(__('delivery_admin.companies.fields.phone')),
                    TextEntry::make('email')->label(__('delivery_admin.companies.fields.email')),
                    TextEntry::make('address')->label(__('delivery_admin.companies.fields.address')),
                ]),
            Section::make(__('delivery_company.financial.sections.summary'))
                ->columns(2)
                ->schema([
                    TextEntry::make('financialAccount.current_balance')
                        ->label(__('delivery_company.financial.fields.current_balance'))
                        ->numeric(decimalPlaces: 2),
                    TextEntry::make('financial_limit')
                        ->label(__('delivery_company.financial.fields.financial_limit'))
                        ->numeric(decimalPlaces: 2),
                    TextEntry::make('financialAccount.currency')
                        ->label(__('delivery_company.financial.fields.currency')),
                    IconEntry::make('is_suspended')
                        ->label(__('delivery_company.financial.fields.is_suspended'))
                        ->boolean(),
                    TextEntry::make('suspension_reason')
                        ->label(__('delivery_company.financial.fields.suspension_reason'))
                        ->placeholder('—'),
                ]),
        ]);
    }
}
