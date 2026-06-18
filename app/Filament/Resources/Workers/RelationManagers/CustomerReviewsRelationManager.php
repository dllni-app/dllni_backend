<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\CleaningBooking;

final class CustomerReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'cleaningBookings';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('cleaning_admin.workers.reviews');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking_number')->label(__('cleaning_admin.workers.reviews_fields.booking_number')),
                TextColumn::make('scheduled_date')->label(__('cleaning_admin.workers.reviews_fields.date'))->date(),
                TextColumn::make('customer.name')->label(__('cleaning_admin.booking.fields.customer')),
                TextColumn::make('review_rating')
                    ->label(__('cleaning_admin.workers.reviews_fields.rating'))
                    ->getStateUsing(function (CleaningBooking $record) {
                        $review = $record->reviews()->first();

                        return $review?->rating;
                    }),
                TextColumn::make('review_comment')
                    ->label(__('cleaning_admin.workers.reviews_fields.comment'))
                    ->limit(40)
                    ->getStateUsing(function (CleaningBooking $record) {
                        $review = $record->reviews()->first();

                        return $review?->comment;
                    }),
            ])
            ->defaultSort('scheduled_date', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('reviews'));
    }
}
