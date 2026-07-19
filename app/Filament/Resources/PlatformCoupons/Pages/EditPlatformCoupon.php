<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformCoupons\Pages;

use App\Filament\Resources\PlatformCoupons\PlatformCouponResource;
use App\Models\PlatformCouponConstraint;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditPlatformCoupon extends EditRecord
{
    protected static string $resource = PlatformCouponResource::class;

    /** @var array<string, array<int, string>> */
    private array $constraintValues = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $constraints = $this->record->constraints()->get()->groupBy('constraint_type');
        $data['property_types'] = $constraints->get(PlatformCouponConstraint::TYPE_PROPERTY)?->pluck('constraint_value')->all() ?? [];
        $data['cleaning_modes'] = $constraints->get(PlatformCouponConstraint::TYPE_CLEANING_MODE)?->pluck('constraint_value')->all() ?? [];
        $data['event_types'] = $constraints->get(PlatformCouponConstraint::TYPE_EVENT)?->pluck('constraint_value')->all() ?? [];

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->constraintValues = [
            PlatformCouponConstraint::TYPE_PROPERTY => array_values($data['property_types'] ?? []),
            PlatformCouponConstraint::TYPE_CLEANING_MODE => array_values($data['cleaning_modes'] ?? []),
            PlatformCouponConstraint::TYPE_EVENT => array_values($data['event_types'] ?? []),
        ];

        unset($data['property_types'], $data['cleaning_modes'], $data['event_types']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->constraints()->delete();
        foreach ($this->constraintValues as $type => $values) {
            foreach ($values as $value) {
                $this->record->constraints()->create([
                    'constraint_type' => $type,
                    'constraint_value' => $value,
                ]);
            }
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->hidden(fn (): bool => $this->record->redemptions()->exists()),
        ];
    }
}
