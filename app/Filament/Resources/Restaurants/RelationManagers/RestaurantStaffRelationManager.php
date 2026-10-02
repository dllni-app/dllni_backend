<?php

declare(strict_types=1);

namespace App\Filament\Resources\Restaurants\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class RestaurantStaffRelationManager extends RelationManager
{
    protected static string $relationship = 'staff';

    protected static ?string $title = 'فريق المطعم';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('المستخدم')->searchable(),
                TextColumn::make('user.email')->label('البريد')->searchable(),
                TextColumn::make('role.name')->label('الدور')->placeholder('—'),
                IconColumn::make('is_active')->label('فعال')->boolean(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->defaultSort('user_id');
    }
}
