<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Schemas;

use Carbon\Carbon;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Models\CleaningWorkerLocationHistory;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class CleaningBookingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(12)
                    ->schema([
                        Group::make()
                            ->schema([
                                Section::make('الحجز')
                                    ->schema([
                                        TextEntry::make('booking_number')->label('رقم الحجز'),
                                        TextEntry::make('status')
                                            ->label('الحالة')
                                            ->badge()
                                            ->formatStateUsing(fn ($state): string => $state?->label() ?? '-'),
                                        TextEntry::make('booking_kind')
                                            ->label('نوع الحجز')
                                            ->state(fn ($record): string => self::bookingKindLabel($record))
                                            ->badge()
                                            ->color(fn ($record): string => self::bookingKindColor($record)),
                                        TextEntry::make('cancelled_at')
                                            ->label('وقت الإلغاء')
                                            ->formatStateUsing(fn ($state): string => self::dateTime($state))
                                            ->placeholder('-')
                                            ->visible(fn ($record): bool => filled($record->cancelled_at)),
                                        TextEntry::make('cancelled_by_role')
                                            ->label('مصدر الإلغاء')
                                            ->badge()
                                            ->formatStateUsing(fn ($state): string => self::cancellationSourceLabel($state))
                                            ->color(fn ($state): string => self::cancellationSourceColor($state))
                                            ->placeholder('-')
                                            ->visible(fn ($record): bool => filled($record->cancelled_by_role)),
                                        TextEntry::make('property_type')
                                            ->label('نوع العقار')
                                            ->formatStateUsing(fn (?string $state): string => self::propertyTypeLabel($state))
                                            ->visible(fn ($record): bool => ! self::isEventAssistance($record)),
                                        TextEntry::make('number_of_workers')
                                            ->label('عدد العاملين')
                                            ->formatStateUsing(fn ($state): string => self::integer($state)),
                                        TextEntry::make('estimated_sqm')
                                            ->label('المساحة التقديرية')
                                            ->formatStateUsing(fn ($state): string => self::integer($state))
                                            ->visible(fn ($record): bool => ! self::isEventAssistance($record)),
                                        TextEntry::make('estimated_hours')
                                            ->label('الساعات التقديرية')
                                            ->formatStateUsing(fn ($state): string => self::integer($state)),
                                        TextEntry::make('scheduled_date')
                                            ->label('التاريخ')
                                            ->formatStateUsing(fn ($state): string => self::date($state)),
                                        TextEntry::make('scheduled_time')
                                            ->label('الوقت')
                                            ->formatStateUsing(fn ($state): string => self::time($state)),
                                    ])
                                    ->columns(2),
                                Section::make('تفاصيل المناسبة')
                                    ->schema([
                                        TextEntry::make('property_details.event_type')
                                            ->label('نوع المناسبة')
                                            ->formatStateUsing(fn (?string $state): string => self::eventTypeLabel($state))
                                            ->placeholder('-'),
                                        TextEntry::make('property_details.guest_count')
                                            ->label('عدد الضيوف')
                                            ->formatStateUsing(fn ($state): string => self::integer($state))
                                            ->placeholder('-'),
                                        TextEntry::make('property_details.venue_type')
                                            ->label('نوع المكان')
                                            ->formatStateUsing(fn (?string $state): string => self::propertyTypeLabel($state))
                                            ->placeholder('-'),
                                        TextEntry::make('property_details.custom_service')->label('الخدمة المخصصة')->placeholder('-'),
                                        TextEntry::make('property_details.hours')
                                            ->label('عدد الساعات')
                                            ->formatStateUsing(fn ($state): string => self::integer($state))
                                            ->placeholder('-'),
                                        TextEntry::make('property_details.special_requirement')->label('متطلب خاص')->placeholder('-'),
                                        TextEntry::make('property_details.notes')->label('ملاحظات')->placeholder('-'),
                                    ])
                                    ->columns(2)
                                    ->visible(fn ($record): bool => self::isEventAssistance($record)),
                                Section::make('أوقات التنفيذ')
                                    ->schema([
                                        TextEntry::make('work_started_at')->label('بدأ العمل')->formatStateUsing(fn ($state): string => self::dateTime($state))->placeholder('-'),
                                        TextEntry::make('work_finished_at')->label('انتهى العمل')->formatStateUsing(fn ($state): string => self::dateTime($state))->placeholder('-'),
                                        TextEntry::make('customer_confirmed_at')->label('تأكيد العميل')->formatStateUsing(fn ($state): string => self::dateTime($state))->placeholder('-'),
                                    ])
                                    ->columns(3),
                                Section::make('الفريق')
                                    ->schema([
                                        TextEntry::make('worker_acceptance')
                                            ->label('قبول العاملين')
                                            ->state(fn ($record): string => sprintf('%d / %d', $record->acceptedWorkerCount(), max(1, (int) ($record->number_of_workers ?? 1)))),
                                        TextEntry::make('remaining_workers')
                                            ->label('العاملون المتبقون')
                                            ->state(fn ($record): string => self::integer($record->remainingWorkerCount())),
                                        TextEntry::make('room_coverage')
                                            ->label('تغطية الغرف')
                                            ->state(fn ($record): string => self::roomCoverageLabel($record))
                                            ->visible(fn ($record): bool => ! self::isEventAssistance($record)),
                                    ])
                                    ->columns(3),
                                Section::make('تتبع حركة العاملين')
                                    ->description('آخر مواقع العاملين أثناء التوجه للعميل مع سجل مختصر لأحدث النقاط.')
                                    ->schema([
                                        ViewEntry::make('worker_movement_map')
                                            ->hiddenLabel()
                                            ->getStateUsing(fn (CleaningBooking $record): array => self::workerMovementMapState($record))
                                            ->view('filament.resources.cleaning-bookings.infolists.worker-movement-map')
                                            ->columnSpanFull(),
                                    ])
                                    ->columnSpanFull(),
                                Section::make('العاملون المقبولون')
                                    ->schema([
                                        RepeatableEntry::make('acceptedWorkerAssignments')
                                            ->label('تعيينات العاملين المقبولين')
                                            ->schema([
                                                TextEntry::make('worker.first_name')->label('العامل المقبول')->placeholder('-'),
                                                TextEntry::make('status')
                                                    ->label('الحالة')
                                                    ->badge()
                                                    ->formatStateUsing(fn ($state): string => self::workerAssignmentStatusLabel($state))
                                                    ->color(fn ($state): string => self::workerAssignmentStatusColor($state)),
                                                TextEntry::make('accepted_at')
                                                    ->label('تاريخ القبول')
                                                    ->formatStateUsing(fn ($state): string => self::dateTime($state))
                                                    ->placeholder('-'),
                                                TextEntry::make('room_count')
                                                    ->label('عدد الغرف')
                                                    ->formatStateUsing(fn ($state): string => self::integer($state))
                                                    ->placeholder('-')
                                                    ->visible(fn (CleaningBookingWorkerAssignment $record): bool => ! self::isEventAssistance($record->booking)),
                                                TextEntry::make('rooms_weight')
                                                    ->label('وزن الغرف')
                                                    ->formatStateUsing(fn ($state): string => self::integer($state))
                                                    ->placeholder('-')
                                                    ->visible(fn (CleaningBookingWorkerAssignment $record): bool => ! self::isEventAssistance($record->booking)),
                                                TextEntry::make('service_share_amount')->label('حصة الخدمة')->formatStateUsing(fn ($state): string => self::money($state)),
                                                TextEntry::make('travel_fee')->label('رسوم التنقل')->formatStateUsing(fn ($state): string => self::money($state)),
                                                TextEntry::make('admin_margin_amount')->label('هامش الإدارة')->formatStateUsing(fn ($state): string => self::money($state)),
                                                TextEntry::make('worker_amount')->label('مستحقات العامل')->formatStateUsing(fn ($state): string => self::money($state)),
                                            ])
                                            ->columns(4),
                                    ])
                                    ->visible(fn ($record): bool => $record->acceptedWorkerAssignments()->exists()),
                                Section::make('تفصيل المستحقات')
                                    ->schema([
                                        TextEntry::make('worker_share_total')->label('إجمالي حصة العامل')->state(fn ($record): string => self::money(self::acceptedAssignmentsTotal($record, 'service_share_amount'))),
                                        TextEntry::make('worker_travel_total')->label('إجمالي التنقل للعامل')->state(fn ($record): string => self::money(self::acceptedAssignmentsTotal($record, 'travel_fee'))),
                                        TextEntry::make('worker_admin_total')->label('إجمالي هامش الإدارة للعامل')->state(fn ($record): string => self::money(self::acceptedAssignmentsTotal($record, 'admin_margin_amount'))),
                                        TextEntry::make('worker_amount_total')->label('مستحقات العامل')->state(fn ($record): string => self::money(self::acceptedAssignmentsTotal($record, 'worker_amount'))),
                                    ])
                                    ->columns(2),
                            ])
                            ->columnSpan([
                                'default' => 12,
                                'xl' => 6,
                            ]),
                        Group::make()
                            ->schema([
                                Section::make('التسعير')
                                    ->schema([
                                        TextEntry::make('base_price')->label('السعر الأساسي')->formatStateUsing(fn ($state): string => self::money($state)),
                                        TextEntry::make('addons_total')->label('الإضافات')->formatStateUsing(fn ($state): string => self::money($state)),
                                        TextEntry::make('travel_fee')->label('رسوم التنقل')->formatStateUsing(fn ($state): string => self::money($state)),
                                        TextEntry::make('travel_distance_km')->label('مسافة التنقل (كم)')->formatStateUsing(fn ($state): string => self::integer($state))->placeholder('-'),
                                        TextEntry::make('admin_margin_amount')->label('هامش الإدارة')->formatStateUsing(fn ($state): string => self::money($state)),
                                        TextEntry::make('total_price')->label('الإجمالي')->formatStateUsing(fn ($state): string => self::money($state))->weight('bold'),
                                    ])
                                    ->columns(2),
                                Section::make('الأطراف')
                                    ->schema([
                                        TextEntry::make('customer.name')->label('العميل')->placeholder('-'),
                                        TextEntry::make('worker.first_name')->label('العامل الأساسي')->placeholder('-'),
                                        TextEntry::make('preferred_workers')
                                            ->label('العاملون المفضلون')
                                            ->state(fn ($record): array => self::preferredWorkerNames($record))
                                            ->badge()
                                            ->color('info')
                                            ->placeholder('-')
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2),
                                Section::make('توزيع الغرف')
                                    ->schema([
                                        RepeatableEntry::make('rooms')
                                            ->label('الغرف')
                                            ->schema([
                                                TextEntry::make('display_label')->label('اسم الغرفة')->placeholder('-'),
                                                TextEntry::make('room_type')->label('نوع الغرفة')->formatStateUsing(fn (?string $state): string => self::roomTypeLabel($state))->placeholder('-'),
                                                TextEntry::make('room_size')->label('حجم الغرفة')->formatStateUsing(fn (?string $state): string => self::roomSizeLabel($state))->placeholder('-'),
                                                TextEntry::make('weight')->label('وزن الغرفة')->formatStateUsing(fn ($state): string => self::integer($state))->placeholder('-'),
                                                TextEntry::make('assignedWorker.first_name')->label('العامل المعيّن')->placeholder('-'),
                                                TextEntry::make('assignment_source')->label('مصدر التعيين')->badge()->formatStateUsing(fn ($state): string => self::roomAssignmentSourceLabel($state)),
                                            ])
                                            ->columns(3),
                                    ])
                                    ->visible(fn ($record): bool => ! self::isEventAssistance($record) && $record->rooms()->exists()),
                                Section::make('النزاعات')
                                    ->schema([
                                        TextEntry::make('disputes_count')->counts('disputes')->label('عدد النزاعات'),
                                    ])
                                    ->visible(fn ($record): bool => $record->disputes()->exists()),
                            ])
                            ->columnSpan([
                                'default' => 12,
                                'xl' => 6,
                            ]),
                    ]),
            ]);
    }

    private static function bookingKindLabel(mixed $record): string
    {
        return self::isEventAssistance($record) ? 'مساعدة مناسبة' : 'تنظيف عادي';
    }

    private static function bookingKindColor(mixed $record): string
    {
        return self::isEventAssistance($record) ? 'warning' : 'info';
    }

    private static function isEventAssistance(mixed $record): bool
    {
        return $record !== null
            && $record->property_type === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE;
    }

    private static function preferredWorkerNames(mixed $record): array
    {
        $names = collect();

        if ($record->preferredWorker !== null) {
            $names->push($record->preferredWorker->first_name ?: $record->preferredWorker->user?->name);
        }

        $rooms = $record->relationLoaded('rooms')
            ? $record->rooms
            : $record->rooms()->with('plannedPreferredWorker.user')->get();

        foreach ($rooms as $room) {
            $worker = $room->plannedPreferredWorker;
            if ($worker !== null) {
                $names->push($worker->first_name ?: $worker->user?->name);
            }
        }

        return $names
            ->filter(fn ($name): bool => filled($name))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     points: list<array{latitude: float, longitude: float, label: string}>,
     *     history: list<array{workerName: string, latitude: float, longitude: float, recordedAt: string}>,
     *     destination: ?array{latitude: float, longitude: float}
     * }
     */
    private static function workerMovementMapState(CleaningBooking $record): array
    {
        $record->loadMissing(['workerAssignments.worker.user', 'worker.user']);

        $points = [];

        foreach ($record->workerAssignments as $assignment) {
            if ($assignment->last_latitude === null || $assignment->last_longitude === null) {
                continue;
            }

            $points[] = [
                'latitude' => (float) $assignment->last_latitude,
                'longitude' => (float) $assignment->last_longitude,
                'label' => $assignment->worker?->first_name
                    ?: $assignment->worker?->user?->name
                    ?: 'عامل #'.$assignment->worker_id,
            ];
        }

        if ($points === [] && $record->last_worker_latitude !== null && $record->last_worker_longitude !== null) {
            $points[] = [
                'latitude' => (float) $record->last_worker_latitude,
                'longitude' => (float) $record->last_worker_longitude,
                'label' => $record->worker?->first_name
                    ?: $record->worker?->user?->name
                    ?: 'العامل الأساسي',
            ];
        }

        $history = CleaningWorkerLocationHistory::query()
            ->with(['worker.user'])
            ->where('cleaning_booking_id', $record->id)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (CleaningWorkerLocationHistory $row): array => [
                'workerName' => $row->worker?->first_name
                    ?: $row->worker?->user?->name
                    ?: 'عامل #'.$row->worker_id,
                'latitude' => (float) $row->latitude,
                'longitude' => (float) $row->longitude,
                'recordedAt' => $row->recorded_at?->format('Y-m-d H:i:s') ?: '-',
            ])
            ->all();

        $destination = null;
        if ($record->address_latitude !== null && $record->address_longitude !== null) {
            $destination = [
                'latitude' => (float) $record->address_latitude,
                'longitude' => (float) $record->address_longitude,
            ];
        }

        return [
            'points' => $points,
            'history' => $history,
            'destination' => $destination,
        ];
    }

    private static function roomCoverageLabel(mixed $record): string
    {
        if (self::isEventAssistance($record)) {
            return 'غير مطبق';
        }

        $totalRooms = max(0, (int) $record->rooms()->count());
        if ($totalRooms === 0) {
            return '-';
        }

        $assignedRooms = max(0, (int) $record->rooms()->whereNotNull('assigned_worker_id')->count());
        $percent = (int) round(($assignedRooms / $totalRooms) * 100);

        return sprintf('%d/%d (%d%%)', $assignedRooms, $totalRooms, $percent);
    }

    private static function acceptedAssignmentsTotal(mixed $record, string $field): float
    {
        return (float) $record->workerAssignments()
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->sum($field);
    }

    private static function workerAssignmentStatusLabel(mixed $state): string
    {
        $value = $state?->value ?? $state;

        return match ((string) $value) {
            'pending' => 'قيد الانتظار',
            'accepted' => 'مقبول',
            'accepted_waiting_for_order_start' => 'مقبول وبانتظار بدء الطلب',
            'awaiting_start_verification' => 'بانتظار التحقق من البدء',
            'start_approved' => 'تمت الموافقة على البدء',
            'in_progress' => 'قيد التنفيذ',
            'awaiting_customer_completion' => 'بانتظار تأكيد العميل',
            'time_extension_requested' => 'تم طلب تمديد الوقت',
            'completed' => 'مكتمل',
            'rejected' => 'مرفوض',
            'withdrawn' => 'منسحب',
            'cancelled' => 'ملغى',
            default => '-',
        };
    }

    private static function workerAssignmentStatusColor(mixed $state): string
    {
        $value = $state?->value ?? $state;

        return match ((string) $value) {
            'completed' => 'success',
            'rejected', 'cancelled' => 'danger',
            'withdrawn' => 'warning',
            'in_progress', 'time_extension_requested' => 'primary',
            'accepted', 'accepted_waiting_for_order_start', 'awaiting_start_verification', 'start_approved', 'awaiting_customer_completion' => 'info',
            default => 'gray',
        };
    }

    private static function propertyTypeLabel(?string $value): string
    {
        return match ($value) {
            'apartment' => 'شقة',
            'villa' => 'فيلا',
            'house' => 'منزل',
            'office' => 'مكتب',
            'studio' => 'استوديو',
            'event_assistance' => 'مساعدة مناسبة',
            null, '' => '-',
            default => $value,
        };
    }

    private static function eventTypeLabel(?string $value): string
    {
        return match ($value) {
            'family_dinner' => 'عشاء عائلي',
            'birthday' => 'عيد ميلاد',
            'large_gathering' => 'تجمع كبير',
            'funeral' => 'عزاء',
            'other' => 'أخرى',
            null, '' => '-',
            default => $value,
        };
    }

    private static function roomTypeLabel(?string $value): string
    {
        return match ($value) {
            'bedroom' => 'غرفة نوم',
            'bathroom' => 'حمام',
            'toilet' => 'دورة مياه',
            'kitchen' => 'مطبخ',
            'living_room' => 'غرفة معيشة',
            'balcony' => 'شرفة',
            'corridor' => 'ممر',
            'shed' => 'سقيفة',
            'room' => 'غرفة',
            null, '' => '-',
            default => $value,
        };
    }

    private static function roomSizeLabel(?string $value): string
    {
        return match ($value) {
            'small' => 'صغير',
            'medium' => 'متوسط',
            'large' => 'كبير',
            null, '' => '-',
            default => $value,
        };
    }

    private static function roomAssignmentSourceLabel(mixed $state): string
    {
        $value = $state?->value ?? $state;

        return match ((string) $value) {
            'customer' => 'العميل',
            'worker' => 'العامل',
            'auto' => 'تلقائي',
            'admin' => 'الإدارة',
            default => '-',
        };
    }

    private static function cancellationSourceLabel(mixed $state): string
    {
        $value = $state instanceof \BackedEnum ? $state->value : $state;

        return match ((string) $value) {
            'customer' => 'ألغاه العميل',
            'worker' => 'ألغاه العامل',
            default => '-',
        };
    }

    private static function cancellationSourceColor(mixed $state): string
    {
        $value = $state instanceof \BackedEnum ? $state->value : $state;

        return match ((string) $value) {
            'customer' => 'danger',
            'worker' => 'warning',
            default => 'gray',
        };
    }

    private static function money(mixed $amount): string
    {
        return self::integer($amount).' ل.س';
    }

    private static function integer(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        return number_format((int) round((float) $value), 0, '.', ',');
    }

    private static function date(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private static function time(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        try {
            return Carbon::parse((string) $value)->format('h:i A');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private static function dateTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        try {
            return Carbon::parse($value)->format('Y-m-d h:i A');
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
