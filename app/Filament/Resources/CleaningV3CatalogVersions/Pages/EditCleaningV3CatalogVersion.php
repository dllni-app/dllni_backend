<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningV3CatalogVersions\Pages;

use App\Filament\Resources\CleaningV3CatalogVersions\CleaningV3CatalogVersionResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Modules\Cleaning\Services\CleaningV3CatalogDraftEstimateService;
use Throwable;

final class EditCleaningV3CatalogVersion extends EditRecord
{
    protected static string $resource = CleaningV3CatalogVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('previewDraftTariff')
                ->label('معاينة السعر والجهد دون حجز')
                ->icon('heroicon-o-calculator')
                ->color('gray')
                ->visible(fn (): bool => CleaningV3CatalogVersionResource::canEdit($this->record))
                ->authorize(fn (): bool => CleaningV3CatalogVersionResource::canEdit($this->record))
                ->modalDescription('هذه معاينة حسابية للمسودة فقط؛ لا تنشئ عرض سعر معتمداً ولا حجزاً أو تحصيلاً.')
                ->form([
                    Select::make('itemKey')
                        ->label('بند الخدمة')
                        ->options(fn (): array => $this->record->items()
                            ->where('is_enabled', true)->pluck('label_ar', 'item_key')->all())
                        ->required()->searchable(),
                    Select::make('cleaningMode')->label('نمط التنظيف')->options([
                        'regular' => 'حوسة',
                        'deep' => 'تعزيل',
                    ])->required(),
                    TextInput::make('scopeKey')
                        ->label('رمز الجزء (whole أو floors...)')
                        ->required()->default('whole')
                        ->regex('/^[a-z][a-z0-9_.-]{0,99}$/'),
                    TextInput::make('quantity')->label('الكمية')
                        ->required()
                        ->regex('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/'),
                ])
                ->action(function (array $data): void {
                    try {
                        /** @var CleaningV3CatalogVersion $version */
                        $version = $this->record;
                        $result = app(CleaningV3CatalogDraftEstimateService::class)->estimate(
                            $version,
                            [[
                                'itemKey' => (string) $data['itemKey'],
                                'cleaningMode' => (string) $data['cleaningMode'],
                                'scopeKey' => (string) $data['scopeKey'],
                                'quantity' => (string) $data['quantity'],
                            ]],
                        );

                        Notification::make()
                            ->title('نتيجة المعاينة غير الملزمة')
                            ->body(
                                'السعر التقديري: '.$result['estimatedBaseAmountSyp']
                                .' ل.س — الجهد التقديري: '.$result['estimatedEffortMinutes']
                                .' دقيقة. يلزم اعتماد القدرة والتمويل قبل الحجز.'
                            )
                            ->success()
                            ->send();
                    } catch (Throwable) {
                        Notification::make()
                            ->title('لا يمكن معاينة هذه القاعدة')
                            ->body('تحقق من البند والنطاق والكمية وعدم تداخل شرائح الأسعار.')
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
