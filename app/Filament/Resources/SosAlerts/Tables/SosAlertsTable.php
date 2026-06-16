<?php

declare(strict_types=1);

namespace App\Filament\Resources\SosAlerts\Tables;

use App\Enums\SOSStatus;
use App\Filament\Resources\Orders\OrderResource;
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

final class SosAlertsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('order.order_number')
                    ->label('Order')
                    ->placeholder('-')
                    ->url(fn (SosAlert $record): ?string => $record->order instanceof \Modules\Resturants\Models\Order
                        ? OrderResource::getUrl('view', ['record' => $record->order])
                        : null),
                TextColumn::make('user.name')
                    ->label('User')
                    ->placeholder('-')
                    ->url(fn (SosAlert $record): ?string => $record->user instanceof \App\Models\User
                        ? UserResource::getUrl('view', ['record' => $record->user])
                        : null),
                TextColumn::make('message')
                    ->label('Message preview')
                    ->limit(60)
                    ->wrap()
                    ->tooltip(fn (SosAlert $record): ?string => $record->message),
                TextColumn::make('status')
                    ->badge()
                    ->label('Status')
                    ->color(fn (mixed $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (mixed $state): string => self::statusLabel($state)),
                TextColumn::make('triggered_at')
                    ->label('Triggered at')
                    ->since()
                    ->sortable(),
                TextColumn::make('acknowledged_at')
                    ->label('Acknowledged at')
                    ->since()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('resolved_at')
                    ->label('Resolved at')
                    ->since()
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'order']))
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(SOSStatus::cases())->mapWithKeys(fn (SOSStatus $case): array => [$case->value => self::statusLabel($case)])->all()),
                Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('From'),
                        \Filament\Forms\Components\DatePicker::make('to')->label('To'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                            ->when($data['to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('acknowledge')
                    ->label('Acknowledge')
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
                            ->title('SOS alert acknowledged')
                            ->success()
                            ->send();
                    }),
                Action::make('resolve')
                    ->label('Resolve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (SosAlert $record): bool => ! self::isResolved($record->status))
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('resolution_note')
                            ->label('Resolution note')
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
                            ->title('SOS alert resolved')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private static function statusLabel(SOSStatus|string|null $status): string
    {
        $status = self::normalizeStatus($status);

        return $status?->value ?? '-';
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
        return self::normalizeStatus($status) === SOSStatus::Pending;
    }

    private static function isResolved(SOSStatus|string|null $status): bool
    {
        return self::normalizeStatus($status) === SOSStatus::Resolved;
    }
}
