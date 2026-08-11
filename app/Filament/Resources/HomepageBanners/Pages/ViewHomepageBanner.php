<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageBanners\Pages;

use App\Filament\Resources\HomepageBanners\HomepageBannerResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewHomepageBanner extends ViewRecord
{
    protected static string $resource = HomepageBannerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
