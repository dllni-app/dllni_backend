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
use Modules\Cleaning\Services\CleaningV3CatalogReviewManifestService;
use Throwable;

final class EditCleaningV3CatalogVersion extends EditRecord
{
    protected static string $resource = CleaningV3CatalogVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publishCatalog')
                ->label('اعتماد الأسعار ونشر الكتالوج')
                ->icon('heroicon-o-check-circle')
                ->visible(fn (): bool => config('cleaning_v3_catalog.admin_publication_enabled', false)
                    && \Modules\Cleaning\Services\CleaningV3AdminCatalogPublication::authorized()
                    && CleaningV3CatalogVersionResource::canEdit($this->record))
                ->authorize(fn (): bool => \Modules\Cleaning\Services\CleaningV3AdminCatalogPublication::authorized())
                ->requiresConfirmation()
                ->form([
                    TextInput::make('reviewedFingerprint')->label('بصمة النسخة التي تمت مراجعتها')
                        ->default(fn () => app(CleaningV3CatalogReviewManifestService::class)->fingerprint($this->record))
                        ->readOnly()->required(),
                    \Filament\Forms\Components\Checkbox::make('approved')
                        ->label('راجعت الأسعار والمدد ونطاقات القياس وأوافق على نشر هذه النسخة للعملاء')
                        ->rule('accepted')->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        if (($data['approved'] ?? false) !== true) {
                            throw new \DomainException('V3_PUBLICATION_APPROVAL_REQUIRED');
                        }
                        app(\Modules\Cleaning\Services\CleaningV3AdminCatalogPublication::class)->publish($this->record, $data['reviewedFingerprint']);
                        Notification::make()->title('تم اعتماد ونشر الكتالوج')->success()->send();
                        $this->redirect(CleaningV3CatalogVersionResource::getUrl('index'));
                    } catch (\Illuminate\Validation\ValidationException $e) {
                        Notification::make()->title('تعذر النشر')->body(implode(' — ', array_map(
                            fn (string $issue): string => $this->readinessIssueLabel($issue), $e->errors()['catalog'] ?? [],
                        )))->danger()->persistent()->send();
                    } catch (\DomainException|\LogicException $e) {
                        Notification::make()->title('تعذر النشر، أعد مراجعة النسخة')->danger()->send();
                    }
                }),
            Action::make('reviewDraftManifest')
                ->label('بصمة مراجعة الكتالوج')
                ->icon('heroicon-o-finger-print')
                ->color('gray')
                ->visible(fn (): bool => CleaningV3CatalogVersionResource::canEdit($this->record))
                ->authorize(fn (): bool => CleaningV3CatalogVersionResource::canEdit($this->record))
                ->action(function (): void {
                    $manifest = app(CleaningV3CatalogReviewManifestService::class)->inspect($this->record);
                    Notification::make()
                        ->title('بصمة المسودة للمراجعة فقط')
                        ->body('SHA-256: '.$manifest['fingerprint'].' — البنود: '.$manifest['itemCount']
                            .' — قواعد السعر والجهد: '.$manifest['tariffRuleCount']
                            .' — عوائق الاعتماد: '.count($manifest['issueCodes'])
                            .'. لا تسمح هذه البصمة بالنشر أو الحجز أو التحصيل.')
                        ->warning()->persistent()->send();
                }),
            Action::make('reviewDraftReadiness')
                ->label('فحص جاهزية نشر الكتالوج')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('warning')
                ->visible(fn (): bool => CleaningV3CatalogVersionResource::canEdit($this->record))
                ->authorize(fn (): bool => CleaningV3CatalogVersionResource::canEdit($this->record))
                ->action(function (): void {
                    /** @var CleaningV3CatalogVersion $version */
                    $version = $this->record;
                    $problems = config('cleaning_v3_catalog.admin_publication_enabled', false)
                        ? app(\Modules\Cleaning\Services\CleaningV3AdminCatalogPublication::class)->issues($version)
                        : app(CleaningV3CatalogDraftValidator::class)->review($version);
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
            'CATALOG_NO_BOOKABLE_SERVICE' => 'لا توجد خدمة قابلة للحجز ضمن هذا النموذج',
            'EVENT_FORM_REQUIRES_TASK_FIELDS' => 'افصل نموذج المناسبة الزمنية عن بنود التنظيف الإلزامية',
            'EVENT_TYPE_MUST_BE_OPTIONAL' => 'نوع المناسبة يحدد من جدول المناسبة وليس كحقل إلزامي',
            'TASK_QUANTITY_LIMIT_EXCEEDED' => 'عدد القطع أكبر من حد التخطيط المدعوم',
            'FINITE_QUANTITY_RANGE_REQUIRED' => 'حدد أقل وأكبر كمية مسموحة للبند',
            'FIXED_QUANTITY_REQUIRED' => 'السعر الثابت يتطلب كمية واحدة',
            'INTEGER_QUANTITY_REQUIRED' => 'العدد والقطعة يتطلبان كميات وخطوة صحيحة',
            'TARIFF_COVERAGE_INCOMPLETE' => 'التعرفة لا تغطي كل الكميات أو نطاق التنظيف',
            'EVENT_COVERAGE_INCOMPLETE' => 'تعرفة المناسبة لا تغطي ساعات العمال المسموحة',
            'UNSUPPORTED_PRICED_COMPONENT' => 'البند المسعر يتطلب حقل كمية مدعوماً',
            'CATALOG_FIELD_LIMIT_EXCEEDED' => 'عدد البنود أكبر من الحد المدعوم',
            'INVALID_EVENT_HOURLY_TARIFF' => 'تعرفة المناسبة يجب أن تكون بالساعة وجهد 60 دقيقة',
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
            'TARIFF_START_GAP' => 'شرائح الأسعار لا تغطي أقل كمية مسموحة',
            'TARIFF_END_GAP' => 'شرائح الأسعار لا تغطي أعلى كمية مسموحة',
            'APPROVED_CATALOG_COVERAGE_MATRIX_MISSING' => 'مصفوفة تغطية الكتالوج غير معتمدة',
            'APPROVED_V3_PRICING_AND_FUNDING_PROOF_MISSING' => 'اعتماد التسعير والتمويل لم يكتمل',
            default => 'حالة تتطلب مراجعة فنية: '.$code,
        };

        return $itemKey === null ? $message : $message.' ('.$itemKey.')';
    }
}
