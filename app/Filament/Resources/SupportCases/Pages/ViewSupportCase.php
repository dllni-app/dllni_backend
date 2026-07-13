<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportCases\Pages;

use App\Enums\SupportCaseKind;
use App\Enums\SupportCaseReporterRole;
use App\Enums\SupportCaseResolution;
use App\Enums\SupportCaseStatus;
use App\Filament\Resources\SupportCases\SupportCaseResource;
use App\Models\SupportCase;
use App\Models\User;
use App\Services\SupportCaseService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Cleaning\Models\CleaningBooking;

final class ViewSupportCase extends ViewRecord
{
    protected static string $resource = SupportCaseResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->reloadRecord();
    }

    public function getTitle(): string
    {
        return 'تفاصيل البلاغ '.$this->record->case_number;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('acknowledge')
                ->label('استلام البلاغ')
                ->icon('heroicon-o-hand-raised')
                ->color('warning')
                ->visible(fn (): bool => $this->record->status === SupportCaseStatus::New)
                ->requiresConfirmation()
                ->action(function (SupportCaseService $service): void {
                    $service->transition($this->record, SupportCaseStatus::Acknowledged, $this->admin());
                    $this->afterMutation('تم استلام البلاغ');
                }),

            Action::make('start_review')
                ->label('بدء المراجعة')
                ->icon('heroicon-o-magnifying-glass')
                ->color('warning')
                ->visible(fn (): bool => $this->record->kind === SupportCaseKind::Complaint && ! $this->record->status->isTerminal())
                ->action(function (SupportCaseService $service): void {
                    $service->transition($this->record, SupportCaseStatus::UnderReview, $this->admin());
                    $this->afterMutation('تم بدء مراجعة النزاع');
                }),

            Action::make('request_information')
                ->label('طلب معلومات')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->visible(fn (): bool => ! $this->record->status->isTerminal())
                ->form([
                    Textarea::make('message')
                        ->label('الرسالة للطرف المعني')
                        ->required()
                        ->minLength(2)
                        ->maxLength(1000),
                ])
                ->action(function (array $data, SupportCaseService $service): void {
                    $admin = $this->admin();
                    $service->addMessage(
                        supportCase: $this->record,
                        sender: $admin,
                        senderRole: SupportCaseReporterRole::Admin,
                        body: (string) $data['message'],
                    );
                    $service->transition($this->record, SupportCaseStatus::WaitingParty, $admin);
                    $this->afterMutation('تم إرسال طلب المعلومات');
                }),

            Action::make('release_worker_earnings')
                ->label('تحرير مستحقات العامل')
                ->icon('heroicon-o-lock-open')
                ->color('success')
                ->visible(fn (): bool => $this->record->kind === SupportCaseKind::Complaint && (bool) $this->record->worker_earnings_frozen)
                ->requiresConfirmation()
                ->action(function (SupportCaseService $service): void {
                    $service->releaseWorkerEarnings($this->record, $this->admin());
                    $this->afterMutation('تم تحرير مستحقات العامل');
                }),

            Action::make('resolve')
                ->label('اعتماد الحل')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => ! $this->record->status->isTerminal())
                ->form([
                    Select::make('resolution')
                        ->label('القرار')
                        ->options(fn (): array => $this->resolutionOptions())
                        ->required(),
                    Textarea::make('resolution_note')
                        ->label('ملاحظة القرار')
                        ->maxLength(2000),
                ])
                ->action(function (array $data, SupportCaseService $service): void {
                    $service->resolve(
                        supportCase: $this->record,
                        resolution: SupportCaseResolution::from((string) $data['resolution']),
                        note: $data['resolution_note'] ?? null,
                        actor: $this->admin(),
                    );
                    $this->afterMutation('تم اعتماد الحل وإغلاق المتابعة');
                }),

            Action::make('close')
                ->label('إغلاق')
                ->icon('heroicon-o-x-circle')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === SupportCaseStatus::Resolved)
                ->requiresConfirmation()
                ->action(function (SupportCaseService $service): void {
                    $service->transition($this->record, SupportCaseStatus::Closed, $this->admin());
                    $this->afterMutation('تم إغلاق البلاغ');
                }),
        ];
    }

    private function admin(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function resolutionOptions(): array
    {
        $cases = $this->record->kind === SupportCaseKind::Emergency
            ? [SupportCaseResolution::EmergencyResolved, SupportCaseResolution::NoAction]
            : [
                SupportCaseResolution::WorkerPenalty,
                SupportCaseResolution::Refund,
                SupportCaseResolution::Dismissed,
                SupportCaseResolution::NoAction,
            ];

        return collect($cases)->mapWithKeys(fn (SupportCaseResolution $case): array => [$case->value => $case->label()])->all();
    }

    private function afterMutation(string $message): void
    {
        $this->reloadRecord();

        Notification::make()
            ->title($message)
            ->success()
            ->send();
    }

    private function reloadRecord(): void
    {
        $this->record->refresh()->load([
            'reporter',
            'messages.sender',
            'messages.media',
            'events.actor',
            'resolvedBy',
            'media',
            'booking' => function (MorphTo $morphTo): void {
                $morphTo->morphWith([
                    CleaningBooking::class => ['customer', 'worker.user'],
                ]);
            },
        ]);
    }
}
