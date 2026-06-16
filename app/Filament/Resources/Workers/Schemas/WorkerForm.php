<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Schemas;

use App\Enums\WorkerPreferredWorkType;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class WorkerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('cleaning_admin.workers.sections.profile'))
                    ->columns(2)
                    ->schema([
                        Select::make('user_id')
                            ->label(__('cleaning_admin.workers.fields.user'))
                            ->relationship('user', 'name')
                            ->searchable()
                            ->required(),
                        TextInput::make('first_name')
                            ->label(__('cleaning_admin.workers.fields.first_name'))
                            ->required(),
                        Select::make('gender')
                            ->label(__('cleaning_admin.workers.fields.gender'))
                            ->options([
                                'male' => __('cleaning_admin.workers.gender_options.male'),
                                'female' => __('cleaning_admin.workers.gender_options.female'),
                            ])
                            ->nullable(),
                        Select::make('preferred_work_type')
                            ->label(__('cleaning_admin.workers.fields.preferred_work_type'))
                            ->options(WorkerPreferredWorkType::options())
                            ->default(WorkerPreferredWorkType::Both->value)
                            ->required(),
                        TextInput::make('bio')
                            ->label(__('cleaning_admin.workers.fields.bio')),
                        Toggle::make('is_active')
                            ->label(__('cleaning_admin.workers.fields.is_active'))
                            ->default(true)
                            ->live(),
                        TextInput::make('trust_score')->numeric()->default(100)->hidden(),
                        TextInput::make('average_rating')->numeric()->default(0)->hidden(),
                        Toggle::make('is_suspended')->default(false)->hidden(),
                        Toggle::make('is_verified')->default(true)->hidden(),
                    ]),
            ]);
    }
}
