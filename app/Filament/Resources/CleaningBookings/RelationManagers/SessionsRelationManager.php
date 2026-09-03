<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Models\CleaningBookingSession;

final class SessionsRelationManager extends RelationManager
{
    protected static string $relationship = 'sessions';

    protected static ?string $title = 'أيام / جلسات التنفيذ';

    protected static ?string $modelLabel = 'جلسة تنفيذ';

    protected static ?string $pluralModelLabel = 'جلسات التنفيذ';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['workerAssignments.worker.user'])
                ->orderBy('sequence'))
            ->columns([
                TextColumn::make('sequence')
                    ->label('اليوم')
                    ->formatStateUsing(fn ($state): string => 'اليوم '.(int) $state)
                    ->badge()
                    ->color('info'),
                TextColumn::make('scheduled_date')
                    ->label('التاريخ')
                    ->date('Y-m-d')
                    ->sortable(),
                TextColumn::make('scheduled_time')
                    ->label('وقت البدء')
                    ->formatStateUsing(fn ($state): string => self::timeLabel((string) $state)),
                TextColumn::make('duration_hours')
                    ->label('المدة')
                    ->formatStateUsing(fn ($state): string => self::hoursLabel((float) $state)),
                TextColumn::make('status')
                    ->label('حالة اليوم')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::statusLabel($state))
                    ->color(fn ($state): string => self::statusColor($state)),
                TextColumn::make('coverage_status')
                    ->label('تغطية العمال')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::coverageLabel($state))
                    ->color(fn ($state): string => self::coverageColor($state)),
                TextColumn::make('workers')
                    ->label('العمال')
                    ->getStateUsing(fn (CleaningBookingSession $record): array => $record->workerAssignments
                        ->filter(fn ($assignment): bool => $assignment->isAccepted())
                        ->map(fn ($assignment): string => (string) ($assignment->worker?->user?->name ?? 'عامل #'.$assignment->worker_id))
                        ->unique()
                        ->values()
                        ->all())
                    ->badge()
                    ->color('success')
                    ->placeholder('-'),
                TextColumn::make('work_started_at')
                    ->label('بدأ العمل')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('work_finished_at')
                    ->label('انتهى العمل')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('total_price')
                    ->label('سعر اليوم')
                    ->formatStateUsing(fn ($state): string => self::money((float) $state))
                    ->weight('bold'),
                TextColumn::make('travel_fee')
                    ->label('النقل')
                    ->formatStateUsing(fn ($state): string => self::money((float) $state))
                    ->toggleable(),
                TextColumn::make('admin_margin_amount')
                    ->label('حصة الإدارة')
                    ->formatStateUsing(fn ($state): string => self::money((float) $state))
                    ->toggleable(),
                TextColumn::make('worker_entitlements')
                    ->label('مستحقات العمال')
                    ->getStateUsing(fn (CleaningBookingSession $record): float => (float) $record->workerAssignments
                        ->filter(fn ($assignment): bool => $assignment->isAccepted())
                        ->sum('worker_amount'))
                    ->formatStateUsing(fn ($state): string => self::money((float) $state))
                    ->toggleable(),
                TextColumn::make('extension_requested_minutes')
                    ->label('التمديد')
                    ->formatStateUsing(fn ($state): string => $state !== null ? (int) $state.' دقيقة' : '-')
                    ->toggleable(),
                TextColumn::make('cancellation_fee')
                    ->label('رسوم الإلغاء')
                    ->formatStateUsing(fn ($state): string => self::money((float) $state))
                    ->toggleable(),
                TextColumn::make('cancellation_reason')
                    ->label('سبب الإلغاء')
                    ->placeholder('-')
                    ->wrap()
                    ->toggleable(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    private static function statusLabel(mixed $state): string
    {
        if ($state instanceof CleaningBookingSessionStatus) {
            return $state->label();
        }

        return CleaningBookingSessionStatus::tryFrom((string) $state)?->label() ?? (string) $state;
    }

    private static function statusColor(mixed $state): string
    {
        $value = $state instanceof CleaningBookingSessionStatus ? $state->value : (string) $state;

        return match ($value) {
            'completed' => 'success',
            'cancelled', 'under_dispute' => 'danger',
            'in_progress', 'time_extension_requested' => 'primary',
            'worker_assigned', 'awaiting_start_verification', 'awaiting_worker_start_confirmation', 'awaiting_customer_completion' => 'info',
            default => 'gray',
        };
    }

    private static function coverageLabel(mixed $state): string
    {
        if ($state instanceof CleaningBookingSessionCoverageStatus) {
            return $state->label();
        }

        return CleaningBookingSessionCoverageStatus::tryFrom((string) $state)?->label() ?? (string) $state;
    }

    private static function coverageColor(mixed $state): string
    {
        $value = $state instanceof CleaningBookingSessionCoverageStatus ? $state->value : (string) $state;

        return match ($value) {
            'fully_covered' => 'success',
            'partially_covered' => 'warning',
            default => 'gray',
        };
    }

    private static function timeLabel(string $value): string
    {
        $time = mb_trim($value);
        if ($time === '') {
            return '-';
        }

        $parts = explode(':', $time);
        $hour = (int) ($parts[0] ?? 0);
        $minute = (int) ($parts[1] ?? 0);
        $suffix = $hour >= 12 ? 'م' : 'ص';
        $displayHour = $hour % 12;
        if ($displayHour === 0) {
            $displayHour = 12;
        }

        return sprintf('%d:%02d %s', $displayHour, $minute, $suffix);
    }

    private static function hoursLabel(float $hours): string
    {
        return mb_rtrim(mb_rtrim(number_format($hours, 2, '.', ''), '0'), '.').' ساعة';
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 0, '.', ',').' '.config('app.currency', 'SYP');
    }
}
