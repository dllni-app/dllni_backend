<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Pages;

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Models\BookingReview;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningTimeWarningResponse;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;

final class ViewCleaningBooking extends ViewRecord
{
    protected static string $resource = CleaningBookingResource::class;

    public function getTitle(): string
    {
        return 'عرض حجز تنظيف';
    }

    public function infolist(Schema $schema): Schema
    {
        $this->record->loadMissing([
            'reviews.customer',
            'timeWarnings.worker.user',
        ]);

        // ViewRecord schemas use a multi-column root layout by default. The
        // existing cleaning booking infolist already owns its responsive grid,
        // so keeping the root at one column prevents that grid from occupying
        // only half of the available page and leaving a large empty area.
        $schema = parent::infolist($schema)->columns(1);
        $existingComponents = $schema->getComponents(withHidden: true, withOriginalKeys: true);

        return $schema->components([
            ...$existingComponents,
            Section::make('تقييم العميل')
                ->description('التقييم والملاحظات التي أرسلها العميل بعد اكتمال الطلب.')
                ->schema([
                    RepeatableEntry::make('reviews')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('customer.name')
                                ->label('العميل')
                                ->placeholder('-'),
                            TextEntry::make('rating')
                                ->label('التقييم')
                                ->state(fn (BookingReview $record): string => sprintf('%d / 5', (int) $record->rating))
                                ->badge()
                                ->color(fn (BookingReview $record): string => self::reviewColor((int) $record->rating)),
                            TextEntry::make('created_at')
                                ->label('وقت التقييم')
                                ->dateTime('Y-m-d h:i A')
                                ->placeholder('-'),
                            TextEntry::make('comment')
                                ->label('ملاحظات العميل')
                                ->placeholder('لا توجد ملاحظات مكتوبة')
                                ->columnSpanFull(),
                        ])
                        ->columns([
                            'default' => 1,
                            'md' => 2,
                            'xl' => 3,
                        ]),
                ])
                ->visible(fn (CleaningBooking $record): bool => $record->reviews->isNotEmpty())
                ->columnSpanFull(),
            Section::make('تمديدات الوقت')
                ->description('سجل طلبات تمديد وقت العمل والمدة المطلوبة والمبلغ ورد العامل، بنفس بيانات تطبيق العميل والعامل.')
                ->schema([
                    TextEntry::make('extension_fee_total')
                        ->label('إجمالي رسوم التمديد المضافة')
                        ->state(fn (CleaningBooking $record): string => self::money(
                            $record->extension_fee_total,
                            (string) config('app.currency', 'SYP'),
                        ))
                        ->weight('bold'),
                    RepeatableEntry::make('timeWarnings')
                        ->label('طلبات التمديد')
                        ->schema([
                            TextEntry::make('extension_status')
                                ->label('الحالة')
                                ->state(fn (CleaningTimeWarning $record): string => self::extensionStatusLabel($record))
                                ->badge()
                                ->color(fn (CleaningTimeWarning $record): string => self::extensionStatusColor($record)),
                            TextEntry::make('additional_minutes')
                                ->label('المدة المطلوبة')
                                ->state(fn (CleaningTimeWarning $record): string => $record->additional_minutes !== null
                                    ? sprintf('%d دقيقة', (int) $record->additional_minutes)
                                    : '-'),
                            TextEntry::make('quoted_amount')
                                ->label('تكلفة التمديد')
                                ->state(fn (CleaningTimeWarning $record): string => self::money(
                                    $record->quoted_amount,
                                    $record->quoted_currency,
                                )),
                            TextEntry::make('worker.user.name')
                                ->label('العامل')
                                ->placeholder('-'),
                            TextEntry::make('customer_response_display')
                                ->label('قرار العميل')
                                ->state(fn (CleaningTimeWarning $record): string => self::timeWarningResponseLabel(
                                    $record->customer_response,
                                    false,
                                )),
                            TextEntry::make('worker_response_display')
                                ->label('رد العامل')
                                ->state(fn (CleaningTimeWarning $record): string => self::timeWarningResponseLabel(
                                    $record->worker_response,
                                    true,
                                )),
                            TextEntry::make('sent_at')
                                ->label('وقت الطلب')
                                ->dateTime('Y-m-d h:i A')
                                ->placeholder('-'),
                            TextEntry::make('worker_responded_at')
                                ->label('وقت رد العامل')
                                ->dateTime('Y-m-d h:i A')
                                ->placeholder('بانتظار رد العامل'),
                            TextEntry::make('price_applied_at')
                                ->label('وقت إضافة الرسوم')
                                ->dateTime('Y-m-d h:i A')
                                ->placeholder('لم تتم إضافة الرسوم'),
                            TextEntry::make('worker_reject_message')
                                ->label('سبب رفض العامل')
                                ->placeholder('لا يوجد')
                                ->visible(fn (CleaningTimeWarning $record): bool => filled($record->worker_reject_message))
                                ->columnSpanFull(),
                        ])
                        ->columns([
                            'default' => 1,
                            'md' => 2,
                            'xl' => 3,
                        ]),
                ])
                ->columns([
                    'default' => 1,
                    'xl' => 3,
                ])
                ->visible(fn (CleaningBooking $record): bool => $record->timeWarnings->isNotEmpty())
                ->columnSpanFull(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view_dispute')
                ->label('عرض النزاع')
                ->url(fn () => $this->record->disputes()->first()
                    ? DisputeResource::getUrl('view', ['record' => $this->record->disputes()->first()])
                    : '#')
                ->visible(fn (): bool => $this->record->disputes()->exists()),
            EditAction::make()
                ->label('تعديل')
                ->visible(fn (): bool => CleaningBookingResource::canEdit($this->record)),
        ];
    }

