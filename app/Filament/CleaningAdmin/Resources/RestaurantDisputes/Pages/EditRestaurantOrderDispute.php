<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\RestaurantDisputes\Pages;

use App\Filament\CleaningAdmin\Resources\RestaurantDisputes\RestaurantOrderDisputeResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditRestaurantOrderDispute extends EditRecord
{
    protected static string $resource = RestaurantOrderDisputeResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (in_array($data['status'] ?? null, ['resolved', 'closed'], true)) {
            $data['resolved_by_user_id'] = auth()->id();
            $data['resolved_at'] = now();
            $data['payout_hold_status'] = 'released';
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
