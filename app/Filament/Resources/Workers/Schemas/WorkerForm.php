<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Schemas;

use App\Enums\WorkerPreferredWorkType;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
                            ->visible(fn (string $operation): bool => $operation === 'create')
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
                        DatePicker::make('birthday')
                            ->label(__('cleaning_admin.workers.fields.birthday'))
                            ->native(false),
                        Textarea::make('bio')
                            ->label(__('cleaning_admin.workers.fields.bio')),
                        Toggle::make('is_active')
                            ->label(__('cleaning_admin.workers.fields.is_active'))
                            ->default(true)
                            ->live(),
                    ]),
                Section::make(__('cleaning_admin.workers.sections.account'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('user_phone')
                            ->label(__('cleaning_admin.workers.fields.phone'))
                            ->tel(),
                        TextInput::make('user_password')
                            ->label(__('cleaning_admin.workers.fields.password'))
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->dehydrateStateUsing(fn (?string $state): ?string => blank($state) ? null : $state)
                            ->dehydrated(fn (?string $state): bool => filled($state)),
                    ]),
            ]);
    }
}
