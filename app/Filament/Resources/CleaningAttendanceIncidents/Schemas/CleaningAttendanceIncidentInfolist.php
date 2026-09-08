<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningAttendanceIncidents\Schemas;

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Filament\Resources\CleaningWorkers\CleaningWorkerResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

final class CleaningAttendanceIncidentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('ملخص البلاغ')
                ->description('حالة بلاغ التأخر أو عدم التوجه كما سجلها العميل، بدون أي تعديل يدوي على الحالة التشغيلية.')
                ->schema([
                    TextEntry::make('incident_type')
                        ->label('نوع البلاغ')
                        ->state(fn (CleaningBookingSessionWorkerAssignment $record): string => $record->no_travel_reported_at !== null ? 'عدم التوجه' : 'تأخر')
                        ->badge()
                        ->color(fn (CleaningBookingSessionWorkerAssignment $record): string => $record->no_travel_reported_at !== null ? 'danger' : 'warning'),
                    TextEntry::make('resolution_state')
                        ->label('حالة المعالجة')
                        ->state(fn (CleaningBookingSessionWorkerAssignment $record): string => $record->attendance_resolved_at !== null ? 'تمت المعالجة' : 'غير معالجة')
                        ->badge()
                        ->color(fn (CleaningBookingSessionWorkerAssignment $record): string => $record->attendance_resolved_at !== null ? 'success' : 'danger'),
                    TextEntry::make('attendance_action')
                        ->label('قرار العميل')
                        ->formatStateUsing(fn (?string $state): string => self::actionLabel($state))
                        ->badge()
                        ->color(fn (?string $state): string => self::actionColor($state))
                        ->placeholder('لم يحدد إجراء'),
                    TextEntry::make('late_reported_at')
                        ->label('وقت بلاغ التأخر')
                        ->dateTime('Y-m-d H:i')
                        ->placeholder('-'),
                    TextEntry::make('no_travel_reported_at')
                        ->label('وقت بلاغ عدم التوجه')
                        ->dateTime('Y-m-d H:i')
                        ->placeholder('-'),
                    TextEntry::make('attendance_resolved_at')
                        ->label('وقت المعالجة')
                        ->dateTime('Y-m-d H:i')
                        ->placeholder('-'),
                    TextEntry::make('attendance_note')
                        ->label('ملاحظة العميل')
                        ->placeholder('لا توجد ملاحظة')
                        ->columnSpanFull(),
                ])
                ->columns(3),

            Section::make('الطلب والزيارة')
                ->schema([
                    TextEntry::make('booking_reference')
                        ->label('طلب التنظيف')
                        ->state(fn (CleaningBookingSessionWorkerAssignment $record): string => self::bookingReference($record))
                        ->url(fn (CleaningBookingSessionWorkerAssignment $record): ?string => $record->session?->booking !== null
                            ? CleaningBookingResource::getUrl('view', ['record' => $record->session->booking])
                            : null),
                    TextEntry::make('session.sequence')
                        ->label('رقم الزيارة')
                        ->formatStateUsing(fn (mixed $state): string => $state !== null ? 'زيارة '.(int) $state : '-'),
                    TextEntry::make('session.scheduled_date')
                        ->label('تاريخ الزيارة')
                        ->date('Y-m-d')
                        ->placeholder('-'),
                    TextEntry::make('session.scheduled_time')
                        ->label('وقت الزيارة')
                        ->placeholder('-'),
                    TextEntry::make('session.status')
                        ->label('حالة الزيارة')
                        ->formatStateUsing(fn (mixed $state): string => self::enumValue($state))
                        ->badge(),
                    TextEntry::make('session.coverage_status')
                        ->label('حالة التغطية')
                        ->formatStateUsing(fn (mixed $state): string => self::enumValue($state))
                        ->badge(),
                ])
                ->columns(3),

            Section::make('العامل والتنفيذ')
                ->schema([
                    TextEntry::make('worker_name')
                        ->label('العامل')
                        ->state(fn (CleaningBookingSessionWorkerAssignment $record): string => self::workerName($record))
                        ->url(fn (CleaningBookingSessionWorkerAssignment $record): ?string => $record->worker !== null
                            ? CleaningWorkerResource::getUrl('view', ['record' => $record->worker])
                            : null),
                    TextEntry::make('worker.user.phone')
                        ->label('رقم العامل')
                        ->placeholder('-')
                        ->copyable(),
                    TextEntry::make('status')
                        ->label('حالة إسناد العامل')
                        ->formatStateUsing(fn (mixed $state): string => self::enumValue($state))
                        ->badge(),
                    TextEntry::make('started_travel_at')
                        ->label('بدأ التوجه')
                        ->dateTime('Y-m-d H:i')
                        ->placeholder('-'),
                    TextEntry::make('arrived_at')
                        ->label('وصل إلى العميل')
                        ->dateTime('Y-m-d H:i')
                        ->placeholder('-'),
                    TextEntry::make('released_reason')
                        ->label('سبب تحرير الإسناد')
                        ->placeholder('-')
                        ->columnSpanFull(),
                ])
                ->columns(3),
        ]);
    }

    private static function bookingReference(CleaningBookingSessionWorkerAssignment $record): string
    {
        $booking = $record->session?->booking;
        if ($booking === null) {
            return '-';
        }

        return filled($booking->booking_number)
            ? (string) $booking->booking_number
            : '#'.(int) $booking->id;
    }

    private static function workerName(CleaningBookingSessionWorkerAssignment $record): string
    {
        return (string) ($record->worker?->user?->name ?: $record->worker?->first_name ?: '-');
    }

    private static function actionLabel(?string $action): string
    {
        return match ($action) {
            'wait' => 'اختار العميل الانتظار',
            'replace' => 'طلب العميل استبدال العامل',
            'cancel' => 'ألغى العميل الزيارة بدون رسوم',
            default => '-',
        };
    }

    private static function actionColor(?string $action): string
    {
        return match ($action) {
            'wait' => 'warning',
            'replace' => 'info',
            'cancel' => 'danger',
            default => 'gray',
        };
    }

    private static function enumValue(mixed $state): string
    {
        if ($state instanceof \BackedEnum) {
            return (string) $state->value;
        }

        return filled($state) ? (string) $state : '-';
    }
}
