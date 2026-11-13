<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantProducts\Pages;

use App\Filament\Resources\RestaurantProducts\RestaurantProductResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

final class EditRestaurantProductClassification extends EditRecord
{
    protected static string $resource = RestaurantProductResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $role = $data['catalog_role'] ?? 'independent';
        $kind = $data['product_kind'] ?? null;

        if ($role === 'modifier_only') {
            // A modifier never gets a standalone food category or meal ranking.
            $data['product_kind'] = 'other';
            $data['primary_food_category_id'] = null;
        } elseif (in_array($kind, ['main_dish', 'combo_meal'], true)
            && empty($data['primary_food_category_id'])) {
            throw ValidationException::withMessages([
                'data.primary_food_category_id' => 'حدد تصنيف الوجبة قبل اعتمادها.',
            ]);
        }

        $data['classification_status'] = 'reviewed';
        $data['classified_at'] = now();

        return $data;
    }
}
