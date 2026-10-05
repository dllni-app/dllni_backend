<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Pages;

use App\Filament\Resources\Workers\Actions\ChangeWorkerAvatarAction;
use App\Filament\Resources\Workers\Pages\Concerns\SyncsWorkerDebtLimit;
use App\Filament\Resources\Workers\Pages\Concerns\SyncsWorkerLinkedUser;
use App\Filament\Resources\Workers\WorkerResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Cleaning\Services\CleaningWorkerNotificationService;

final class EditWorker extends EditRecord
{
    use SyncsWorkerDebtLimit;
    use SyncsWorkerLinkedUser;

    protected static string $resource = WorkerResource::class;

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
        $this->syncWorkerDebtLimitFromForm();
        $this->notifyManualTrustScoreChange();
    }

    private function notifyManualTrustScoreChange(): void
    {
        if ($this->trustScoreBeforeSave === null) {
            return;
        }

        $scoreAfter = (int) $this->record->trust_score;
        if ($scoreAfter === $this->trustScoreBeforeSave) {
            return;
        }

        app(CleaningWorkerNotificationService::class)->notifyTrustScoreChanged(
            worker: $this->record,
            scoreBefore: $this->trustScoreBeforeSave,
            scoreAfter: $scoreAfter,
        );
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
