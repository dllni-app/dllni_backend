<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningBillingPolicies\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class CleaningBillingPoliciesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable(),
                TextColumn::make('billing_mode')->label('طريقة الفوترة')->badge()->formatStateUsing(fn ($state) => $state?->label()),
                IconColumn::make('is_active')->label('نشط')->boolean(),
                IconColumn::make('is_default')->label('افتراضي')->boolean(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