    private static function reviewColor(int $rating): string
    {
        return match (true) {
            $rating >= 4 => 'success',
            $rating === 3 => 'warning',
            default => 'danger',
        };
    }

    private static function extensionStatusLabel(CleaningTimeWarning $warning): string
    {
        $workerResponse = self::enumValue($warning->worker_response);

        if ($warning->worker_responded_at !== null || $workerResponse !== null) {
            return match ($workerResponse) {
                CleaningTimeWarningResponse::ExtendTime->value => 'وافق العامل على التمديد',
                CleaningTimeWarningResponse::CommitCurrentTime->value => 'رفض العامل التمديد',
                default => 'تم الرد على الطلب',
            };
        }

        $bookingStatus = $warning->booking?->status;
        $bookingStatusValue = $bookingStatus instanceof CleaningBookingStatus
            ? $bookingStatus->value
            : (string) ($bookingStatus ?? '');

        if (in_array($bookingStatusValue, [
            CleaningBookingStatus::Completed->value,
            CleaningBookingStatus::Cancelled->value,
        ], true)) {
            return 'مغلق';
        }

        return self::enumValue($warning->customer_response) === CleaningTimeWarningResponse::ExtendTime->value
            ? 'بانتظار رد العامل'
            : 'قيد الانتظار';
    }

    private static function extensionStatusColor(CleaningTimeWarning $warning): string
    {
        $workerResponse = self::enumValue($warning->worker_response);

        return match ($workerResponse) {
            CleaningTimeWarningResponse::ExtendTime->value => 'success',
            CleaningTimeWarningResponse::CommitCurrentTime->value => 'danger',
            default => $warning->worker_responded_at !== null ? 'gray' : 'warning',
        };
    }

    private static function timeWarningResponseLabel(mixed $response, bool $workerResponse): string
    {
        $value = self::enumValue($response);

        if ($value === null) {
            return $workerResponse ? 'بانتظار رد العامل' : '-';
        }

        return match ($value) {
            CleaningTimeWarningResponse::ExtendTime->value => $workerResponse ? 'موافقة على التمديد' : 'طلب تمديد الوقت',
            CleaningTimeWarningResponse::CommitCurrentTime->value => $workerResponse ? 'رفض التمديد واعتماد الوقت الحالي' : 'اعتماد الوقت الحالي',
            CleaningTimeWarningResponse::FinishEarly->value => 'إنهاء العمل مبكراً',
            default => $value,
        };
    }

    private static function enumValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private static function money(mixed $amount, ?string $currency): string
    {
        if ($amount === null || $amount === '') {
            return '-';
        }

        $formatted = number_format((float) $amount, 0, '.', ',');
        $currency = strtoupper(trim((string) $currency));

        return $currency === '' || $currency === 'SYP'
            ? $formatted.' ل.س'
            : $formatted.' '.$currency;
    }
}
