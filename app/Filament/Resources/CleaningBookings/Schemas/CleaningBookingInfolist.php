<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Schemas;

use App\Models\PlatformCoupon;
use BackedEnum;
use Carbon\Carbon;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoom;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningCouponPricingService;
use Modules\User\Services\UserCleaningOrderEstimationService;
use Throwable;

final class CleaningBookingInfolist
{
    /** @var array<int, PlatformCoupon|null> */
    private static array $platformCouponCache = [];

    /** @var array<int, array<string, float>|null> */
    private static array $couponBreakdownCache = [];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(12)
                    ->schema([
                        Group::make()
                            ->schema([
                                Section::make('ملخص الحجز')
                                    ->description('المعلومات الأساسية للحجز. جميع الأوقات المعروضة بتوقيت سوريا - حلب.')
                                    ->schema([
                                        TextEntry::make('booking_number')->label('رقم الحجز'),
                                        TextEntry::make('status')
                                            ->label('الحالة')
                                            ->badge()
                                            ->formatStateUsing(fn ($state): string => $state?->label() ?? '-'),
                                        TextEntry::make('booking_kind')
                                            ->label('نوع الحجز')
                                            ->state(fn ($record): string => $record->dashboardKindLabel())
                                            ->badge()
                                            ->color(fn ($record): string => $record->dashboardKindColor()),
                                        TextEntry::make('customer.name')
                                            ->label('العميل')
                                            ->placeholder('-'),
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
                                        TextEntry::make('scheduled_date')
                                            ->label('موعد الخدمة')
                                            ->state(fn (CleaningBooking $record): string => self::appointmentLabel($record))
                                            ->weight('bold'),
                                    ])
                                    ->columns(2),
                                Section::make('تفاصيل المناسبة')
                                    ->collapsible()
                                    ->collapsed()
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
                                    ->description('أوقات بدء وإنهاء العمل وتأكيد العميل بتوقيت سوريا - حلب.')
                                    ->collapsible()
                                    ->collapsed()
                                    ->visible(fn (CleaningBooking $record): bool => filled($record->work_started_at) || filled($record->work_finished_at) || filled($record->customer_confirmed_at))
                                    ->schema([
                                        TextEntry::make('work_started_at')->label('بدأ العمل')->formatStateUsing(fn ($state): string => self::dateTime($state))->placeholder('-'),
                                        TextEntry::make('work_finished_at')->label('انتهى العمل')->formatStateUsing(fn ($state): string => self::dateTime($state))->placeholder('-'),
                                        TextEntry::make('customer_confirmed_at')->label('تأكيد العميل')->formatStateUsing(fn ($state): string => self::dateTime($state))->placeholder('-'),
                                    ])
                                    ->columns(3),
                                Section::make('فريق العمل')
                                    ->description('حالة اكتمال الفريق والعاملون الذين تم تأكيدهم لتنفيذ الحجز.')
                                    ->schema([
                                        TextEntry::make('worker_acceptance')
                                            ->label('الفريق المؤكد')
                                            ->state(fn ($record): string => sprintf('%d من %d', $record->acceptedWorkerCount(), max(1, (int) ($record->number_of_workers ?? 1))))
                                            ->badge()
                                            ->color(fn ($record): string => $record->isTeamFulfilled() ? 'success' : 'warning'),
                                        TextEntry::make('remaining_workers')
                                            ->label('متبقٍ لإكمال الفريق')
                                            ->state(fn ($record): string => self::integer($record->remainingWorkerCount())),
                                        TextEntry::make('confirmed_workers')
                                            ->label('العاملون المؤكدون')
                                            ->state(fn (CleaningBooking $record): array => self::acceptedWorkerNames($record))
                                            ->badge()
                                            ->color('success')
                                            ->placeholder('لم يتم تأكيد أي عامل بعد')
                                            ->columnSpanFull(),
                                        TextEntry::make('preferred_workers')
                                            ->label('العاملون المفضلون من العميل')
                                            ->state(fn (CleaningBooking $record): array => self::preferredWorkerNames($record))
                                            ->badge()
                                            ->color('info')
                                            ->visible(fn (CleaningBooking $record): bool => self::preferredWorkerNames($record) !== [])
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2),

                            ])
                            ->columnSpan([
                                'default' => 12,
                                'xl' => 6,
                            ]),
                        Group::make()
                            ->schema([
                                Section::make('الملخص المالي')
                                    ->description('الأرقام النهائية التي يحتاجها مدير المنصة لمراجعة الطلب وحصص الأطراف.')
                                    ->schema([
                                        TextEntry::make('financial_service')
                                            ->label('قيمة الخدمة')
                                            ->state(fn (CleaningBooking $record): string => self::financialServiceSummary($record)),
                                        TextEntry::make('financial_travel')
                                            ->label('رسوم التنقل')
                                            ->state(fn (CleaningBooking $record): string => self::money($record->travel_fee))
                                            ->visible(fn (CleaningBooking $record): bool => (float) ($record->travel_fee ?? 0) > 0),
                                        TextEntry::make('financial_admin')
                                            ->label('هامش الإدارة')
                                            ->state(fn (CleaningBooking $record): string => self::financialAdminSummary($record)),
                                        TextEntry::make('financial_coupon')
                                            ->label('الكوبون')
                                            ->state(fn (CleaningBooking $record): string => self::financialCouponSummary($record))
                                            ->visible(fn (CleaningBooking $record): bool => self::couponUsed($record)),
                                        TextEntry::make('financial_before_coupon')
                                            ->label('قبل الكوبون')
                                            ->state(fn (CleaningBooking $record): string => self::money(self::priceBeforeCoupon($record)))
                                            ->visible(fn (CleaningBooking $record): bool => self::couponUsed($record)),
                                        TextEntry::make('financial_customer_total')
                                            ->label('المطلوب من العميل')
                                            ->state(fn (CleaningBooking $record): string => self::money(
                                                self::couponUsed($record)
                                                    ? (self::couponBreakdown($record)['totalPrice'] ?? $record->total_price)
                                                    : $record->total_price
                                            ))
                                            ->weight('bold'),
                                    ])
                                    ->columns(2),
                                Section::make('أجور العاملين النهائية')
                                    ->description('المبلغ النهائي المستحق لكل عامل في هذا الطلب بعد اعتماد توزيع الخدمة ورسوم التنقل.')
                                    ->schema([
                                        RepeatableEntry::make('acceptedWorkerAssignments')
                                            ->hiddenLabel()
                                            ->schema([
                                                TextEntry::make('worker.user.name')
                                                    ->label('العامل')
                                                    ->placeholder('-')
                                                    ->weight('bold'),
                                                TextEntry::make('worker_amount')
                                                    ->label('الأجرة النهائية')
                                                    ->state(fn (CleaningBookingWorkerAssignment $record): string => self::finalWorkerAmount($record))
                                                    ->weight('bold')
                                                    ->color('success'),
                                                TextEntry::make('status')
                                                    ->label('حالة العامل')
                                                    ->badge()
                                                    ->formatStateUsing(fn ($state): string => self::workerAssignmentStatusLabel($state))
                                                    ->color(fn ($state): string => self::workerAssignmentStatusColor($state)),
                                            ])
                                            ->columns(3),
                                    ])
                                    ->visible(fn (CleaningBooking $record): bool => $record->acceptedWorkerAssignments()->exists()),
                                Section::make('توزيع الغرف')
                                    ->description('تفاصيل الغرف والعامل المعيّن لكل غرفة. افتح هذا القسم عند الحاجة.')
                                    ->collapsible()
                                    ->collapsed()
                                    ->schema([
                                        RepeatableEntry::make('rooms')
                                            ->label('الغرف')
                                            ->schema([
                                                TextEntry::make('display_label')
                                                    ->label('اسم الغرفة')
                                                    ->state(fn (CleaningBookingRoom $record): string => self::arabicRoomName($record))
                                                    ->placeholder('-')
                                                    ->weight('bold'),
                                                TextEntry::make('assignedWorker.user.name')
                                                    ->label('العامل المعيّن')
                                                    ->placeholder('-'),
                                            ])
                                            ->columns(2),
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

    private static function couponUsed(CleaningBooking $record): bool
    {
        return filled($record->platform_coupon_code)
            || $record->platform_coupon_id !== null
            || (float) ($record->discount_amount ?? 0) > 0;
    }

    private static function couponCode(CleaningBooking $record): string
    {
        return (string) ($record->platform_coupon_code ?: self::platformCoupon($record)?->code ?: '—');
    }

    private static function couponValueLabel(CleaningBooking $record): string
    {
        $coupon = self::platformCoupon($record);
        if ($coupon === null || $coupon->discount_value === null) {
            return '—';
        }

        $value = (float) $coupon->discount_value;
        if ($coupon->discount_type === 'percentage') {
            return self::decimal($value).'%';
        }

        return self::money($value);
    }

    private static function financialServiceSummary(CleaningBooking $record): string
    {
        $breakdown = self::couponBreakdown($record);
        $gross = (float) ($breakdown['grossServiceAmount']
            ?? ((float) ($record->base_price ?? 0) + (float) ($record->addons_total ?? 0)));
        $net = (float) ($breakdown['serviceAmount'] ?? $gross);

        if (abs($gross - $net) <= 0.01) {
            return self::money($net);
        }

        return sprintf('%s (قبل الخصم %s)', self::money($net), self::money($gross));
    }

    private static function financialAdminSummary(CleaningBooking $record): string
    {
        if (! self::couponUsed($record)) {
            return self::money($record->admin_margin_amount);
        }

        $breakdown = self::couponBreakdown($record);
        $gross = (float) ($breakdown['grossAdminMargin'] ?? $record->admin_margin_amount ?? 0);
        $net = (float) ($breakdown['adminMargin'] ?? $record->admin_margin_amount ?? 0);

        return sprintf('%s (قبل الخصم %s)', self::money($net), self::money($gross));
    }

    private static function financialCouponSummary(CleaningBooking $record): string
    {
        $value = self::couponValueLabel($record);
        $discount = (float) (self::couponBreakdown($record)['discountAmount']
            ?? $record->discount_amount
            ?? 0);

        return sprintf('%s • %s • خصم %s', self::couponCode($record), $value, self::money($discount));
    }

    private static function priceBeforeCoupon(CleaningBooking $record): float
    {
        if ($record->subtotal_before_discount !== null) {
            return max(0.0, (float) $record->subtotal_before_discount);
        }

        return max(0.0, (float) ($record->total_price ?? 0) + (float) ($record->discount_amount ?? 0));
    }

    /** @return array<string, float>|null */
    private static function couponBreakdown(CleaningBooking $record): ?array
    {
        $bookingId = (int) $record->getKey();

        if (! array_key_exists($bookingId, self::$couponBreakdownCache)) {
            self::$couponBreakdownCache[$bookingId] = app(CleaningCouponPricingService::class)
                ->storedBreakdown($record);
        }

        return self::$couponBreakdownCache[$bookingId];
    }

    private static function platformCoupon(CleaningBooking $record): ?PlatformCoupon
    {
        $couponId = (int) ($record->platform_coupon_id ?? 0);
        if ($couponId <= 0) {
            return null;
        }

        if (! array_key_exists($couponId, self::$platformCouponCache)) {
            self::$platformCouponCache[$couponId] = PlatformCoupon::query()->find($couponId);
        }

        return self::$platformCouponCache[$couponId];
    }

    private static function acceptedWorkerNames(CleaningBooking $record): array
    {
        $assignments = $record->relationLoaded('acceptedWorkerAssignments')
            ? $record->acceptedWorkerAssignments
            : $record->acceptedWorkerAssignments()->with('worker.user')->get();

        return $assignments
            ->map(fn (CleaningBookingWorkerAssignment $assignment): ?string => $assignment->worker?->first_name ?: $assignment->worker?->user?->name)
            ->filter()
            ->unique()
            ->values()
            ->all();
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

    private static function isEventAssistance(mixed $record): bool
    {
        if ($record === null) {
            return false;
        }

        if ($record instanceof CleaningBooking) {
            return $record->isEventAssistanceBooking();
        }

        return $record->property_type === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE;
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

    private static function finalWorkerAmount(CleaningBookingWorkerAssignment $assignment): string
    {
        $amount = $assignment->worker_amount;

        if ($amount === null) {
            $amount = (float) ($assignment->service_share_amount ?? 0)
                + (float) ($assignment->travel_fee ?? 0);
        }

        return self::money($amount);
    }

    private static function arabicRoomName(CleaningBookingRoom $room): string
    {
        $type = match ((string) $room->room_type) {
            'bedroom' => 'غرفة نوم',
            'bathroom' => 'حمّام',
            'toilet' => 'دورة مياه',
            'kitchen' => 'مطبخ',
            'living_room' => 'غرفة معيشة',
            'balcony' => 'شرفة',
            'corridor' => 'ممر',
            'shed' => 'مستودع',
            default => 'غرفة',
        };

        $size = match ((string) $room->room_size) {
            'small' => 'صغيرة',
            'medium' => 'متوسطة',
            'large' => 'كبيرة',
            default => null,
        };

        return $size !== null ? $type.' '.$size : $type;
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
        $value = $state instanceof BackedEnum ? $state->value : $state;

        return match ((string) $value) {
            'customer' => 'ألغاه العميل',
            'worker' => 'ألغاه العامل',
            'admin' => 'ألغته الإدارة',
            default => '-',
        };
    }

    private static function cancellationSourceColor(mixed $state): string
    {
        $value = $state instanceof BackedEnum ? $state->value : $state;

        return match ((string) $value) {
            'customer' => 'danger',
            'worker' => 'warning',
            'admin' => 'info',
            default => 'gray',
        };
    }

    private static function money(mixed $amount): string
    {
        return self::integer($amount).' ل.س';
    }

    private static function decimal(float $value): string
    {
        return mb_rtrim(mb_rtrim(number_format($value, 2, '.', ','), '0'), '.');
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
        } catch (Throwable) {
            return (string) $value;
        }
    }

    private static function time(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        try {
            return self::arabicPeriod(Carbon::parse((string) $value)->format('h:i A'));
        } catch (Throwable) {
            return (string) $value;
        }
    }

    private static function appointmentLabel(CleaningBooking $record): string
    {
        return self::date($record->scheduled_date).' • '.self::time($record->scheduled_time);
    }

    private static function dateTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        try {
            $timezone = (string) config('app.dashboard_timezone', 'Asia/Damascus');

            return self::arabicPeriod(Carbon::parse($value)->setTimezone($timezone)->format('Y-m-d h:i A'));
        } catch (Throwable) {
            return (string) $value;
        }
    }

    private static function arabicPeriod(string $value): string
    {
        return str_replace(['AM', 'PM'], ['ص', 'م'], $value);
    }
}
