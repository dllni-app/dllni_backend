<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class WorkersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('الرقم')->sortable(),
                TextColumn::make('first_name')->label('الاسم')->searchable(),
                TextColumn::make('user.phone')->label('الهاتف'),
                TextColumn::make('trust_score')->label('نقاط الثقة')->sortable(),
                TextColumn::make('average_rating')->label('متوسط التقييم')->sortable(),
                TextColumn::make('total_completed_jobs')->label('إجمالي المهام المنجزة')->sortable(),
                IconColumn::make('is_active')->label('نشط')->boolean(),
                IconColumn::make('is_suspended')->label('معلق')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('نشط'),
                TernaryFilter::make('is_suspended')->label('معلق'),
            ])
            ->recordActions([
                ViewAction::make()->label('عرض'),
                EditAction::make()->label('تعديل'),
            ]);
    }
}
