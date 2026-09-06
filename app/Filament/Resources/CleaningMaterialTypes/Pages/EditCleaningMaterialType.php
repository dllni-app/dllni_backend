<?php

declare(strict_types=1);
namespace App\Filament\Resources\CleaningMaterialTypes\Pages;
use App\Filament\Resources\CleaningMaterialTypes\CleaningMaterialTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
final class EditCleaningMaterialType extends EditRecord { protected static string $resource=CleaningMaterialTypeResource::class; protected function getHeaderActions(): array { return [DeleteAction::make()->requiresConfirmation()]; } }
