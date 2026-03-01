<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class CustomerRatingsRelationManager extends RelationManager
{
    protected static string $relationship = 'customerRatings';

    protected static ?string $title = 'تقييمات العامل للعملاء';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('rating')->label('التقييم'),
                TextColumn::make('rating_type')->label('النوع')->badge(),
                TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
