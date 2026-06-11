<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Schemas;

use App\Enums\WorkerPreferredWorkType;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class WorkerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $yesNo = fn($state) => $state ? __('cleaning_admin.boolean.yes') : __('cleaning_admin.boolean.no');

        return $schema
            ->components([
                Section::make(__('cleaning_admin.workers.sections.profile'))
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                ImageEntry::make('photo')
                                    ->label('')
                                    ->getStateUsing(fn($record) => $record->getFirstMediaUrl('avatar') ?: null)
                                    ->defaultImageUrl(fn() => 'https://ui-avatars.com/api/?name=W&background=random'),
                                Group::make()
                                    ->schema([
                                        TextEntry::make('first_name')->label(__('cleaning_admin.workers.fields.name')),
                                        TextEntry::make('gender')->label(__('cleaning_admin.workers.fields.gender')),
                                        TextEntry::make('preferred_work_type')
                                            ->label(__('cleaning_admin.workers.fields.preferred_work_type'))
                                            ->formatStateUsing(function ($state): string {
                                                $value = $state instanceof WorkerPreferredWorkType
                                                    ? $state->value
                                                    : (string) ($state ?? WorkerPreferredWorkType::Both->value);

                                                return WorkerPreferredWorkType::options()[$value] ?? $value;
                                            }),
                                        TextEntry::make('user.phone')->label(__('cleaning_admin.workers.fields.phone')),
                                        TextEntry::make('home_address')->label(__('cleaning_admin.workers.fields.home_address'))->placeholder('-'),
                                        TextEntry::make('home_latitude')->label(__('cleaning_admin.workers.fields.home_latitude'))->placeholder('-'),
                                        TextEntry::make('home_longitude')->label(__('cleaning_admin.workers.fields.home_longitude'))->placeholder('-'),
                                        TextEntry::make('average_rating')->label(__('cleaning_admin.workers.fields.average_rating'))->suffix(' / 5'),
                                        TextEntry::make('total_completed_jobs')->label(__('cleaning_admin.workers.fields.total_completed_jobs')),
                                        Group::make()
                                            ->schema([
                                                TextEntry::make('is_verified')->label(__('cleaning_admin.workers.fields.is_verified'))->formatStateUsing($yesNo),
                                                TextEntry::make('is_featured')->label(__('cleaning_admin.workers.fields.is_featured'))->formatStateUsing($yesNo),
                                            ])
                                            ->columns(2),
                                    ]),
                            ]),
                    ]),
                Section::make(__('cleaning_admin.workers.sections.trust_card'))
                    ->schema([
                        TextEntry::make('trust_score')
                            ->label(__('cleaning_admin.workers.fields.trust_score'))
                            ->suffix(' / 100')
                            ->weight('bold'),
                        RepeatableEntry::make('trustLogs')
                            ->label(__('cleaning_admin.workers.fields.trust_log'))
                            ->schema([
                                TextEntry::make('reason')->label(__('cleaning_admin.workers.fields.reason')),
                                TextEntry::make('score_delta')->label(__('cleaning_admin.workers.fields.score_delta'))->suffix(' نقطة'),
                                TextEntry::make('created_at')->label(__('cleaning_admin.workers.fields.date'))->dateTime('Y-m-d H:i'),
                            ])
                            ->columns(3),
                    ]),
                Section::make(__('cleaning_admin.workers.sections.performance'))
                    ->schema([
                        Grid::make(5)
                            ->schema([
                                TextEntry::make('total_completed_jobs')->label(__('cleaning_admin.workers.fields.total_completed_jobs')),
                                TextEntry::make('acceptance_rate')->label(__('cleaning_admin.workers.fields.acceptance_rate'))->suffix('%'),
                                TextEntry::make('cancellation_rate')->label(__('cleaning_admin.workers.fields.cancellation_rate'))->suffix('%'),
                                TextEntry::make('average_rating')->label(__('cleaning_admin.workers.fields.average_rating')),
                                TextEntry::make('open_disputes_count')->label(__('cleaning_admin.workers.fields.open_disputes_count')),
                            ]),
                    ]),
                Section::make(__('cleaning_admin.workers.sections.preferred_zones'))
                    ->schema([
                        RepeatableEntry::make('zones')
                            ->label('')
                            ->schema([
                                TextEntry::make('name')->label(__('cleaning_admin.workers.fields.zone')),
                                TextEntry::make('is_active')->label(__('cleaning_admin.workers.fields.is_active'))->formatStateUsing($yesNo),
                            ])
                            ->columns(2),
                    ]),
            ]);
    }
}
