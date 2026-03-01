<?php

declare(strict_types=1);

namespace App\Filament\Resources\SystemAlerts\Pages;

use App\Filament\Resources\SystemAlerts\SystemAlertResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListSystemAlerts extends ListRecords
{
    protected static string $resource = SystemAlertResource::class;

    public function getSubheading(): ?string
    {
        return 'تنبيهات تأخر التقييم المتبادل، تجمد الموقع، استغاثة، تجاوز الوقت دون انتهاء؛ إجراءات الاتصال بالعميل أو العامل أو الحل.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
