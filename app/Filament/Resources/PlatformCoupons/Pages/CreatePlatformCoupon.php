<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformCoupons\Pages;

use App\Filament\Resources\PlatformCoupons\PlatformCouponResource;
use App\Jobs\DispatchPlatformCouponNotifications;
use App\Models\PlatformCouponConstraint;
use Filament\Resources\Pages\CreateRecord;

final class CreatePlatformCoupon extends CreateRecord
{
    protected static string $resource = PlatformCouponResource::class;

    /** @var array<string, array<int, string>> */
    private array $constraintValues = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->constraintValues = [
            PlatformCouponConstraint::TYPE_PROPERTY => array_values($data['property_types'] ?? []),
            PlatformCouponConstraint::TYPE_CLEANING_MODE => array_values($data['cleaning_modes'] ?? []),
            PlatformCouponConstraint::TYPE_EVENT => array_values($data['event_types'] ?? []),
        ];

        unset($data['property_types'], $data['cleaning_modes'], $data['event_types']);
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->syncConstraints();
        DispatchPlatformCouponNotifications::dispatch((int) $this->record->id);
    }

    private function syncConstraints(): void
    {
        foreach ($this->constraintValues as $type => $values) {
            foreach ($values as $value) {
                $this->record->constraints()->create([
                    'constraint_type' => $type,
                    'constraint_value' => $value,
                ]);
            }
        }
    }
}
