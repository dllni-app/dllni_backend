<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\Restaurants\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'reviews';

    protected static ?string $title = 'تقييمات العملاء';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order.order_number')->label('رقم الطلب'),
                TextColumn::make('user.name')->label('العميل'),
                TextColumn::make('rating')->label('التقييم')->badge(),
                TextColumn::make('comment')->label('التعليق')->limit(80),
                TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
