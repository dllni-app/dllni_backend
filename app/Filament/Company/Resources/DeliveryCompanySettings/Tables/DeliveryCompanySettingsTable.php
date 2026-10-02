<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryCompanySettings\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class DeliveryCompanySettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('delivery_company.company.fields.name')),
                TextColumn::make('phone')->label(__('delivery_company.company.fields.phone'))->placeholder('—'),
                TextColumn::make('email')->label(__('delivery_company.company.fields.email'))->placeholder('—'),
                IconColumn::make('is_active')->label(__('delivery_company.company.fields.is_active'))->boolean(),
                IconColumn::make('is_suspended')->label(__('delivery_company.company.fields.is_suspended'))->boolean(),
                TextColumn::make('financial_limit')
                    ->label(__('delivery_company.company.fields.financial_limit'))
                    ->money('SYP'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
