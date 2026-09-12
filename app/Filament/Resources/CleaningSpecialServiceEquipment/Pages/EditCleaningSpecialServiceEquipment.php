<?php

declare(strict_types=1);
namespace App\Filament\Resources\CleaningSpecialServiceEquipment\Pages;
use App\Filament\Resources\CleaningSpecialServiceEquipment\CleaningSpecialServiceEquipmentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
final class EditCleaningSpecialServiceEquipment extends EditRecord { protected static string $resource=CleaningSpecialServiceEquipmentResource::class; protected function getHeaderActions(): array { return [DeleteAction::make()->requiresConfirmation()]; } }
