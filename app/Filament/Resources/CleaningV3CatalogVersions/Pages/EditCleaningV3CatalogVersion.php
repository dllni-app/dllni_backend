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
use Modules\Cleaning\Services\CleaningV3CatalogDraftValidator;
use Throwable;

final class EditCleaningV3CatalogVersion extends EditRecord
{
    protected static string $resource = CleaningV3CatalogVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reviewDraftReadiness')
                ->label('فحص جاهزية نشر الكتالوج')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('warning')
                ->visible(fn (): bool => CleaningV3CatalogVersionResource::canEdit($this->record))
                ->authorize(fn (): bool => CleaningV3CatalogVersionResource::canEdit($this->record))
                ->action(function (): void {
                    /** @var CleaningV3CatalogVersion $version */
                    $version = $this->record;
                    $problems = app(CleaningV3CatalogDraftValidator::class)->review($version);
                    // All reads are fresh. This action NEVER publishes,
                    // approves financing, or changes a saved price.
                    Notification::make()
                        ->title($problems === []
                            ? 'لا توجد مشاكل بنيوية مكتشفة'
                            : 'الكتالوج غير جاهز للنشر: '.count($problems).' مشكلة')
                        ->body($problems === []
                            ? 'هذا الفحص لا يعتبر موافقة مالية أو إذناً بالنشر.'
                            : implode(' — ', array_map(
                                fn (string $problem): string => $this->readinessIssueLabel($problem),
                                array_slice($problems, 0, 12),
                            )))
                        ->warning()
                        ->persistent()
                        ->send();
                }),
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

    private function readinessIssueLabel(string $problem): string
    {
        [$code, $itemKey] = array_pad(explode(':', $problem, 2), 2, null);
        $message = match ($code) {
            'CATALOG_EMPTY' => 'لا توجد بنود في المسودة',
            'INVALID_SOURCE' => 'مصدر البند غير صالح أو غير مفعّل',
            'INVALID_PRESET' => 'حدود قياس غير صحيحة',
            'OVERLAPPING_PRESETS' => 'تداخل نطاقات القياس',
            'UNSUPPORTED_INPUT_RULES' => 'قواعد إدخال غير مدعومة',
            'REQUIRED_COMPONENT_UNSUPPORTED' => 'حقل إلزامي لا يستطيع الخادم التحقق منه',
            'INVALID_INPUT_CONSTRAINTS' => 'قيود الحقل غير صحيحة',
            'TARIFF_MISSING' => 'سعر أو جهد البند غير محدد',
            'INVALID_TARIFF' => 'سعر أو جهد غير صالح',
            'OVERLAPPING_TARIFFS' => 'شرائح أسعار متداخلة',
            'TARIFF_GAP' => 'فجوة بين شرائح الأسعار',
            'APPROVED_CATALOG_COVERAGE_MATRIX_MISSING' => 'مصفوفة تغطية الكتالوج غير معتمدة',
            'APPROVED_V3_PRICING_AND_FUNDING_PROOF_MISSING' => 'اعتماد التسعير والتمويل لم يكتمل',
            default => 'حالة تتطلب مراجعة فنية: '.$code,
        };

        return $itemKey === null ? $message : $message.' ('.$itemKey.')';
    }
}
