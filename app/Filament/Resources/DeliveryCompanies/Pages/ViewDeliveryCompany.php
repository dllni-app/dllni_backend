<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryCompanies\Pages;

use App\Filament\Resources\DeliveryCompanies\DeliveryCompanyResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Services\DeliveryCollectionService;
use Modules\Delivery\Services\FinancialLedgerService;

final class ViewDeliveryCompany extends ViewRecord
{
    protected static string $resource = DeliveryCompanyResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        app(FinancialLedgerService::class)->accountForCompany($this->record);
        $this->record->load('financialAccount', 'owner');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('record_collection')
                ->label(__('delivery_admin.collection.action'))
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn (): bool => $this->canRecordCollection())
                ->form([
                    TextInput::make('amount')
                        ->label(__('delivery_admin.collection.fields.amount'))
                        ->numeric()
                        ->required()
                        ->minValue(0.01)
                        ->step(0.01),
                    Textarea::make('note')
                        ->label(__('delivery_admin.collection.fields.note'))
                        ->maxLength(1000)
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    /** @var DeliveryCompany $company */
                    $company = $this->record;

                    app(DeliveryCollectionService::class)->recordManualCollection(
                        company: $company,
                        amount: (float) $data['amount'],
                        note: isset($data['note']) ? (string) $data['note'] : null,
                        recordedByUserId: auth()->id(),
                    );

                    Notification::make()
                        ->title(__('delivery_admin.collection.success'))
                        ->success()
                        ->send();

                    $this->record->refresh()->load('financialAccount', 'owner');
                }),
        ];
    }

    private function canRecordCollection(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(['admin', 'Super Admin'])) {
            return true;
        }

        return $user->can('delivery_companies.update');
    }
}
