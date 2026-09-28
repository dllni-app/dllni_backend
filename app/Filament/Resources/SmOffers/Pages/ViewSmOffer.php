<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOffers\Pages;

use App\Filament\Resources\SmOffers\SmOfferResource;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Modules\Supermarket\Models\SmOffer;

final class ViewSmOffer extends ViewRecord
{
    protected static string $resource = SmOfferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('moderate_offer')
                ->label('تدخل إداري على العرض')
                ->visible(fn (): bool => SmOfferResource::canModerate())
                ->fillForm(fn (): array => [
                    'is_active' => (bool) $this->record->is_active,
                    'starts_at' => $this->record->starts_at,
                    'ends_at' => $this->record->ends_at,
                ])
                ->form([
                    Toggle::make('is_active')->label('فعال'),
                    DateTimePicker::make('starts_at')->label('يبدأ في')->nullable(),
                    DateTimePicker::make('ends_at')->label('ينتهي في')->nullable()->rules(['nullable', 'date', 'after_or_equal:starts_at']),
                    Textarea::make('reason')->label('سبب التدخل الإداري')->required()->maxLength(1000),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    /** @var SmOffer $record */
                    $record = $this->record;
                    $old = $record->only(['is_active', 'starts_at', 'ends_at']);
                    $record->forceFill([
                        'is_active' => (bool) $data['is_active'],
                        'starts_at' => $data['starts_at'] ?? null,
                        'ends_at' => $data['ends_at'] ?? null,
                    ])->save();

                    activity('supermarket_admin_overrides')
                        ->causedBy(auth()->user())->performedOn($record)
                        ->withProperties(['old' => $old, 'new' => $record->only(['is_active', 'starts_at', 'ends_at']), 'reason' => $data['reason']])
                        ->log('supermarket_offer_moderation_override');

                    Notification::make()->title('تم تطبيق التدخل الإداري على العرض')->success()->send();
                }),
        ];
    }
}
