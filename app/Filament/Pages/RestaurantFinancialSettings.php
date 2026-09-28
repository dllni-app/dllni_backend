<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\AuthorizesPlatformAdminResource;
use App\Models\RestaurantFinancialSetting;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use UnitEnum;

final class RestaurantFinancialSettings extends Page
{
    use AuthorizesPlatformAdminResource;

    public string $commissionType = 'percent';

    public float $commissionValue = 0.0;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'الماليات';

    protected static ?string $navigationLabel = 'عمولة المطاعم';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.restaurant-financial-settings';

    public static function canAccess(): bool
    {
        return self::dashboardAllowed('platform_finance.view');
    }

    public function mount(): void
    {
        $setting = RestaurantFinancialSetting::query()->latest('id')->first();

        if ($setting !== null) {
            $this->commissionType = (string) $setting->commission_type;
            $this->commissionValue = (float) $setting->commission_value;
        }
    }

    public function save(): void
    {
        abort_unless(self::dashboardAllowed('platform_finance.update'), 403);

        $rules = [
            'commissionType' => ['required', 'in:percent,fixed'],
            'commissionValue' => ['required', 'numeric', 'min:0'],
        ];

        if ($this->commissionType === 'percent') {
            $rules['commissionValue'][] = 'max:100';
        }

        $this->validate($rules);

        $current = RestaurantFinancialSetting::query()->latest('id')->first();
        $base = $current !== null
            ? Arr::only($current->toArray(), $current->getFillable())
            : [];

        $next = RestaurantFinancialSetting::query()->create([
            ...$base,
            'commission_type' => $this->commissionType,
            'commission_value' => round($this->commissionValue, 2),
        ]);

        activity('restaurant_financial_settings')
            ->causedBy(auth()->user())
            ->performedOn($next)
            ->withProperties([
                'previous_setting_id' => $current?->id,
                'commission_type' => $next->commission_type,
                'commission_value' => (float) $next->commission_value,
            ])
            ->log('restaurant_financial_setting_updated');

        Notification::make()
            ->title('تم حفظ عمولة المطاعم')
            ->body('سيتم تطبيقها على الطلبات الجديدة فقط، أما الطلبات القديمة فتبقى على snapshot المحفوظة.')
            ->success()
            ->send();
    }

    public function canUpdateSetting(): bool
    {
        return self::dashboardAllowed('platform_finance.update');
    }

    public function getTitle(): string
    {
        return 'إعداد عمولة المطاعم';
    }

    public function getSubheading(): ?string
    {
        return 'تحديد قاعدة العمولة المستخدمة عند إنشاء financial snapshot للطلبات الجديدة.';
    }
}
