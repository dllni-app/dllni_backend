<?php

declare(strict_types=1);

namespace App\Filament\Resources\Disputes\Pages;

use App\Enums\DisputeStatus;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Models\Worker;
use App\Services\DisputeFinancialPenaltyService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\EventBooking;
use Modules\Resturants\Models\Order;
use Throwable;

final class ViewDispute extends ViewRecord
{
    protected static string $resource = DisputeResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->record->load([
            'messages.sender',
            'financialPenaltyWorker.user',
            'financialPenaltyTransaction',
            'financialPenaltyAppliedBy',
            'booking' => function (MorphTo $morphTo): void {
                $morphTo->morphWith([
                    CleaningBooking::class => [
                        'customer',
                        'worker.user',
                        'acceptedWorkerAssignments.worker.user',
                    ],
                    EventBooking::class => ['customer'],
                    Order::class => ['customer'],
                ]);
            },
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('deduct_worker')
                ->label(__('dispute_finance.action.label'))
                ->icon('heroicon-o-banknotes')
                ->color('danger')
                ->visible(fn (): bool => $this->workerOptions() !== [])
                ->disabled(fn (): bool => $this->record->financial_penalty_transaction_id !== null)
                ->tooltip(fn (): ?string => $this->record->financial_penalty_transaction_id !== null
                    ? __('dispute_finance.action.already_applied')
                    : null)
                ->modalHeading(__('dispute_finance.action.heading'))
                ->modalDescription(__('dispute_finance.action.description'))
                ->modalSubmitActionLabel(__('dispute_finance.action.submit'))
                ->requiresConfirmation()
                ->form([
                    Select::make('worker_id')
                        ->label(__('dispute_finance.fields.worker'))
                        ->options(fn (): array => $this->workerOptions())
                        ->default(fn (): ?int => array_key_first($this->workerOptions()))
                        ->searchable()
                        ->required(),
                    TextInput::make('amount')
                        ->label(__('dispute_finance.fields.amount'))
                        ->helperText(__('dispute_finance.fields.amount_helper'))
                        ->numeric()
                        ->minValue(0.01)
                        ->suffix(config('app.currency', 'SYP'))
                        ->required(),
                    Textarea::make('notes')
                        ->label(__('dispute_finance.fields.notes'))
                        ->rows(3)
                        ->maxLength(1000)
                        ->required(),
                    Toggle::make('keep_worker_earnings_frozen')
                        ->label(__('dispute_finance.fields.keep_frozen'))
                        ->helperText(__('dispute_finance.fields.keep_frozen_helper'))
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    try {
                        $worker = Worker::query()->findOrFail((int) $data['worker_id']);
                        $updated = app(DisputeFinancialPenaltyService::class)->apply(
                            dispute: $this->record,
                            worker: $worker,
                            amount: (float) $data['amount'],
                            notes: isset($data['notes']) ? (string) $data['notes'] : null,
                            appliedByAdminId: auth()->id(),
                            keepWorkerEarningsFrozen: (bool) ($data['keep_worker_earnings_frozen'] ?? false),
                        );

                        $this->record = $updated;

                        Notification::make()
                            ->title(__('dispute_finance.action.success_title'))
                            ->body(__('dispute_finance.action.success_body', [
                                'amount' => number_format((float) $updated->financial_penalty_amount, 2),
                                'currency' => config('app.currency', 'SYP'),
                            ]))
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title(__('dispute_finance.action.error_title'))
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('close')
                ->label(__('cleaning_admin.disputes.actions.close'))
                ->requiresConfirmation()
                ->modalHeading(__('cleaning_admin.disputes.modals.close_heading'))
                ->action(function (): void {
                    $this->record->update(['status' => DisputeStatus::Closed]);
                    Notification::make()->title(__('cleaning_admin.disputes.notifications.dispute_closed'))->success()->send();
                }),
            EditAction::make(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function workerOptions(): array
    {
        $booking = $this->record->booking;

        if (! $booking instanceof CleaningBooking) {
            return [];
        }

        $booking->loadMissing([
            'worker.user',
            'acceptedWorkerAssignments.worker.user',
        ]);

        $workers = collect();

        if ($booking->worker instanceof Worker) {
            $workers->put($booking->worker->id, $booking->worker);
        }

        foreach ($booking->acceptedWorkerAssignments as $assignment) {
            if ($assignment->worker instanceof Worker) {
                $workers->put($assignment->worker->id, $assignment->worker);
            }
        }

        return $workers
            ->mapWithKeys(fn (Worker $worker): array => [
                $worker->id => $worker->user?->name
                    ?: $worker->first_name
                    ?: '#'.$worker->id,
            ])
            ->all();
    }
}
