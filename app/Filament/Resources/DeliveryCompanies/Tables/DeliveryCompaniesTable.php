<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryCompanies\Tables;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class DeliveryCompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('delivery_admin.companies.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('owner.name')
                    ->label(__('delivery_admin.companies.fields.owner'))
                    ->searchable(),
                TextColumn::make('financialAccount.current_balance')
                    ->label(__('delivery_company.financial.fields.current_balance'))
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('financial_limit')
                    ->label(__('delivery_company.financial.fields.financial_limit'))
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                IconColumn::make('is_suspended')
                    ->label(__('delivery_company.financial.fields.is_suspended'))
                    ->boolean(),
                TextColumn::make('phone')
                    ->label(__('delivery_admin.companies.fields.phone'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name');
    }
}
