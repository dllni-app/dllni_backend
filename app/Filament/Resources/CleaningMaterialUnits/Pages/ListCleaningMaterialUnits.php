<?php

declare(strict_types=1);
namespace App\Filament\Resources\CleaningMaterialUnits\Pages;
use App\Filament\Resources\CleaningMaterialUnits\CleaningMaterialUnitResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
final class ListCleaningMaterialUnits extends ListRecords { protected static string $resource=CleaningMaterialUnitResource::class; protected function getHeaderActions(): array { return [CreateAction::make()]; } }
