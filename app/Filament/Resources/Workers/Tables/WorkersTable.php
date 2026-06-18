<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Tables;

use App\Enums\WorkerPreferredWorkType;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

final class WorkersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label(self::headerLabel(
                        __('cleaning_admin.workers.fields.id'),
                        __('cleaning_admin.column_descriptions.id'),
                    ))
                    ->sortable(),
                TextColumn::make('first_name')
                    ->label(__('cleaning_admin.workers.fields.first_name'))
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label(__('cleaning_admin.workers.fields.user_name'))
                    ->searchable(),
                TextColumn::make('birthday')
                    ->label(__('cleaning_admin.workers.fields.birthday'))
                    ->date('Y-m-d')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('gender')
                    ->label(__('cleaning_admin.workers.fields.gender'))
                    ->formatStateUsing(fn ($state): string => match ($state) {
                        'male' => __('cleaning_admin.workers.gender_options.male'),
                        'female' => __('cleaning_admin.workers.gender_options.female'),
                        default => (string) ($state ?? '-'),
                    })
                    ->searchable(),
                TextColumn::make('preferred_work_type')
                    ->label(__('cleaning_admin.workers.fields.preferred_work_type'))
                    ->formatStateUsing(function ($state): string {
                        $value = $state instanceof WorkerPreferredWorkType
                            ? $state->value
                            : (string) ($state ?? WorkerPreferredWorkType::Both->value);

                        return WorkerPreferredWorkType::options()[$value] ?? $value;
                    })
                    ->badge(),
                TextColumn::make('user.phone')
                    ->label(self::headerLabel(
                        __('cleaning_admin.workers.fields.phone'),
                        __('cleaning_admin.column_descriptions.phone'),
                    )),
                TextColumn::make('bio')
                    ->label(__('cleaning_admin.workers.fields.bio'))
                    ->limit(35)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('home_address')
                    ->label(__('cleaning_admin.workers.fields.home_address'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('home_latitude')
                    ->label(__('cleaning_admin.workers.fields.home_latitude'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('home_longitude')
                    ->label(__('cleaning_admin.workers.fields.home_longitude'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('trust_score')
                    ->label(self::headerLabel(
                        __('cleaning_admin.workers.fields.trust_score'),
                        __('cleaning_admin.column_descriptions.trust_score'),
                    ))
                    ->sortable(),
                TextColumn::make('average_rating')
                    ->label(self::headerLabel(
                        __('cleaning_admin.workers.fields.average_rating'),
                        __('cleaning_admin.column_descriptions.average_rating'),
                    ))
                    ->sortable(),
                TextColumn::make('total_completed_jobs')
                    ->label(self::headerLabel(
                        __('cleaning_admin.workers.fields.total_completed_jobs'),
                        __('cleaning_admin.column_descriptions.total_completed_jobs'),
                    ))
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('cleaning_admin.workers.fields.is_active'))
                    ->tooltip(__('cleaning_admin.column_descriptions.is_active'))
                    ->boolean(),
                IconColumn::make('is_suspended')
                    ->label(__('cleaning_admin.workers.fields.suspended'))
                    ->tooltip(__('cleaning_admin.column_descriptions.is_suspended'))
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('cleaning_admin.workers.fields.is_active')),
                TernaryFilter::make('is_suspended')->label(__('cleaning_admin.workers.fields.suspended')),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('cleaning_admin.workers.view'))
                    ->modal(),
                EditAction::make()
                    ->label(__('cleaning_admin.workers.edit'))
                    ->modal(),
            ]);
    }

    private static function headerLabel(string $label, string $description): HtmlString
    {
        return new HtmlString(
            '<span style="display:flex;flex-direction:column;line-height:1.2;">'
                . '<span style="display:block;font-weight:600;color:inherit;">' . e($label) . '</span>'
                . '<span style="display:block;margin-top:2px;font-size:11px;font-weight:400;color:#9ca3af;">' . e($description) . '</span>'
                . '</span>',
        );
    }
}
