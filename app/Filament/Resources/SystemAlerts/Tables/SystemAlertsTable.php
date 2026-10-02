<?php

declare(strict_types=1);

namespace App\Filament\Resources\SystemAlerts\Tables;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\SystemAlertStatus;
use App\Filament\Resources\SystemAlerts\SystemAlertResource;
use App\Models\SystemAlert;
use App\Support\BookingMorphTypeLabel;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
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
                    ->label(__('cleaning_admin.system_alerts.fields.user'))
                    ->placeholder('-'),
                TextColumn::make('booking.order_number')
                    ->label(__('cleaning_admin.system_alerts.fields.order'))
                    ->placeholder('-'),
                TextColumn::make('payload.message')
                    ->label(__('cleaning_admin.system_alerts.fields.message'))
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
                Action::make('acknowledge')
                    ->label('استلام')
                    ->color('warning')
                    ->visible(fn (SystemAlert $record): bool => SystemAlertResource::canUpdateAlerts() && $record->status === SystemAlertStatus::New)
                    ->requiresConfirmation()
                    ->action(function (SystemAlert $record): void {
                        $before=$record->status?->value;
                        $record->update([
                            'status'=>SystemAlertStatus::Acknowledged->value,
                            'acknowledged_at'=>now(),
                            'acknowledged_by'=>auth()->id(),
                        ]);
                        activity('system_alerts')->performedOn($record)->causedBy(auth()->user())->withProperties(['from'=>$before,'to'=>'acknowledged'])->log('system_alert_acknowledged');
                        Notification::make()->title('تم استلام التنبيه')->success()->send();
                    }),
                Action::make('resolve')
                    ->label('حل')
                    ->color('success')
                    ->visible(fn (SystemAlert $record): bool => SystemAlertResource::canUpdateAlerts() && $record->status !== SystemAlertStatus::Resolved)
                    ->form([Textarea::make('resolution_note')->label('ملاحظة الحل')->required()->maxLength(2000)])
                    ->requiresConfirmation()
                    ->action(function (SystemAlert $record, array $data): void {
                        $before=$record->status?->value;
                        $record->update([
                            'status'=>SystemAlertStatus::Resolved->value,
                            'resolved_at'=>now(),
                            'resolved_by'=>auth()->id(),
                            'resolution_note'=>trim((string)$data['resolution_note']),
                        ]);
                        activity('system_alerts')->performedOn($record)->causedBy(auth()->user())->withProperties(['from'=>$before,'to'=>'resolved','note'=>$data['resolution_note']])->log('system_alert_resolved');
                        Notification::make()->title('تم حل التنبيه')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private static function alertTypeLabel(AlertType|string|null $type): string
    {
        $type = $type instanceof AlertType ? $type : ($type ? AlertType::tryFrom($type) : null);
        return $type?->label() ?? '-';
    }

    private static function alertTypeColor(AlertType|string|null $type): string
    {
        $type = $type instanceof AlertType ? $type : ($type ? AlertType::tryFrom($type) : null);
        return match ($type) {
            AlertType::SOSTriggered,
            AlertType::DeliveryDispatchExhausted,
            AlertType::StaleDriverLocation => 'danger',
            AlertType::OverdueCompletion,
            AlertType::TimeExpired,
            AlertType::StalledProgress,
            AlertType::ReadyPickupOverdue => 'warning',
            AlertType::FrozenGPS => 'info',
            default => 'gray',
        };
    }

    private static function severityLabel(AlertSeverity|string|null $severity): string
    {
        $severity = $severity instanceof AlertSeverity ? $severity : ($severity ? AlertSeverity::tryFrom($severity) : null);
        return $severity?->label() ?? '-';
    }

    private static function severityColor(AlertSeverity|string|null $severity): string
    {
        $severity = $severity instanceof AlertSeverity ? $severity : ($severity ? AlertSeverity::tryFrom($severity) : null);
        return match ($severity) {
            AlertSeverity::Critical => 'danger',
            AlertSeverity::High => 'warning',
            AlertSeverity::Medium => 'info',
            default => 'gray',
        };
    }

    private static function statusLabel(SystemAlertStatus|string|null $status): string
    {
        $status = $status instanceof SystemAlertStatus ? $status : ($status ? SystemAlertStatus::tryFrom($status) : null);
        return $status?->label() ?? '-';
    }

    private static function statusColor(SystemAlertStatus|string|null $status): string
    {
        $status = $status instanceof SystemAlertStatus ? $status : ($status ? SystemAlertStatus::tryFrom($status) : null);
        return match ($status) {
            SystemAlertStatus::New => 'warning',
            SystemAlertStatus::Acknowledged => 'info',
            SystemAlertStatus::Resolved => 'success',
            default => 'gray',
        };
    }
}
