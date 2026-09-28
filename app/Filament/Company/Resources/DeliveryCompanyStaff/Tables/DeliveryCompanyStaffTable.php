<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryCompanyStaff\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class DeliveryCompanyStaffTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('delivery_company.staff.fields.name'))
                    ->searchable(),
                TextColumn::make('user.email')
                    ->label(__('delivery_company.staff.fields.email'))
                    ->placeholder('—'),
                TextColumn::make('user.phone')
                    ->label(__('delivery_company.staff.fields.phone'))
                    ->placeholder('—'),
                TextColumn::make('role_key')
                    ->label(__('delivery_company.staff.fields.role'))
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? __('delivery_company.staff.roles.'.$state)
                        : '—'),
                IconColumn::make('is_active')
                    ->label(__('delivery_company.staff.fields.is_active'))
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label(__('delivery_company.staff.fields.created_at'))
                    ->dateTime('Y-m-d H:i'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
