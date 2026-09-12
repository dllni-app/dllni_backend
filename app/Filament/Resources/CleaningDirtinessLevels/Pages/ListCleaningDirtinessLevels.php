<?php

declare(strict_types=1); namespace App\Filament\Resources\CleaningDirtinessLevels\Pages; use App\Filament\Resources\CleaningDirtinessLevels\CleaningDirtinessLevelResource; use Filament\Actions\CreateAction; use Filament\Resources\Pages\ListRecords; final class ListCleaningDirtinessLevels extends ListRecords { protected static string $resource=CleaningDirtinessLevelResource::class; protected function getHeaderActions(): array { return [CreateAction::make()]; } }
