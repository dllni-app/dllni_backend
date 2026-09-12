<?php

declare(strict_types=1); namespace App\Filament\Resources\CleaningSpecialServiceCategories\Pages; use App\Filament\Resources\CleaningSpecialServiceCategories\CleaningSpecialServiceCategoryResource; use Filament\Actions\CreateAction; use Filament\Resources\Pages\ListRecords; final class ListCleaningSpecialServiceCategories extends ListRecords { protected static string $resource=CleaningSpecialServiceCategoryResource::class; protected function getHeaderActions(): array { return [CreateAction::make()]; } }
