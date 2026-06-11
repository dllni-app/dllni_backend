<?php

declare(strict_types=1);

namespace App\Filament\Resources\SystemAlerts\Tables;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\SystemAlertStatus;
use App\Support\BookingMorphTypeLabel;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class SystemAlertsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('alert_type')
                    ->label(__('cleaning_admin.system_alerts.fields.alert_type'))
                    ->badge()
                    ->color(fn ($state): string => self::alertTypeColor($state))
                    ->formatStateUsing(fn ($state): string => self::alertTypeLabel($state)),
                TextColumn::make('severity')
                    ->label(__('cleaning_admin.system_alerts.fields.severity'))
                    ->badge()
                    ->color(fn ($state): string => self::severityColor($state))
                    ->formatStateUsing(fn ($state): string => self::severityLabel($state)),
                TextColumn::make('status')
                    ->label(__('cleaning_admin.system_alerts.fields.status'))
                    ->badge()
                    ->color(fn ($state): string => self::statusColor($state))
                    ->formatStateUsing(fn ($state): string => self::statusLabel($state)),
                TextColumn::make('booking_type')
                    ->label(__('cleaning_admin.system_alerts.fields.booking_type'))
                    ->formatStateUsing(fn (?string $state): string => BookingMorphTypeLabel::resolve($state)),
                TextColumn::make('booking.customer.name')
                    ->label('User')
                    ->placeholder('-'),
                TextColumn::make('booking.order_number')
                    ->label('Order')
                    ->placeholder('-'),
                TextColumn::make('payload.message')
                    ->label('Message')
                    ->placeholder('-')
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label(__('cleaning_admin.system_alerts.fields.created_at'))
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('cleaning_admin.system_alerts.fields.status'))
                    ->options(collect(SystemAlertStatus::cases())->mapWithKeys(fn (SystemAlertStatus $case): array => [$case->value => $case->label()])->all()),
                SelectFilter::make('severity')
                    ->label(__('cleaning_admin.system_alerts.fields.severity'))
                    ->options(collect(AlertSeverity::cases())->mapWithKeys(fn (AlertSeverity $case): array => [$case->value => $case->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make()->label(__('cleaning_admin.shared.actions.view')),
                EditAction::make()->label(__('cleaning_admin.shared.actions.edit')),
            ]);
    }

    private static function alertTypeLabel(AlertType|string|null $type): string
    {
        $type = self::normalizeAlertType($type);

        return $type?->label() ?? '-';
    }

    private static function alertTypeColor(AlertType|string|null $type): string
    {
        return match (self::normalizeAlertType($type)) {
            AlertType::SOSTriggered => 'danger',
            AlertType::OverdueCompletion,
            AlertType::TimeExpired => 'warning',
            AlertType::FrozenGPS => 'info',
            default => 'gray',
        };
    }

    private static function severityLabel(AlertSeverity|string|null $severity): string
    {
        $severity = self::normalizeSeverity($severity);

        return $severity?->label() ?? '-';
    }

    private static function severityColor(AlertSeverity|string|null $severity): string
    {
        return match (self::normalizeSeverity($severity)) {
            AlertSeverity::Critical => 'danger',
            AlertSeverity::High => 'warning',
            AlertSeverity::Medium => 'info',
            AlertSeverity::Low => 'gray',
            default => 'gray',
        };
    }

    private static function statusLabel(SystemAlertStatus|string|null $status): string
    {
        $status = self::normalizeStatus($status);

        return $status?->label() ?? '-';
    }

    private static function statusColor(SystemAlertStatus|string|null $status): string
    {
        return match (self::normalizeStatus($status)) {
            SystemAlertStatus::New => 'warning',
            SystemAlertStatus::Acknowledged => 'info',
            SystemAlertStatus::Resolved => 'success',
            default => 'gray',
        };
    }

    private static function normalizeAlertType(AlertType|string|null $type): ?AlertType
    {
        if ($type instanceof AlertType || $type === null) {
            return $type;
        }

        return AlertType::tryFrom($type);
    }

    private static function normalizeSeverity(AlertSeverity|string|null $severity): ?AlertSeverity
    {
        if ($severity instanceof AlertSeverity || $severity === null) {
            return $severity;
        }

        return AlertSeverity::tryFrom($severity);
    }

    private static function normalizeStatus(SystemAlertStatus|string|null $status): ?SystemAlertStatus
    {
        if ($status instanceof SystemAlertStatus || $status === null) {
            return $status;
        }

        return SystemAlertStatus::tryFrom($status);
    }
}
