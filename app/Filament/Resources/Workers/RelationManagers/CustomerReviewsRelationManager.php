<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Cleaning\Models\CleaningBooking;

final class CustomerReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'cleaningBookings';

    protected static ?string $title = 'تقييمات العملاء';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking_number')->label('رقم الحجز'),
                TextColumn::make('scheduled_date')->label('التاريخ')->date(),
                TextColumn::make('customer.name')->label('العميل'),
                TextColumn::make('review_rating')
                    ->label('التقييم')
                    ->getStateUsing(function (CleaningBooking $record) {
                        $review = $record->reviews()->first();

                        return $review?->rating;
                    }),
                TextColumn::make('review_comment')
                    ->label('التعليق')
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
