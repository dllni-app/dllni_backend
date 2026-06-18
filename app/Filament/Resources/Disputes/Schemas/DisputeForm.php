<?php

declare(strict_types=1);

namespace App\Filament\Resources\Disputes\Schemas;

use App\Enums\DisputeCategory;
use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class DisputeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('ticket_number')->label(__('cleaning_admin.disputes.fields.ticket_number'))->required(),
                TextInput::make('booking_id')->label(__('cleaning_admin.disputes.fields.booking_number'))->numeric()->required(),
                TextInput::make('booking_type')->label(__('cleaning_admin.disputes.fields.booking_type'))->required(),
                Textarea::make('description')->label(__('cleaning_admin.disputes.fields.description'))->rows(4),
                Select::make('category')
                    ->label(__('cleaning_admin.disputes.fields.category'))
                    ->options(collect(DisputeCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all())
                    ->required(),
                Select::make('status')
                    ->label(__('cleaning_admin.disputes.fields.status'))
                    ->options(collect(DisputeStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all())
                    ->required(),
                Select::make('resolution')
                    ->label(__('cleaning_admin.disputes.fields.resolution'))
                    ->options(collect(DisputeResolution::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
                Toggle::make('worker_earnings_frozen')
                    ->label(__('cleaning_admin.disputes.fields.worker_earnings_frozen'))
                    ->default(true),
            ]);
    }
}
