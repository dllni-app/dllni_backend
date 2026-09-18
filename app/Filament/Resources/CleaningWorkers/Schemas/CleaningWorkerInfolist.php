<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Schemas;

use App\Enums\WorkerCustomerRatingType;
use App\Enums\WorkerPreferredWorkType;
use App\Models\Worker;
use App\Models\WorkerCustomerRating;
use App\Models\WorkerZone;
use BackedEnum;
use Carbon\Carbon;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Modules\Cleaning\Services\WorkerFinancialAccountStatusService;
use Throwable;

final class CleaningWorkerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(12)
                ->schema([
                    Section::make('معلومات العامل')
                        ->description('البيانات الأساسية التي تحتاجها الإدارة للتعرف على العامل والتواصل معه.')
                        ->schema([
                            Grid::make(4)
                                ->schema([
                                    ImageEntry::make('avatar_preview')
                                        ->label('الصورة')
                                        ->getStateUsing(fn (Worker $record): ?string => self::workerAvatarUrl($record))
                                        ->defaultImageUrl(fn (Worker $record): string => self::fallbackAvatarUrl($record))
                                        ->circular()
                                        ->imageHeight(96),
                                    Group::make()
                                        ->schema([
                                            TextEntry::make('worker_display_name')
                                                ->label('الاسم')
                                                ->state(fn (Worker $record): string => $record->user?->name ?: $record->first_name ?: '-')
                                                ->weight('bold')
                                                ->size('lg'),
                                            TextEntry::make('account_status')
                                                ->label('حالة الحساب')
                                                ->state(fn (Worker $record): string => self::accountStatusLabel($record))
                                                ->badge()
                                                ->color(fn (mixed $state): string => self::accountStatusColor($state)),
                                            TextEntry::make('user.phone')
                                                ->label('رقم الهاتف')
                                                ->placeholder('-')
                                                ->copyable()
                                                ->extraAttributes(['class' => 'fi-phone-ltr', 'dir' => 'ltr']),
                                            TextEntry::make('user.email')
                                                ->label('البريد الإلكتروني')
                                                ->placeholder('-')
                                                ->copyable(),
                                            TextEntry::make('preferred_work_type_display')
                                                ->label('الخدمات التي يعمل بها')
                                                ->state(fn (Worker $record): string => self::preferredWorkTypeLabel($record))
                                                ->badge()
                                                ->color('info'),
                                            TextEntry::make('home_address')
                                                ->label('موقع بدء المهمة')
                                                ->placeholder('-'),
                                        ])
                                        ->columns(3)
                                        ->columnSpan(3),
                                ]),
                        ])
                        ->columnSpanFull(),

                    Section::make('ملخص العمل')
                        ->description('أهم المعلومات التي تساعد الإدارة على معرفة وضع العامل الحالي بسرعة.')
                        ->schema([
                            TextEntry::make('statistics_total_completed_jobs')
                                ->label('الطلبات المكتملة')
                                ->state(fn (Worker $record): string => self::formatInteger($record->total_completed_jobs))
                                ->icon(Heroicon::OutlinedCheckCircle)
                                ->weight('bold'),
                            TextEntry::make('statistics_average_rating')
                                ->label('متوسط تقييم العملاء')
                                ->state(fn (Worker $record): string => self::formatStars(self::reviewsSummary($record)['average']))
                                ->icon(Heroicon::OutlinedStar)
                                ->weight('bold'),
                            TextEntry::make('statistics_reviews_count')
                                ->label('عدد تقييمات العملاء')
                                ->state(fn (Worker $record): string => self::formatInteger(self::reviewsSummary($record)['total']))
                                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                                ->weight('bold'),
                            TextEntry::make('statistics_booking_availability')
                                ->label('استقبال طلبات جديدة')
                                ->state(fn (Worker $record): string => app(WorkerFinancialAccountStatusService::class)->status($record))
                                ->formatStateUsing(fn (mixed $state): string => self::workerAvailabilityLabel((string) $state))
                                ->badge()
                                ->color(fn (mixed $state): string => self::workerAvailabilityColor((string) $state)),
                        ])
                        ->columns(4)
                        ->columnSpanFull(),

                    Section::make('تقييمات العملاء')
                        ->description('التقييمات والتعليقات التي تركها العملاء بعد تنفيذ الطلبات.')
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            TextEntry::make('reviews_average')
                                ->label('متوسط التقييم')
                                ->state(fn (Worker $record): string => self::formatStars(self::reviewsSummary($record)['average']))
                                ->icon(Heroicon::OutlinedStar)
                                ->weight('bold'),
                            TextEntry::make('reviews_total')
                                ->label('إجمالي التقييمات')
                                ->state(fn (Worker $record): string => self::formatInteger(self::reviewsSummary($record)['total']))
                                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                                ->weight('bold'),
                            TextEntry::make('reviews_empty_state')
                                ->hiddenLabel()
                                ->state('لا توجد تقييمات من العملاء حتى الآن.')
                                ->visible(fn (Worker $record): bool => self::customerReviews($record)->isEmpty())
                                ->color('gray')
                                ->columnSpanFull(),
                            RepeatableEntry::make('customer_reviews')
                                ->label('تفاصيل التقييمات')
                                ->getStateUsing(fn (Worker $record): array => self::reviewEntries($record))
                                ->visible(fn (Worker $record): bool => self::customerReviews($record)->isNotEmpty())
                                ->schema([
                                    TextEntry::make('customer_name')
                                        ->label('من قام بالتقييم')
                                        ->weight('bold'),
                                    TextEntry::make('customer_phone')
                                        ->label('رقم العميل')
                                        ->placeholder('-')
                                        ->copyable()
                                        ->extraAttributes(['class' => 'fi-phone-ltr', 'dir' => 'ltr']),
                                    TextEntry::make('rating')
                                        ->label('التقييم')
                                        ->formatStateUsing(fn (mixed $state): string => self::formatStars((float) $state))
                                        ->badge()
                                        ->color(fn (mixed $state): string => self::ratingColor($state)),
                                    TextEntry::make('booking_reference')
                                        ->label('الطلب'),
                                    TextEntry::make('created_at')
                                        ->label('تاريخ التقييم'),
                                    TextEntry::make('comment')
                                        ->label('تعليق العميل')
                                        ->placeholder('لم يكتب العميل تعليقاً.')
                                        ->columnSpanFull(),
                                ])
                                ->columns(3)
                                ->columnSpanFull(),
                        ])
                        ->columns(3)
                        ->columnSpanFull(),

                    Section::make('أوقات العمل')
                        ->description('الأيام والفترات التي اختار العامل استقبال الطلبات خلالها داخل التطبيق.')
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            ViewEntry::make('worker_working_hours')
                                ->hiddenLabel()
                                ->getStateUsing(fn (Worker $record): array => self::workingHoursState($record))
                                ->view('filament.resources.workers.infolists.working-hours')
                                ->columnSpanFull(),
                        ])
                        ->columnSpanFull(),

                    Section::make('مناطق العمل')
                        ->description('الأحياء والمناطق التي اختار العامل استقبال طلبات التنظيف ضمنها.')
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            TextEntry::make('work_areas_empty_state')
                                ->hiddenLabel()
                                ->state('لم يحدد العامل مناطق عمل بعد.')
                                ->visible(fn (Worker $record): bool => self::workAreaEntries($record) === [])
                                ->color('gray')
                                ->columnSpanFull(),
                            RepeatableEntry::make('work_areas')
                                ->hiddenLabel()
                                ->getStateUsing(fn (Worker $record): array => self::workAreaEntries($record))
                                ->visible(fn (Worker $record): bool => self::workAreaEntries($record) !== [])
                                ->schema([
                                    TextEntry::make('name')
                                        ->label('المنطقة')
                                        ->weight('bold'),
                                    TextEntry::make('city')
                                        ->label('المدينة')
                                        ->placeholder('-'),
                                    TextEntry::make('status')
                                        ->label('الحالة')
                                        ->badge()
                                        ->color(fn (mixed $state): string => (string) $state === 'نشطة' ? 'success' : 'gray'),
                                ])
                                ->columns(3)
                                ->columnSpanFull(),
                        ])
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
        ]);
    }

    private static function workerAvatarUrl(Worker $worker): ?string
    {
        $url = $worker->getFirstMediaUrl('avatar');

        return $url !== '' ? $url : null;
    }

    private static function fallbackAvatarUrl(Worker $worker): string
    {
        $name = rawurlencode($worker->user?->name ?: $worker->first_name ?: 'Worker');

        return "https://ui-avatars.com/api/?name={$name}&background=f3f4f6&color=111827";
    }

    private static function accountStatusLabel(Worker $worker): string
    {
        if ($worker->is_suspended) {
            return 'موقوف';
        }

        return $worker->is_active ? 'نشط' : 'غير نشط';
    }

    private static function accountStatusColor(mixed $state): string
    {
        return match ((string) $state) {
            'نشط' => 'success',
            'موقوف' => 'danger',
            default => 'gray',
        };
    }

    private static function workerAvailabilityLabel(string $status): string
    {
        return match ($status) {
            WorkerFinancialAccountStatusService::ACTIVE => 'متاح لاستقبال الطلبات',
            WorkerFinancialAccountStatusService::SUSPENDED => 'موقوف من الإدارة',
            WorkerFinancialAccountStatusService::INSUFFICIENT_BALANCE => 'غير متاح - الرصيد غير كافٍ',
            WorkerFinancialAccountStatusService::INACTIVE => 'غير نشط',
            default => 'غير محدد',
        };
    }

    private static function workerAvailabilityColor(string $status): string
    {
        return match ($status) {
            WorkerFinancialAccountStatusService::ACTIVE => 'success',
            WorkerFinancialAccountStatusService::SUSPENDED => 'warning',
            WorkerFinancialAccountStatusService::INSUFFICIENT_BALANCE,
            WorkerFinancialAccountStatusService::INACTIVE => 'danger',
            default => 'gray',
        };
    }

    private static function preferredWorkTypeLabel(Worker $worker): string
    {
        $value = $worker->preferred_work_type instanceof WorkerPreferredWorkType
            ? $worker->preferred_work_type->value
            : (is_string($worker->preferred_work_type) ? $worker->preferred_work_type : WorkerPreferredWorkType::Both->value);

        return WorkerPreferredWorkType::options()[$value] ?? $value;
    }

    /**
     * @return Collection<int, WorkerCustomerRating>
     */
    private static function customerReviews(Worker $worker): Collection
    {
        $worker->loadMissing(['customerRatings.customer']);

        return $worker->customerRatings
            ->filter(
                fn (WorkerCustomerRating $rating): bool => self::enumValue($rating->rating_type)
                    === WorkerCustomerRatingType::CustomerToWorker->value,
            )
            ->sortByDesc('created_at')
            ->values();
    }

    /**
     * @return array{average:float,total:int}
     */
    private static function reviewsSummary(Worker $worker): array
    {
        $reviews = self::customerReviews($worker);
        $average = $reviews->isNotEmpty()
            ? round((float) $reviews->avg('rating'), 1)
            : (float) ($worker->average_rating ?? 0);

        return [
            'average' => $average,
            'total' => $reviews->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function reviewEntries(Worker $worker): array
    {
        return self::customerReviews($worker)
            ->map(fn (WorkerCustomerRating $review): array => [
                'customer_name' => $review->customer?->name ?: 'مستخدم غير متاح',
                'customer_phone' => $review->customer?->phone,
                'rating' => (int) $review->rating,
                'booking_reference' => self::bookingReference($review),
                'created_at' => $review->created_at?->format('Y-m-d H:i') ?: '-',
                'comment' => $review->comment,
            ])
            ->all();
    }

    private static function bookingReference(WorkerCustomerRating $review): string
    {
        $bookingType = class_basename((string) $review->booking_type);
        $typeLabel = match ($bookingType) {
            'CleaningBooking' => 'طلب تنظيف',
            'EventBooking' => 'طلب فعالية',
            default => 'طلب',
        };

        return $typeLabel.' #'.$review->booking_id;
    }

    private static function ratingColor(mixed $state): string
    {
        $rating = (int) $state;

        return match (true) {
            $rating >= 4 => 'success',
            $rating === 3 => 'warning',
            default => 'danger',
        };
    }

    private static function enumValue(mixed $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }

    /**
     * @return array<int, array{key:string,label:string,available:bool,ranges:array<int, array{from:string,to:string,label:string}>}>
     */
    private static function workingHoursState(Worker $worker): array
    {
        $normalized = $worker->getNormalizedDefaultWorkingHours();
        $dayLabels = [
            'sunday' => 'الأحد',
            'monday' => 'الإثنين',
            'tuesday' => 'الثلاثاء',
            'wednesday' => 'الأربعاء',
            'thursday' => 'الخميس',
            'friday' => 'الجمعة',
            'saturday' => 'السبت',
        ];

        $days = [];
        foreach ($dayLabels as $day => $label) {
            $dayData = $normalized[$day] ?? ['available' => false, 'data' => []];
            $ranges = [];

            foreach ((array) ($dayData['data'] ?? []) as $period) {
                if (! is_array($period)) {
                    continue;
                }

                $from = isset($period['from']) && is_string($period['from'])
                    ? $period['from']
                    : array_key_first($period);
                $to = isset($period['to']) && is_string($period['to'])
                    ? $period['to']
                    : (is_string($from) ? ($period[$from] ?? null) : null);

                if (! is_string($from) || ! is_string($to)) {
                    continue;
                }

                $ranges[] = [
                    'from' => $from,
                    'to' => $to,
                    'label' => self::formatWorkingTime($from).' — '.self::formatWorkingTime($to),
                ];
            }

            $days[] = [
                'key' => $day,
                'label' => $label,
                'available' => (bool) ($dayData['available'] ?? false) && $ranges !== [],
                'ranges' => $ranges,
            ];
        }

        return $days;
    }

    private static function formatWorkingTime(string $time): string
    {
        foreach (['H:i', 'H:i:s'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $time);

                return str_replace(['AM', 'PM'], ['ص', 'م'], $parsed->format('h:i A'));
            } catch (Throwable) {
                continue;
            }
        }

        return $time;
    }

    /**
     * @return array<int, array{name:string,city:?string,status:string}>
     */
    private static function workAreaEntries(Worker $worker): array
    {
        $worker->loadMissing(['zones.neighborhood']);

        return $worker->zones
            ->sortByDesc(fn (WorkerZone $zone): bool => (bool) $zone->is_active)
            ->map(fn (WorkerZone $zone): array => [
                'name' => $zone->neighborhood?->name_ar
                    ?: $zone->name
                    ?: $zone->neighborhood?->name_en
                    ?: '-',
                'city' => $zone->neighborhood?->city_name,
                'status' => $zone->is_active ? 'نشطة' : 'غير نشطة',
            ])
            ->values()
            ->all();
    }

    private static function formatInteger(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 0, '.', ',');
    }

    private static function formatDecimal(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 1, '.', ',');
    }

    private static function formatStars(float $rating): string
    {
        $clamped = max(0, min(5, (int) round($rating)));
        $filled = str_repeat('★', $clamped);
        $empty = str_repeat('☆', 5 - $clamped);

        return $filled.$empty.' ('.self::formatDecimal($rating).')';
    }
}
