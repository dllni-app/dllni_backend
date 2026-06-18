<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class CustomerRatingsRelationManager extends RelationManager
{
    protected static string $relationship = 'customerRatings';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('cleaning_admin.workers.customer_ratings');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('rating')->label(__('cleaning_admin.workers.customer_rating_fields.rating')),
                TextColumn::make('rating_type')->label(__('cleaning_admin.workers.customer_rating_fields.type'))->badge(),
                TextColumn::make('created_at')->label(__('cleaning_admin.workers.customer_rating_fields.date'))->dateTime('Y-m-d H:i'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
