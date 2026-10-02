<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryCompanySettings\Pages;

use App\Filament\Company\Resources\DeliveryCompanySettings\DeliveryCompanySettingsResource;
use Filament\Resources\Pages\EditRecord;

final class EditDeliveryCompanySettings extends EditRecord
{
    protected static string $resource = DeliveryCompanySettingsResource::class;

    protected function getRedirectUrl(): string
    {
        return DeliveryCompanySettingsResource::getUrl('index', panel: 'company');
    }
}
