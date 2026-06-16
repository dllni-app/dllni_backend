<?php

declare(strict_types=1);

namespace App\Filament\Resources\SosAlerts\Pages;

use App\Filament\Resources\SosAlerts\SosAlertResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use App\Enums\SOSStatus;

final class ViewSosAlert extends ViewRecord
{
    protected static string $resource = SosAlertResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('acknowledge')
                ->label('Acknowledge')
                ->icon('heroicon-o-hand-raised')
                ->color('warning')
                ->visible(fn (): bool => $this->record->status !== SOSStatus::Resolved)
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->forceFill([
                        'status' => SOSStatus::Acknowledged->value,
                        'acknowledged_at' => now(),
                        'acknowledged_by' => auth()->id(),
                    ])->save();

                    $this->refreshFormData([
                        'status',
                        'acknowledged_at',
                        'acknowledged_by',
                        'updated_at',
                    ]);

                    Notification::make()
                        ->title('SOS alert acknowledged')
                        ->success()
                        ->send();
                }),
            Action::make('resolve')
                ->label('Resolve')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->status !== SOSStatus::Resolved)
                ->requiresConfirmation()
                ->form([
                    Textarea::make('resolution_note')
                        ->label('Resolution note')
                        ->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    $this->record->forceFill([
                        'status' => SOSStatus::Resolved->value,
                        'resolved_at' => now(),
                        'resolved_by' => auth()->id(),
                        'resolution_note' => filled($data['resolution_note'] ?? null)
                            ? trim((string) $data['resolution_note'])
                            : null,
                    ])->save();

                    $this->refreshFormData([
                        'status',
                        'resolved_at',
                        'resolved_by',
                        'resolution_note',
                        'updated_at',
                    ]);

                    Notification::make()
                        ->title('SOS alert resolved')
                        ->success()
                        ->send();
                }),
        ];
    }
}
