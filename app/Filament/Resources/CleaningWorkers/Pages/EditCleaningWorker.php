<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Pages;

use App\Enums\UserModuleType;
use App\Filament\Resources\CleaningWorkers\CleaningWorkerResource;
use App\Filament\Resources\Workers\Actions\ChangeWorkerAvatarAction;
use App\Filament\Resources\Workers\Pages\Concerns\SyncsWorkerDebtLimit;
use App\Filament\Resources\Workers\Pages\Concerns\SyncsWorkerLinkedUser;
use App\Models\WorkerTrustLog;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditCleaningWorker extends EditRecord
{
    use SyncsWorkerDebtLimit;
    use SyncsWorkerLinkedUser;

    protected static string $resource = CleaningWorkerResource::class;

    private ?int $trustScoreBeforeSave = null;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->mutateWorkerDebtLimitFormDataBeforeFill(
            $this->mutateLinkedUserFormDataBeforeFill($data),
        );
    }

    protected function beforeSave(): void
    {
        $this->trustScoreBeforeSave = (int) $this->record->trust_score;
    }

    protected function afterSave(): void
    {
        $this->syncLinkedUserAccount();
        $this->markLinkedUserAsCleaningWorker();
        $this->syncWorkerDebtLimitFromForm();
        $this->logManualTrustScoreChange();
    }

    protected function markLinkedUserAsCleaningWorker(): void
    {
        $this->record->user?->forceFill([
            'module_type' => UserModuleType::CleaningWorker->value,
        ])->saveQuietly();
    }

    private function logManualTrustScoreChange(): void
    {
        if ($this->trustScoreBeforeSave === null) {
            return;
        }

        $scoreAfter = (int) $this->record->trust_score;
        if ($scoreAfter === $this->trustScoreBeforeSave) {
            return;
        }

        WorkerTrustLog::query()->create([
            'worker_id' => $this->record->id,
            'cleaning_booking_id' => null,
            'reason' => 'admin_manual_adjustment',
            'score_delta' => $scoreAfter - $this->trustScoreBeforeSave,
            'score_before' => $this->trustScoreBeforeSave,
            'score_after' => $scoreAfter,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            ChangeWorkerAvatarAction::make(),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
