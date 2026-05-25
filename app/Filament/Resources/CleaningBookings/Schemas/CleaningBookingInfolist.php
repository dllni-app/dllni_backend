<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class CleaningBookingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $yesNo = fn ($state) => $state ? __('cleaning_admin.boolean.yes') : __('cleaning_admin.boolean.no');

        return $schema
            ->components([
                Section::make(__('cleaning_admin.booking.sections.main'))
                    ->schema([
                        TextEntry::make('booking_number')->label(__('cleaning_admin.booking.fields.booking_number')),
                        TextEntry::make('status')->label(__('cleaning_admin.booking.fields.status'))->badge()->formatStateUsing(fn ($state) => $state?->label()),
                        TextEntry::make('terms_accepted')->label(__('cleaning_admin.booking.fields.terms_accepted'))->formatStateUsing($yesNo)->placeholder('-'),
                        TextEntry::make('cancelled_at')->label(__('cleaning_admin.booking.fields.cancelled_at'))->dateTime('Y-m-d H:i')->placeholder('-'),
                        TextEntry::make('cancellationPolicy.name')->label(__('cleaning_admin.booking.fields.cancellation_policy'))->placeholder('-'),
                        TextEntry::make('property_type')->label(__('cleaning_admin.booking.fields.property_type')),
                        TextEntry::make('number_of_workers')->label(__('cleaning_admin.booking.fields.number_of_workers')),
                        TextEntry::make('estimated_sqm')->label(__('cleaning_admin.booking.fields.estimated_sqm')),
                        TextEntry::make('estimated_hours')->label(__('cleaning_admin.booking.fields.estimated_hours')),
                        TextEntry::make('scheduled_date')->label(__('cleaning_admin.booking.fields.scheduled_date'))->date(),
                        TextEntry::make('scheduled_time')->label(__('cleaning_admin.booking.fields.scheduled_time')),
                    ])
                    ->columns(2),
                Section::make('Event assistance details')
                    ->schema([
                        TextEntry::make('property_details.event_type')->label('Event type')->placeholder('-'),
                        TextEntry::make('property_details.guest_count')->label('Guest count')->placeholder('-'),
                        TextEntry::make('property_details.venue_type')->label('Venue type')->placeholder('-'),
                        TextEntry::make('property_details.special_requirement')->label('Special requirement')->placeholder('-'),
                        TextEntry::make('property_details.notes')->label('Notes')->placeholder('-'),
                        TextEntry::make('event_services')
                            ->label('Selected services')
                            ->state(fn ($record): string => $record->services()->pluck('name')->implode(', '))
                            ->placeholder('-'),
                    ])
                    ->columns(2)
                    ->visible(fn ($record): bool => $record->property_type === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE),
                Section::make(__('cleaning_admin.booking.sections.pricing'))
                    ->schema([
                        TextEntry::make('base_price')->label(__('cleaning_admin.booking.fields.base_price'))->money(config('app.currency', 'SYP')),
                        TextEntry::make('addons_total')->label(__('cleaning_admin.booking.fields.addons_total'))->money(config('app.currency', 'SYP')),
                        TextEntry::make('travel_fee')->label(__('cleaning_admin.booking.fields.travel_fee'))->money(config('app.currency', 'SYP')),
                        TextEntry::make('travel_distance_km')->label('Travel distance (km)')->placeholder('-'),
                        TextEntry::make('admin_margin_amount')->label('Admin margin')->money(config('app.currency', 'SYP')),
                        TextEntry::make('is_pricing_final')->label('Pricing finalized')->formatStateUsing($yesNo),
                        TextEntry::make('total_price')->label(__('cleaning_admin.booking.fields.total_price'))->money(config('app.currency', 'SYP')),
                    ])
                    ->columns(2),
                Section::make(__('cleaning_admin.booking.sections.execution_times'))
                    ->schema([
                        TextEntry::make('work_started_at')->label(__('cleaning_admin.booking.fields.work_started_at'))->dateTime('Y-m-d H:i')->placeholder('-'),
                        TextEntry::make('work_finished_at')->label(__('cleaning_admin.booking.fields.work_finished_at'))->dateTime('Y-m-d H:i')->placeholder('-'),
                        TextEntry::make('customer_confirmed_at')->label(__('cleaning_admin.booking.fields.customer_confirmed_at'))->dateTime('Y-m-d H:i')->placeholder('-'),
                    ])
                    ->columns(3),
                Section::make(__('cleaning_admin.booking.sections.parties'))
                    ->schema([
                        TextEntry::make('customer.name')->label(__('cleaning_admin.booking.fields.customer')),
                        TextEntry::make('worker.first_name')->label(__('cleaning_admin.booking.fields.worker'))->placeholder('-'),
                    ])
                    ->columns(2),
                Section::make(__('cleaning_admin.booking.sections.disputes'))
                    ->schema([
                        TextEntry::make('disputes_count')->counts('disputes')->label(__('cleaning_admin.booking.fields.disputes_count')),
                    ])
                    ->visible(fn ($record) => $record->disputes()->count() > 0),
            ]);
    }
}
