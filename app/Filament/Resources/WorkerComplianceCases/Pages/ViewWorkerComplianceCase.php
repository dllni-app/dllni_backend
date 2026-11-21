<?php

declare(strict_types=1);

namespace App\Filament\Resources\WorkerComplianceCases\Pages;

use App\Filament\Resources\WorkerComplianceCases\WorkerComplianceCaseResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Modules\Cleaning\Models\WorkerComplianceReviewCase;
use Modules\Cleaning\Services\WorkerComplianceCaseReviewService;

final class ViewWorkerComplianceCase extends ViewRecord
{
    protected static string $resource = WorkerComplianceCaseResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        app(WorkerComplianceCaseReviewService::class)->recordEvidenceView($this->case(), $this->actor());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('claim')
                ->label(__('worker_compliance.actions.claim'))
                ->visible(fn (): bool => $this->record->status === 'new'
                    && (auth()->user()?->can('claim', $this->record) ?? false))
                ->requiresConfirmation()
                ->action(function (): void {
                    app(WorkerComplianceCaseReviewService::class)->claim($this->case(), $this->actor());
                    $this->refreshFormData(['status', 'claimed_by_user_id']);
                    Notification::make()->title(__('worker_compliance.notifications.claimed'))->success()->send();
                }),
            Action::make('explained')
                ->label(__('worker_compliance.actions.explained'))
                ->visible(fn (): bool => $this->isAssignedReviewer()
                    && (auth()->user()?->can('review', $this->record) ?? false))
                ->form([
                    Textarea::make('reason')->label(__('worker_compliance.fields.review_reason'))->required()->maxLength(2000),
                    TextInput::make('corroborating_reference')->label(__('worker_compliance.fields.reference'))->maxLength(128),
                ])
                ->requiresConfirmation()
                ->action(fn (array $data) => $this->resolveCase($data, 'explained')),
            Action::make('inconclusive')
                ->label(__('worker_compliance.actions.inconclusive'))
                ->visible(fn (): bool => $this->isAssignedReviewer()
                    && (auth()->user()?->can('review', $this->record) ?? false))
                ->form([
                    Textarea::make('reason')->label(__('worker_compliance.fields.review_reason'))->required()->maxLength(2000),
                    TextInput::make('corroborating_reference')->label(__('worker_compliance.fields.reference'))->maxLength(128),
                ])
                ->requiresConfirmation()
                ->action(fn (array $data) => $this->resolveCase($data, 'inconclusive')),
            Action::make('confirm_violation')
                ->label(__('worker_compliance.actions.confirm'))
                ->color('danger')
                ->visible(fn (): bool => $this->record->status === 'under_review'
                    && (int) $this->record->claimed_by_user_id !== (int) auth()->id()
                    && (auth()->user()?->can('confirm', $this->record) ?? false))
                ->form([
                    Textarea::make('reason')->label(__('worker_compliance.fields.review_reason'))->required()->maxLength(2000),
                    TextInput::make('independent_evidence_reference')
                        ->label(__('worker_compliance.fields.independent_evidence'))
                        ->helperText(__('worker_compliance.help.independent_evidence'))
                        ->required()
                        ->maxLength(128),
                    DateTimePicker::make('worker_response_opportunity_at')
                        ->label(__('worker_compliance.fields.worker_response_opportunity'))
                        ->required()
                        ->maxDate(now()),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    app(WorkerComplianceCaseReviewService::class)->confirmViolation(
                        $this->case(),
                        $this->actor(),
                        (string) $data['reason'],
                        (string) $data['independent_evidence_reference'],
                        $data['worker_response_opportunity_at'],
                    );
                    $this->refreshFormData(['status']);
                    Notification::make()->title(__('worker_compliance.notifications.confirmed'))->success()->send();
                }),
        ];
    }

    private function case(): WorkerComplianceReviewCase
    {
        return $this->record;
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function isAssignedReviewer(): bool
    {
        return $this->record->status === 'under_review'
            && (int) $this->record->claimed_by_user_id === (int) auth()->id();
    }

    /** @param array{reason: string, corroborating_reference?: ?string} $data */
    private function resolveCase(array $data, string $status): void
    {
        app(WorkerComplianceCaseReviewService::class)->resolve(
            $this->case(),
            $this->actor(),
            $status,
            (string) $data['reason'],
            $data['corroborating_reference'] ?? null,
        );
        $this->refreshFormData(['status']);
        Notification::make()->title(__('worker_compliance.notifications.resolved'))->success()->send();
    }
}
