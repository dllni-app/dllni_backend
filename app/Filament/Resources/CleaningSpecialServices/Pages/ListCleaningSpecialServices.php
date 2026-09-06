<?php

declare(strict_types=1);
namespace App\Filament\Resources\CleaningSpecialServices\Pages;
use App\Filament\Resources\CleaningSpecialServices\CleaningSpecialServiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
final class ListCleaningSpecialServices extends ListRecords { protected static string $resource=CleaningSpecialServiceResource::class; protected function getHeaderActions(): array { return [CreateAction::make()]; } }
