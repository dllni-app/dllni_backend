<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Enums\CleaningBookingStatus;

final class CleaningBookingForm
{
    public static function configure(Schema $schema): Schema
    {
        $statusOptions = collect(CleaningBookingStatus::cases())
            ->mapWithKeys(fn (CleaningBookingStatus $status): array => [$status->value => $status->label()])
            ->all();

        return $schema
            ->components([
                TextInput::make('booking_number')
                    ->label(__('cleaning_admin.booking.fields.booking_number'))
                    ->disabled()
                    ->dehydrated(false),
                Select::make('status')
                    ->label(__('cleaning_admin.booking.fields.status'))
                    ->options($statusOptions)
                    ->disabled()
                    ->dehydrated(false),
                Select::make('worker_id')
                    ->label(__('cleaning_admin.booking.fields.worker'))
                    ->relationship(
                        name: 'worker',
                        titleAttribute: 'first_name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true),
                    )
                    ->searchable()
                    ->preload(),
                Select::make('preferred_worker_id')
                    ->label(__('cleaning_admin.booking.fields.preferred_worker'))
                    ->relationship(
                        name: 'preferredWorker',
                        titleAttribute: 'first_name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true),
                    )
                    ->searchable()
                    ->preload(),
                TextInput::make('number_of_workers')
                    ->label(__('cleaning_admin.booking.fields.number_of_workers'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(20)
                    ->default(1)
                    ->required(),
                DatePicker::make('scheduled_date')
                    ->label(__('cleaning_admin.booking.fields.scheduled_date'))
                    ->required(),
                TimePicker::make('scheduled_time')
                    ->label(__('cleaning_admin.booking.fields.scheduled_time'))
                    ->seconds(false)
                    ->required(),
                Select::make('billing_policy_id')
                    ->label(__('cleaning_admin.booking.fields.billing_policy'))
                    ->relationship('billingPolicy', 'name')
                    ->searchable()
                    ->preload(),
                Select::make('cancellation_policy_id')
                    ->label(__('cleaning_admin.booking.fields.cancellation_policy'))
                    ->relationship('cancellationPolicy', 'name')
                    ->searchable()
                    ->preload(),
                Toggle::make('terms_accepted')
                    ->label(__('cleaning_admin.booking.fields.terms_accepted')),
                TextInput::make('estimated_sqm')
                    ->label(__('cleaning_admin.booking.fields.estimated_sqm'))
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('estimated_hours')
                    ->label(__('cleaning_admin.booking.fields.estimated_hours'))
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('total_hours')
                    ->label(__('cleaning_admin.booking.fields.total_hours'))
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('base_price')
                    ->label(__('cleaning_admin.booking.fields.base_price'))
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('addons_total')
                    ->label(__('cleaning_admin.booking.fields.addons_total'))
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('travel_fee')
                    ->label(__('cleaning_admin.booking.fields.travel_fee'))
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('travel_distance_km')
                    ->label(__('cleaning_admin.booking.fields.travel_distance_km'))
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('admin_margin_amount')
                    ->label(__('cleaning_admin.booking.fields.admin_margin_amount'))
                    ->disabled()
                    ->dehydrated(false),
                Toggle::make('is_pricing_final')
                    ->label(__('cleaning_admin.booking.fields.is_pricing_final'))
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('total_price')
                    ->label(__('cleaning_admin.booking.fields.total_price'))
                    ->disabled()
                    ->dehydrated(false),
            ]);
    }
}
