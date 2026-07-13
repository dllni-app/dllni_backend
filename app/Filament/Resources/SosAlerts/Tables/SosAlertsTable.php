<?php

declare(strict_types=1);

namespace App\Filament\Resources\SosAlerts\Tables;

use App\Enums\EmergencyType;
use App\Enums\SOSStatus;
use App\Enums\UserModuleType;
use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\SosAlert;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Models\CleaningBooking;

final class SosAlertsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('المعرّف')
                    ->sortable(),
                TextColumn::make('booking.booking_number')
                    ->label('طلب التنظيف')
                    ->placeholder('-')
                    ->url(fn (SosAlert $record): ?string => $record->booking instanceof CleaningBooking
                        ? CleaningBookingResource::getUrl('view', ['record' => $record->booking])
                        : null),
                TextColumn::make('source_app')
                    ->label('التطبيق المرسل')
                    ->badge()
                    ->state(fn (SosAlert $record): string => self::sourceAppLabel($record))
                    ->color(fn (SosAlert $record): string => self::sourceAppColor($record)),
                TextColumn::make('user.name')
                    ->label('صاحب البلاغ')
                    ->placeholder('-')
                    ->url(fn (SosAlert $record): ?string => $record->user instanceof \App\Models\User
                        ? UserResource::getUrl('view', ['record' => $record->user])
                        : null),
                TextColumn::make('reporter_role')
                    ->label('صفة المُبلِّغ')
                    ->badge()
                    ->state(fn (SosAlert $record): string => self::roleLabel($record))
                    ->color(fn (SosAlert $record): string => self::roleColor($record)),
                TextColumn::make('emergency_type')
                    ->label('نوع البلاغ')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => self::emergencyLabel($state))
                    ->color(fn (mixed $state): string => self::emergencyColor($state)),
                TextColumn::make('message')
                    ->label('الرسالة')
                    ->limit(60)
                    ->wrap()
                    ->tooltip(fn (SosAlert $record): ?string => $record->message),
                TextColumn::make('status')
                    ->badge()
                    ->label('الحالة')
                    ->color(fn (mixed $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (mixed $state): string => self::statusLabel($state)),
                TextColumn::make('triggered_at')
                    ->label('وقت الإرسال')
                    ->since()
                    ->sortable(),
                TextColumn::make('acknowledged_at')
                    ->label('وقت الاستلام')
                    ->since()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('resolved_at')
                    ->label('وقت الحل')
                    ->since()
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'booking']))
            ->filters([
                SelectFilter::make('source_app')
                    ->label('التطبيق المرسل')
                    ->options([
                        'dllni_user_app' => 'تطبيق المستخدم',
                        'cleaning_owner_app' => 'تطبيق عامل التنظيف',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $source = $data['value'] ?? null;

                        if ($source === 'cleaning_owner_app') {
                            return $query->where(function (Builder $query): void {
                                $query->where('source', 'cleaning_owner_app')
                                    ->orWhere(function (Builder $query): void {
                                        $query->where('source', 'booking')
                                            ->whereHas('user', fn (Builder $userQuery): Builder => $userQuery->where('module_type', UserModuleType::CleaningWorker->value));
                                    });
                            });
                        }

                        if ($source === 'dllni_user_app') {
                            return $query->where(function (Builder $query): void {
                                $query->where('source', 'dllni_user_app')
                                    ->orWhere(function (Builder $query): void {
                                        $query->where('source', 'booking')
                                            ->whereHas('user', fn (Builder $userQuery): Builder => $userQuery
                                                ->whereNull('module_type')
                                                ->orWhere('module_type', '!=', UserModuleType::CleaningWorker->value));
                                    });
                            });
                        }

                        return $query;
                    }),
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(collect(SOSStatus::cases())->mapWithKeys(fn (SOSStatus $case): array => [$case->value => self::statusLabel($case)])->all()),
                SelectFilter::make('emergency_type')
                    ->label('نوع البلاغ')
                    ->options(collect(EmergencyType::cases())->mapWithKeys(fn (EmergencyType $case): array => [$case->value => self::emergencyLabel($case)])->all()),
                Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('من'),
                        \Filament\Forms\Components\DatePicker::make('to')->label('إلى'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                            ->when($data['to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                ViewAction::make()->label('عرض'),
                Action::make('acknowledge')
                    ->label('استلام')
                    ->icon('heroicon-o-hand-raised')
                    ->color('warning')
                    ->visible(fn (SosAlert $record): bool => self::isPending($record->status))
                    ->requiresConfirmation()
                    ->action(function (SosAlert $record): void {
                        $record->forceFill([
                            'status' => SOSStatus::Acknowledged->value,
                            'acknowledged_at' => now(),
                            'acknowledged_by' => auth()->id(),
                        ])->save();

                        Notification::make()
                            ->title('تم استلام البلاغ')
                            ->success()
                            ->send();
                    }),
                Action::make('resolve')
                    ->label('حل')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (SosAlert $record): bool => ! self::isResolved($record->status))
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('resolution_note')
                            ->label('ملاحظة الحل')
                            ->maxLength(1000),
                    ])
                    ->action(function (SosAlert $record, array $data): void {
                        $record->forceFill([
                            'status' => SOSStatus::Resolved->value,
                            'resolved_at' => now(),
                            'resolved_by' => auth()->id(),
                            'resolution_note' => filled($data['resolution_note'] ?? null)
                                ? trim((string) $data['resolution_note'])
                                : null,
                        ])->save();

                        Notification::make()
                            ->title('تم حل البلاغ')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private static function statusLabel(SOSStatus|string|null $status): string
    {
        return match (self::normalizeStatus($status)) {
            SOSStatus::Pending => 'قيد الانتظار',
            SOSStatus::Triggered => 'جديد',
            SOSStatus::Acknowledged => 'تم الاستلام',
            SOSStatus::Resolved => 'تم الحل',
            default => '-',
        };
    }

    private static function statusColor(SOSStatus|string|null $status): string
    {
        return match (self::normalizeStatus($status)) {
            SOSStatus::Pending => 'warning',
            SOSStatus::Acknowledged => 'info',
            SOSStatus::Resolved => 'success',
            SOSStatus::Triggered => 'danger',
            default => 'gray',
        };
    }

    private static function normalizeStatus(SOSStatus|string|null $status): ?SOSStatus
    {
        if ($status instanceof SOSStatus || $status === null) {
            return $status;
        }

        return SOSStatus::tryFrom($status);
    }

    private static function isPending(SOSStatus|string|null $status): bool
    {
        return in_array(self::normalizeStatus($status), [SOSStatus::Pending, SOSStatus::Triggered], true);
    }

    private static function isResolved(SOSStatus|string|null $status): bool
    {
        return self::normalizeStatus($status) === SOSStatus::Resolved;
    }

    public static function sourceAppLabel(SosAlert $record): string
    {
        if ($record->source === 'cleaning_owner_app') {
            return 'تطبيق عامل التنظيف';
        }

        if ($record->source === 'dllni_user_app') {
            return 'تطبيق المستخدم';
        }

        return $record->user?->module_type === UserModuleType::CleaningWorker
            ? 'تطبيق عامل التنظيف'
            : 'تطبيق المستخدم';
    }

    public static function sourceAppColor(SosAlert $record): string
    {
        return self::sourceAppLabel($record) === 'تطبيق عامل التنظيف' ? 'info' : 'gray';
    }

    public static function roleLabel(SosAlert $record): string
    {
        return $record->user?->module_type === UserModuleType::CleaningWorker
            ? 'عامل تنظيف'
            : 'مستخدم';
    }

    public static function roleColor(SosAlert $record): string
    {
        return $record->user?->module_type === UserModuleType::CleaningWorker
            ? 'info'
            : 'gray';
    }

    public static function emergencyLabel(EmergencyType|string|null $type): string
    {
        $type = $type instanceof EmergencyType ? $type : ($type !== null ? EmergencyType::tryFrom((string) $type) : null);

        return match ($type) {
            EmergencyType::SafetyThreat => 'تهديد للسلامة',
            EmergencyType::MedicalEmergency => 'حالة طبية طارئة',
            EmergencyType::SevereConflict => 'نزاع أو شكوى عاجلة',
            default => '-',
        };
    }

    public static function emergencyColor(EmergencyType|string|null $type): string
    {
        $type = $type instanceof EmergencyType ? $type : ($type !== null ? EmergencyType::tryFrom((string) $type) : null);

        return match ($type) {
            EmergencyType::SafetyThreat => 'danger',
            EmergencyType::MedicalEmergency => 'warning',
            EmergencyType::SevereConflict => 'primary',
            default => 'gray',
        };
    }
}
