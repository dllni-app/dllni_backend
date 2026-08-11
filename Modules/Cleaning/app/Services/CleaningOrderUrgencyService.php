<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;

final class CleaningOrderUrgencyService
{
    public const HOT_ORDER_PREFIX = '[🚨 طلب ساخن - تنفيذ فوري عاجل]';
    public const HOT_ORDER_LABEL = 'طلب ساخن - تنفيذ فوري عاجل';

    public function isHotOrder(mixed $scheduledDate): bool
    {
        $date = $this->normalizeScheduledDate($scheduledDate);

        if (! $date instanceof CarbonInterface) {
            return false;
        }

        return $date->isSameDay(now(config('app.timezone')));
    }

    public function displayTitle(string $baseTitle, mixed $scheduledDate): string
    {
        $baseTitle = mb_trim($baseTitle);

        if (! $this->isHotOrder($scheduledDate)) {
            return $baseTitle;
        }

        return $this->prependHotPrefix($baseTitle);
    }

    public function prependHotPrefix(string $title): string
    {
        $title = mb_trim($title);

        if (str_starts_with($title, self::HOT_ORDER_PREFIX)) {
            return $title;
        }

        return self::HOT_ORDER_PREFIX.' '.$title;
    }

    private function normalizeScheduledDate(mixed $scheduledDate): ?CarbonInterface
    {
        if ($scheduledDate instanceof CarbonInterface) {
            return $scheduledDate->copy()->timezone(config('app.timezone'));
        }

        if (is_string($scheduledDate) && mb_trim($scheduledDate) !== '') {
            return Carbon::parse($scheduledDate, config('app.timezone'));
        }

        return null;
    }
}
