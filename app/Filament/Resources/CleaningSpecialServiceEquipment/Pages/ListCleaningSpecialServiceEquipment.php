<?php

declare(strict_types=1);
namespace App\Filament\Resources\CleaningSpecialServiceEquipment\Pages;
use App\Filament\Resources\CleaningSpecialServiceEquipment\CleaningSpecialServiceEquipmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
final class ListCleaningSpecialServiceEquipment extends ListRecords { protected static string $resource=CleaningSpecialServiceEquipmentResource::class; protected function getHeaderActions(): array { return [CreateAction::make()]; } }
