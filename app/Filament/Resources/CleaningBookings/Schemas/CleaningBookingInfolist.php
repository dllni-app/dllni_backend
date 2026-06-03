<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBookingRoomAssignmentSource;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
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
                        TextEntry::make('worker.first_name')->label(__('cleaning_admin.booking.fields.primary_worker'))->placeholder('-'),
                        TextEntry::make('preferredWorker.first_name')->label(__('cleaning_admin.booking.fields.preferred_worker'))->placeholder('-'),
                    ])
                    ->columns(2),
                Section::make(__('cleaning_admin.booking.sections.team'))
                    ->schema([
                        TextEntry::make('assignment_mode')
                            ->label(__('cleaning_admin.booking.fields.assignment_mode'))
                            ->badge()
                            ->color(fn ($state, $record): string => self::assignmentModeColor($record))
                            ->state(fn ($record): string => self::assignmentModeLabel($record)),
                        TextEntry::make('worker_acceptance')
                            ->label(__('cleaning_admin.booking.fields.worker_acceptance'))
                            ->state(fn ($record): string => self::workerAcceptanceLabel($record)),
                        TextEntry::make('remaining_workers')
                            ->label(__('cleaning_admin.booking.fields.remaining_workers'))
                            ->state(fn ($record): string => (string) $record->remainingWorkerCount()),
                        TextEntry::make('room_coverage')
                            ->label(__('cleaning_admin.booking.fields.room_coverage'))
                            ->state(fn ($record): string => self::roomCoverageLabel($record)),
                    ])
                    ->columns(2),
                Section::make(__('cleaning_admin.booking.sections.accepted_workers'))
                    ->schema([
                        RepeatableEntry::make('acceptedWorkerAssignments')
                            ->label('')
                            ->schema([
                                TextEntry::make('worker.first_name')
                                    ->label(__('cleaning_admin.booking.fields.accepted_worker'))
                                    ->placeholder('-'),
                                TextEntry::make('status')
                                    ->label(__('cleaning_admin.booking.fields.status'))
                                    ->badge()
                                    ->formatStateUsing(fn ($state): string => self::workerAssignmentStatusLabel($state))
                                    ->color(fn ($state): string => self::workerAssignmentStatusColor($state)),
                                TextEntry::make('accepted_at')
                                    ->label(__('cleaning_admin.booking.fields.accepted_at'))
                                    ->dateTime('Y-m-d H:i')
                                    ->placeholder('-'),
                                TextEntry::make('room_count')
                                    ->label(__('cleaning_admin.booking.fields.room_count'))
                                    ->placeholder('-'),
                                TextEntry::make('rooms_weight')
                                    ->label(__('cleaning_admin.booking.fields.rooms_weight'))
                                    ->placeholder('-'),
                                TextEntry::make('service_share_amount')
                                    ->label(__('cleaning_admin.booking.fields.service_share_amount'))
                                    ->money(config('app.currency', 'SYP')),
                                TextEntry::make('travel_fee')
                                    ->label(__('cleaning_admin.booking.fields.travel_fee'))
                                    ->money(config('app.currency', 'SYP')),
                                TextEntry::make('admin_margin_amount')
                                    ->label(__('cleaning_admin.booking.fields.admin_margin_amount'))
                                    ->money(config('app.currency', 'SYP')),
                                TextEntry::make('worker_amount')
                                    ->label(__('cleaning_admin.booking.fields.worker_payout'))
                                    ->money(config('app.currency', 'SYP')),
                            ])
                            ->columns(4),
                    ])
                    ->visible(fn ($record): bool => $record->acceptedWorkerAssignments()->exists()),
                Section::make(__('cleaning_admin.booking.sections.room_assignments'))
                    ->schema([
                        RepeatableEntry::make('rooms')
                            ->label('')
                            ->schema([
                                TextEntry::make('display_label')
                                    ->label(__('cleaning_admin.booking.fields.room_label'))
                                    ->placeholder('-'),
                                TextEntry::make('room_type')
                                    ->label(__('cleaning_admin.booking.fields.room_type'))
                                    ->formatStateUsing(fn (?string $state): string => self::roomTypeLabel($state))
                                    ->placeholder('-'),
                                TextEntry::make('room_size')
                                    ->label(__('cleaning_admin.booking.fields.room_size'))
                                    ->formatStateUsing(fn (?string $state): string => self::roomSizeLabel($state))
                                    ->placeholder('-'),
                                TextEntry::make('weight')
                                    ->label(__('cleaning_admin.booking.fields.room_weight'))
                                    ->placeholder('-'),
                                TextEntry::make('assignedWorker.first_name')
                                    ->label(__('cleaning_admin.booking.fields.assigned_worker'))
                                    ->placeholder('-'),
                                TextEntry::make('assignment_source')
                                    ->label(__('cleaning_admin.booking.fields.assignment_source'))
                                    ->badge()
                                    ->formatStateUsing(fn ($state): string => self::roomAssignmentSourceLabel($state)),
                            ])
                            ->columns(3),
                    ])
                    ->visible(fn ($record): bool => $record->rooms()->exists()),
                Section::make(__('cleaning_admin.booking.sections.payout_breakdown'))
                    ->schema([
                        TextEntry::make('worker_share_total')
                            ->label(__('cleaning_admin.booking.fields.worker_share_total'))
                            ->state(fn ($record): string => self::money(self::acceptedAssignmentsTotal($record, 'service_share_amount'))),
                        TextEntry::make('worker_travel_total')
                            ->label(__('cleaning_admin.booking.fields.worker_travel_total'))
                            ->state(fn ($record): string => self::money(self::acceptedAssignmentsTotal($record, 'travel_fee'))),
                        TextEntry::make('worker_admin_total')
                            ->label(__('cleaning_admin.booking.fields.worker_admin_total'))
                            ->state(fn ($record): string => self::money(self::acceptedAssignmentsTotal($record, 'admin_margin_amount'))),
                        TextEntry::make('worker_amount_total')
                            ->label(__('cleaning_admin.booking.fields.worker_payout'))
                            ->state(fn ($record): string => self::money(self::acceptedAssignmentsTotal($record, 'worker_amount'))),
                    ])
                    ->columns(2),
                Section::make(__('cleaning_admin.booking.sections.disputes'))
                    ->schema([
                        TextEntry::make('disputes_count')->counts('disputes')->label(__('cleaning_admin.booking.fields.disputes_count')),
                    ])
                    ->visible(fn ($record) => $record->disputes()->count() > 0),
            ]);
    }

    private static function assignmentModeLabel(mixed $record): string
    {
        $mode = $record->resolvedAssignmentMode();

        return self::translatedValue('cleaning_admin.booking.enums.assignment_mode.', $mode);
    }

    private static function assignmentModeColor(mixed $record): string
    {
        return match ($record->resolvedAssignmentMode()) {
            CleaningAssignmentMode::PreferredWorker->value => 'info',
            CleaningAssignmentMode::OpenCount->value => 'primary',
            default => 'gray',
        };
    }

    private static function workerAcceptanceLabel(mixed $record): string
    {
        return sprintf('%d / %d', $record->acceptedWorkerCount(), max(1, (int) ($record->number_of_workers ?? 1)));
    }

    private static function roomCoverageLabel(mixed $record): string
    {
        $totalRooms = max(0, (int) ($record->rooms()->count() ?? 0));
        if ($totalRooms <= 0) {
            return '-';
        }

        $assignedRooms = max(0, (int) ($record->rooms()->whereNotNull('assigned_worker_id')->count() ?? 0));
        $percent = (int) round(($assignedRooms / max(1, $totalRooms)) * 100);

        return sprintf('%d/%d (%d%%)', $assignedRooms, $totalRooms, $percent);
    }

    private static function roomTypeLabel(?string $state): string
    {
        return self::translatedValue('cleaning_admin.booking.enums.room_type.', $state);
    }

    private static function roomSizeLabel(?string $state): string
    {
        return self::translatedValue('cleaning_admin.booking.enums.room_size.', $state);
    }

    private static function roomAssignmentSourceLabel(mixed $state): string
    {
        $value = $state?->value ?? $state;
        if (! is_string($value) || $value === '') {
            return '-';
        }

        return self::translatedValue('cleaning_admin.booking.enums.room_assignment_source.', $value);
    }

    private static function workerAssignmentStatusLabel(mixed $state): string
    {
        $value = $state?->value ?? $state;
        if (! is_string($value) || $value === '') {
            return '-';
        }

        return self::translatedValue('cleaning_admin.booking.enums.worker_assignment_status.', $value);
    }

    private static function workerAssignmentStatusColor(mixed $state): string
    {
        $value = $state?->value ?? $state;

        return match ($value) {
            CleaningBookingWorkerAssignmentStatus::Accepted->value => 'success',
            CleaningBookingWorkerAssignmentStatus::Withdrawn->value => 'warning',
            CleaningBookingWorkerAssignmentStatus::Rejected->value => 'danger',
            default => 'gray',
        };
    }

    private static function acceptedAssignmentsTotal(mixed $record, string $field): float
    {
        $assignments = $record->workerAssignments()
            ->where('status', CleaningBookingWorkerAssignmentStatus::Accepted->value)
            ->get();

        return round((float) $assignments->sum($field), 2);
    }

    private static function money(mixed $amount): string
    {
        return number_format((float) $amount, 2).' '.config('app.currency', 'SYP');
    }

    private static function translatedValue(string $prefix, ?string $value): string
    {
        if (! $value) {
            return '-';
        }

        $key = $prefix.$value;

        return __($key) === $key ? $value : __($key);
    }
}
