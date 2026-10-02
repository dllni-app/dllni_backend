<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryDisputes\Pages;

use App\Filament\Resources\DeliveryDisputes\DeliveryDisputeResource;
use Filament\Resources\Pages\ListRecords;

final class ListDeliveryDisputes extends ListRecords
{
    protected static string $resource=DeliveryDisputeResource::class;
    public function getTitle():string{return 'نزاعات التوصيل';}
}
