<?php

declare(strict_types=1);
namespace App\Filament\Resources\CleaningMaterialTypes\Pages;
use App\Filament\Resources\CleaningMaterialTypes\CleaningMaterialTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
final class ListCleaningMaterialTypes extends ListRecords { protected static string $resource=CleaningMaterialTypeResource::class; protected function getHeaderActions(): array { return [CreateAction::make()]; } }
