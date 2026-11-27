<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

/** Presentation only. Never rounds an authoritative payable fraction away. */
final class CleaningV3DisplayFormatter
{
    public static function money(mixed $amount): string
    {
        if ($amount === null || ! is_numeric($amount)) {
            return '—';
        }
        $decimal = (string) $amount;
        if (! preg_match('/^-?\d+(?:\.\d+)?$/D', $decimal)) {
            return 'يتطلب مراجعة المبلغ';
        }
        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        if (trim($fraction, '0') !== '') {
            return 'يتطلب مراجعة المبلغ';
        }
        return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole).' ل.س';
    }

    public static function duration(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        $hourLabel = match ($hours) {
            0 => '',
            1 => 'ساعة',
            2 => 'ساعتان',
            default => $hours.' ساعة',
        };
        return $rest === 0 && $hours > 0 ? $hourLabel
            : ($hours > 0 ? $hourLabel.' و' : '').$rest.' دقيقة';
    }

    public static function state(string $state): string
    {
        return match ($state) {
            'pending_operational_review' => 'قيد المراجعة التشغيلية',
            'searching' => 'البحث عن العمال',
            'reschedule_required' => 'يتطلب مراجعة الموعد',
            'scheduled', 'worker_assigned' => 'مجدول',
            'travelling' => 'في الطريق',
            'arrived' => 'وصل الفريق',
            'paused' => 'متوقف مؤقتاً',
            default => \Modules\Cleaning\Enums\CleaningBookingStatus::tryFrom($state)?->label() ?? '—',
        };
    }
}
