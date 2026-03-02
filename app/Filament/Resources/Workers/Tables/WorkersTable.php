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
                TextColumn::make('id')
                    ->label(__('cleaning_admin.workers.fields.id'))
                    ->description(__('cleaning_admin.column_descriptions.id'))
                    ->sortable(),
                TextColumn::make('first_name')
                    ->label(__('cleaning_admin.workers.fields.name'))
                    ->description(__('cleaning_admin.column_descriptions.first_name'))
                    ->searchable(),
                TextColumn::make('user.phone')
                    ->label(__('cleaning_admin.workers.fields.phone'))
                    ->description(__('cleaning_admin.column_descriptions.phone')),
                TextColumn::make('trust_score')
                    ->label(__('cleaning_admin.workers.fields.trust_score'))
                    ->description(__('cleaning_admin.column_descriptions.trust_score'))
                    ->sortable(),
                TextColumn::make('average_rating')
                    ->label(__('cleaning_admin.workers.fields.average_rating'))
                    ->description(__('cleaning_admin.column_descriptions.average_rating'))
                    ->sortable(),
                TextColumn::make('total_completed_jobs')
                    ->label(__('cleaning_admin.workers.fields.total_completed_jobs'))
                    ->description(__('cleaning_admin.column_descriptions.total_completed_jobs'))
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('cleaning_admin.workers.fields.is_active'))
                    ->description(__('cleaning_admin.column_descriptions.is_active'))
                    ->boolean(),
                IconColumn::make('is_suspended')
                    ->label(__('cleaning_admin.workers.fields.suspended'))
                    ->description(__('cleaning_admin.column_descriptions.is_suspended'))
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('cleaning_admin.workers.fields.is_active')),
                TernaryFilter::make('is_suspended')->label(__('cleaning_admin.workers.fields.suspended')),
            ])
            ->recordActions([
                ViewAction::make()->label(__('cleaning_admin.workers.view')),
                EditAction::make()->label(__('cleaning_admin.workers.edit')),
            ]);
    }
}
