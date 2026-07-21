<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Pages;

use App\Enums\UserModuleType;
use App\Filament\Resources\CleaningWorkers\CleaningWorkerResource;
use App\Filament\Resources\Workers\Pages\Concerns\SyncsWorkerDebtLimit;
use App\Filament\Resources\Workers\Pages\Concerns\SyncsWorkerLinkedUser;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningNeighborhood;

final class CreateCleaningWorker extends CreateRecord
{
    use SyncsWorkerDebtLimit;
    use SyncsWorkerLinkedUser;

    protected static string $resource = CleaningWorkerResource::class;

    protected function afterCreate(): void
    {
        $this->syncLinkedUserAccount();
        $this->syncWorkerAvatarFromForm();
        $this->syncCleaningWorkerCreationDefaults();
        $this->syncWorkerDebtLimitFromForm();

        $this->record->user?->forceFill([
            'module_type' => UserModuleType::CleaningWorker->value,
        ])->saveQuietly();
    }

    private function syncCleaningWorkerCreationDefaults(): void
    {
        DB::transaction(function (): void {
            $this->record->forceFill([
                'trust_score' => 100,
            ])->saveQuietly();

            CleaningNeighborhood::query()
                ->active()
                ->orderBy('sort_order')
                ->orderBy('name_ar')
                ->get(['id', 'name_ar', 'name_en', 'normalized_name'])
                ->each(function (CleaningNeighborhood $neighborhood): void {
                    $this->record->zones()->updateOrCreate(
                        ['neighborhood_id' => $neighborhood->id],
                        [
                            'name' => $this->neighborhoodDisplayName($neighborhood),
                            'is_active' => true,
                        ]
                    );
                });
        });
    }

    private function neighborhoodDisplayName(CleaningNeighborhood $neighborhood): string
    {
        return (string) ($neighborhood->name_ar
            ?: $neighborhood->name_en
            ?: $neighborhood->normalized_name
            ?: 'Neighborhood '.$neighborhood->id);
    }
}
