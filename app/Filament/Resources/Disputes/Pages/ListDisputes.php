<?php

declare(strict_types=1);

namespace App\Filament\Resources\Disputes\Pages;

use App\Filament\Resources\Disputes\DisputeResource;
use App\Filament\Resources\Disputes\Widgets\DisputeStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

final class ListDisputes extends ListRecords
{
    protected static string $resource = DisputeResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('cleaning_admin.disputes.nav_label');
    }

    public function getSubheading(): ?string
    {
        return __('cleaning_admin.pages.disputes.list');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            DisputeStats::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('cleaning_admin.disputes.actions.create')),
        ];
    }
}
