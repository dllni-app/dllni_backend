<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOrderDisputes\Pages;

use App\Filament\Resources\SmOrderDisputes\SmOrderDisputeResource;
use Filament\Resources\Pages\ListRecords;

final class ListSmOrderDisputes extends ListRecords
{
    protected static string $resource = SmOrderDisputeResource::class;

    public function getSubheading(): ?string
    {
        return __('supermarket_admin.pages.disputes.list');
    }
}
