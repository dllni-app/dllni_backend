<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportCases\Pages;

use App\Enums\SupportCaseKind;
use App\Enums\SupportCaseStatus;
use App\Filament\Resources\SupportCases\SupportCaseResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListSupportCases extends ListRecords
{
    protected static string $resource = SupportCaseResource::class;

    public function getTitle(): string
    {
        return 'البلاغات والنزاعات';
    }

    public function getSubheading(): ?string
    {
        return 'إدارة موحدة لبلاغات الطوارئ والشكاوى والنزاعات الواردة من تطبيق العميل وتطبيق العامل.';
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('الكل'),
            'emergencies' => Tab::make('الطوارئ')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('kind', SupportCaseKind::Emergency->value)),
            'complaints' => Tab::make('الشكاوى والنزاعات')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('kind', SupportCaseKind::Complaint->value)),
            'urgent' => Tab::make('العاجلة')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('priority', 'critical')->whereIn('status', SupportCaseStatus::activeValues())),
            'review' => Tab::make('قيد المتابعة')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', SupportCaseStatus::activeValues())),
            'closed' => Tab::make('المغلقة')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [SupportCaseStatus::Resolved->value, SupportCaseStatus::Closed->value])),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
