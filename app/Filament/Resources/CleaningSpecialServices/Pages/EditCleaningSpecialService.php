<?php

declare(strict_types=1);
namespace App\Filament\Resources\CleaningSpecialServices\Pages;
use App\Filament\Resources\CleaningSpecialServices\CleaningSpecialServiceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
final class EditCleaningSpecialService extends EditRecord { protected static string $resource=CleaningSpecialServiceResource::class; protected function getHeaderActions(): array { return [DeleteAction::make()->requiresConfirmation()]; } }
