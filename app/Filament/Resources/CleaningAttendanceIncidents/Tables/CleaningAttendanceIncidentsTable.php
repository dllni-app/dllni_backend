<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningAttendanceIncidents\Tables;

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

final class CleaningAttendanceIncidentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('المعرّف')
                    ->sortable(),
                TextColumn::make('booking_reference')
                    ->label('طلب التنظيف')
                    ->state(fn (CleaningBookingSessionWorkerAssignment $record): string => self::bookingReference($record))
                    ->url(fn (CleaningBookingSessionWorkerAssignment $record): ?string => $record->session?->booking !== null
                        ? CleaningBookingResource::getUrl('view', ['record' => $record->session->booking])
                        : null),
                TextColumn::make('session.sequence')
                    ->label('الزيارة')
                    ->formatStateUsing(fn (mixed $state): string => $state !== null ? 'زيارة '.(int) $state : '-')
                    ->sortable(),
                TextColumn::make('worker_name')
                    ->label('العامل')
                    ->state(fn (CleaningBookingSessionWorkerAssignment $record): string => self::workerName($record))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('worker', function (Builder $worker) use ($search): void {
                            $worker
                                ->where('first_name', 'like', "%{$search}%")
                                ->orWhereHas('user', fn (Builder $user): Builder => $user->where('name', 'like', "%{$search}%"));
                        });
                    }),
                TextColumn::make('incident_type')
                    ->label('نوع البلاغ')
                    ->state(fn (CleaningBookingSessionWorkerAssignment $record): string => self::incidentTypeLabel($record))
                    ->badge()
                    ->color(fn (CleaningBookingSessionWorkerAssignment $record): string => $record->no_travel_reported_at !== null ? 'danger' : 'warning'),
                TextColumn::make('attendance_action')
                    ->label('قرار العميل')
                    ->formatStateUsing(fn (?string $state): string => self::actionLabel($state))
                    ->badge()
                    ->color(fn (?string $state): string => self::actionColor($state))
                    ->placeholder('لم يحدد إجراء'),
                TextColumn::make('resolution_state')
                    ->label('الحالة')
                    ->state(fn (CleaningBookingSessionWorkerAssignment $record): string => $record->attendance_resolved_at !== null ? 'تمت المعالجة' : 'غير معالجة')
                    ->badge()
                    ->color(fn (CleaningBookingSessionWorkerAssignment $record): string => $record->attendance_resolved_at !== null ? 'success' : 'danger'),
                TextColumn::make('reported_at')
                    ->label('وقت البلاغ')
                    ->state(fn (CleaningBookingSessionWorkerAssignment $record): mixed => $record->no_travel_reported_at ?? $record->late_reported_at)
                    ->dateTime('Y-m-d H:i')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw("COALESCE(no_travel_reported_at, late_reported_at) {$direction}")),
                TextColumn::make('attendance_resolved_at')
                    ->label('وقت المعالجة')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('incident_type')
                    ->label('نوع البلاغ')
                    ->options([
                        'late' => 'تأخر',
                        'no_travel' => 'عدم التوجه',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'late' => $query
                                ->whereNotNull('late_reported_at')
                                ->whereNull('no_travel_reported_at'),
                            'no_travel' => $query->whereNotNull('no_travel_reported_at'),
                            default => $query,
                        };
                    }),
                TernaryFilter::make('resolved')
                    ->label('حالة المعالجة')
                    ->placeholder('الكل')
                    ->trueLabel('تمت المعالجة')
                    ->falseLabel('غير معالجة')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('attendance_resolved_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('attendance_resolved_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                SelectFilter::make('worker_id')
                    ->label('العامل')
                    ->relationship('worker', 'first_name')
                    ->searchable()
                    ->preload(),
                Filter::make('reported_date')
                    ->label('تاريخ البلاغ')
                    ->form([
                        DatePicker::make('from')->label('من')->native(false),
                        DatePicker::make('to')->label('إلى')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $query, string $date): Builder => self::applyReportedDateBoundary($query, '>=', $date),
                            )
                            ->when(
                                $data['to'] ?? null,
                                fn (Builder $query, string $date): Builder => self::applyReportedDateBoundary($query, '<=', $date),
                            );
                    }),
            ])
            ->persistFiltersInSession()
            ->recordActions([
                ViewAction::make()->label('عرض'),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    private static function applyReportedDateBoundary(Builder $query, string $operator, string $date): Builder
    {
        return $query->where(function (Builder $reported) use ($operator, $date): void {
            $reported
                ->whereDate('no_travel_reported_at', $operator, $date)
                ->orWhere(function (Builder $lateOnly) use ($operator, $date): void {
                    $lateOnly
                        ->whereNull('no_travel_reported_at')
                        ->whereDate('late_reported_at', $operator, $date);
                });
        });
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

    private static function incidentTypeLabel(CleaningBookingSessionWorkerAssignment $record): string
    {
        return $record->no_travel_reported_at !== null ? 'عدم التوجه' : 'تأخر';
    }

    private static function actionLabel(?string $action): string
    {
        return match ($action) {
            'wait' => 'انتظار',
            'replace' => 'استبدال العامل',
            'cancel' => 'إلغاء الزيارة بدون رسوم',
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
}
