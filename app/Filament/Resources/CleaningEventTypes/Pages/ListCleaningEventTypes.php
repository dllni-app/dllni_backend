<?php

declare(strict_types=1);
namespace App\Filament\Resources\CleaningEventTypes\Pages;
use App\Filament\Resources\CleaningEventTypes\CleaningEventTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
final class ListCleaningEventTypes extends ListRecords { protected static string $resource = CleaningEventTypeResource::class; protected function getHeaderActions(): array { return [CreateAction::make()]; } }
