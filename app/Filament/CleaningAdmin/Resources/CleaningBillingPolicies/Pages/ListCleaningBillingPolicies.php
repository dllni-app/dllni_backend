<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningBillingPolicies\Pages;

use App\Filament\CleaningAdmin\Resources\CleaningBillingPolicies\CleaningBillingPolicyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCleaningBillingPolicies extends ListRecords
{
    protected static string $resource = CleaningBillingPolicyResource::class;

    public function getSubheading(): ?string
    {
        return 'إدارة سياسات الفوترة: الاسم، الوصف، طريقة الفوترة (وقت محجوز كامل / وقت عمل فعلي)، الحد الأدنى للدقائق، افتراضي.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
