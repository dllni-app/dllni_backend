<?php

declare(strict_types=1);

namespace App\Filament\Resources\Disputes\Pages;

use App\Filament\Resources\Disputes\DisputeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListDisputes extends ListRecords
{
    protected static string $resource = DisputeResource::class;

    public function getSubheading(): ?string
    {
        return __('cleaning_admin.pages.disputes.list');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
