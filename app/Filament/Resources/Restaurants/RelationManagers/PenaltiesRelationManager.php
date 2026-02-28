<?php

declare(strict_types=1);

namespace App\Filament\Resources\Restaurants\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Resturants\Enums\PenaltyType;

final class PenaltiesRelationManager extends RelationManager
{
    protected static string $relationship = 'penalties';

    protected static ?string $title = 'العقوبات';

    public function table(Table $table): Table
    {
        $penaltyTypeLabels = [
            PenaltyType::Warning->value => __('restaurant_admin.enums.penalty_type.warning'),
            PenaltyType::Fine->value => __('restaurant_admin.enums.penalty_type.fine'),
            PenaltyType::Suspension->value => __('restaurant_admin.enums.penalty_type.suspension'),
        ];

        return $table
            ->columns([
                TextColumn::make('penalty_type')
                    ->label('نوع العقوبة')
                    ->formatStateUsing(fn (string $state) => $penaltyTypeLabels[$state] ?? $state)
                    ->badge(),
                TextColumn::make('amount')->label('المبلغ')->money('SAR')->placeholder('—'),
                TextColumn::make('reason')->label('السبب')->limit(60)->placeholder('—'),
                TextColumn::make('resolved_at')->label('تاريخ الحل')->dateTime('Y-m-d H:i')->placeholder('—'),
                TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
