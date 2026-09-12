<?php

declare(strict_types=1);
namespace App\Filament\Resources\CleaningMaterialUnits\Pages;
use App\Filament\Resources\CleaningMaterialUnits\CleaningMaterialUnitResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
final class EditCleaningMaterialUnit extends EditRecord { protected static string $resource=CleaningMaterialUnitResource::class; protected function getHeaderActions(): array { return [DeleteAction::make()->requiresConfirmation()]; } }
