<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformCoupons\Pages;

use App\Filament\Resources\PlatformCoupons\PlatformCouponResource;
use App\Jobs\DispatchPlatformCouponNotifications;
use App\Models\PlatformCoupon;
use App\Models\PlatformCouponConstraint;
use Filament\Resources\Pages\CreateRecord;

final class CreatePlatformCoupon extends CreateRecord
{
    protected static string $resource = PlatformCouponResource::class;

    /** @var array<string, array<int, string>> */
    private array $constraintValues = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $supportsCleaningConstraints = in_array(
            $data['section'] ?? null,
            [PlatformCoupon::SECTION_CLEANING, PlatformCoupon::SECTION_ALL],
            true,
        );

        $this->constraintValues = [
            PlatformCouponConstraint::TYPE_PROPERTY => $supportsCleaningConstraints ? array_values($data['property_types'] ?? []) : [],
            PlatformCouponConstraint::TYPE_CLEANING_MODE => $supportsCleaningConstraints ? array_values($data['cleaning_modes'] ?? []) : [],
            PlatformCouponConstraint::TYPE_EVENT => $supportsCleaningConstraints ? array_values($data['event_types'] ?? []) : [],
        ];

        unset($data['property_types'], $data['cleaning_modes'], $data['event_types']);
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->syncConstraints();

        if ($this->record->audience_type === PlatformCoupon::AUDIENCE_ALL_USERS) {
            $this->record->users()->detach();
        }

        DispatchPlatformCouponNotifications::dispatch((int) $this->record->id)->afterCommit();
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
