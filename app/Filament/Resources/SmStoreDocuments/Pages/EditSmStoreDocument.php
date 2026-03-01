<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStoreDocuments\Pages;

use App\Filament\Resources\SmStoreDocuments\SmStoreDocumentResource;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

final class EditSmStoreDocument extends EditRecord
{
    protected static string $resource = SmStoreDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label(__('supermarket_admin.form.approve'))
                ->color('success')
                ->icon('heroicon-o-check')
                ->visible(fn () => $this->record->verification_status !== 'approved')
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update([
                        'verification_status' => 'approved',
                        'verified_at' => now(),
                        'verified_by_user_id' => auth()->id(),
                        'rejection_reason' => null,
                    ]);
                    Notification::make()
                        ->title(__('supermarket_admin.notifications.document_approved'))
                        ->success()
                        ->send();
                    $this->redirect(static::getUrl(['record' => $this->record]));
                }),
            Action::make('reject')
                ->label(__('supermarket_admin.form.reject'))
                ->color('danger')
                ->icon('heroicon-o-x-mark')
                ->visible(fn () => $this->record->verification_status !== 'rejected')
                ->form([
                    Textarea::make('rejection_reason')
                        ->label(__('supermarket_admin.form.rejection_reason'))
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    $this->record->update([
                        'verification_status' => 'rejected',
                        'verified_at' => now(),
                        'verified_by_user_id' => auth()->id(),
                        'rejection_reason' => $data['rejection_reason'],
                    ]);
                    Notification::make()
                        ->title(__('supermarket_admin.notifications.document_rejected'))
                        ->success()
                        ->send();
                    $this->redirect(static::getUrl(['record' => $this->record]));
                }),
            ViewAction::make(),
        ];
    }
}
