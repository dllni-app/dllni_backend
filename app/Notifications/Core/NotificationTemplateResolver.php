<?php

declare(strict_types=1);

namespace App\Notifications\Core;

final class NotificationTemplateResolver
{
    public function __construct(
        private readonly NotificationTypeRegistry $registry,
    ) {}

    /**
     * @param  array<string, scalar|null>  $context
     * @return array{title: string, body: string}
     */
    public function resolve(string $canonicalType, array $context = [], ?string $locale = null): array
    {
        $definition = $this->registry->definition($canonicalType);
        $templates = is_array($definition['templates'] ?? null) ? $definition['templates'] : [];

        $resolvedLocale = $this->resolveLocale($templates, $locale);
        $template = $this->templateFor($canonicalType, $templates, $resolvedLocale);

        $title = (string) ($template['title'] ?? '');
        $body = (string) ($template['body'] ?? '');

        return [
            'title' => $this->interpolate($title, $context),
            'body' => $this->interpolate($body, $context),
        ];
    }

    /**
     * @param  array<string, mixed>  $templates
     */
    private function resolveLocale(array $templates, ?string $requestedLocale): string
    {
        $requested = is_string($requestedLocale) ? mb_strtolower($requestedLocale) : '';
        if ($requested !== '' && isset($templates[$requested])) {
            return $requested;
        }

        $default = $this->registry->defaultLocale();
        if (isset($templates[$default])) {
            return $default;
        }

        $fallback = $this->registry->fallbackLocale();
        if (isset($templates[$fallback])) {
            return $fallback;
        }

        $firstKey = array_key_first($templates);

        return is_string($firstKey) ? $firstKey : 'ar';
    }

    /**
     * @param  array<string, mixed>  $templates
     * @return array{title?: string, body?: string}
     */
    private function templateFor(string $canonicalType, array $templates, string $resolvedLocale): array
    {
        $default = $this->registry->defaultLocale();

        if ($default === 'ar') {
            $arabicTemplate = $this->arabicFallbackTemplate($canonicalType);

            if ($arabicTemplate !== null && ! isset($templates['ar'])) {
                return $arabicTemplate;
            }
        }

        $template = is_array($templates[$resolvedLocale] ?? null) ? $templates[$resolvedLocale] : [];

        return $template;
    }

    /**
     * @return array{title: string, body: string}|null
     */
    private function arabicFallbackTemplate(string $canonicalType): ?array
    {
        return match ($canonicalType) {
            'cleaning.booking.worker_assigned' => [
                'title' => 'تم تعيين عامل',
                'body' => 'تم تعيين عامل للحجز رقم :booking_number.',
            ],
            'cleaning.booking.worker_confirmed' => [
                'title' => 'تم تأكيد العامل',
                'body' => 'أكد العامل حجز التنظيف رقم :booking_number.',
            ],
            'cleaning.booking.worker_started_travel' => [
                'title' => 'العامل في الطريق',
                'body' => 'بدأ العامل التوجه إلى حجز التنظيف رقم :booking_number.',
            ],
            'cleaning.booking.worker_arrived' => [
                'title' => 'وصل العامل',
                'body' => 'وصل العامل إلى موقع حجز التنظيف رقم :booking_number.',
            ],
            'cleaning.booking.start_verified' => [
                'title' => 'تم تأكيد بدء العمل',
                'body' => 'أكد العميل بدء العمل لحجز التنظيف رقم :booking_number.',
            ],
            'cleaning.booking.completion_requested' => [
                'title' => 'طلب تأكيد الإكمال',
                'body' => 'طلب العامل تأكيد إكمال حجز التنظيف رقم :booking_number.',
            ],
            'cleaning.booking.completion_approved' => [
                'title' => 'تم تأكيد الإكمال',
                'body' => 'أكد العميل إكمال حجز التنظيف رقم :booking_number.',
            ],
            'cleaning.booking.completion_rejected' => [
                'title' => 'تم رفض الإكمال',
                'body' => 'رفض العميل إكمال حجز التنظيف رقم :booking_number.',
            ],
            'cleaning.booking.time_extension_requested' => [
                'title' => 'طلب تمديد وقت',
                'body' => 'طلب العميل وقتاً إضافياً لحجز التنظيف رقم :booking_number.',
            ],
            'cleaning.booking.order_cancelled' => [
                'title' => 'تم إلغاء الطلب',
                'body' => 'تم إلغاء حجز التنظيف رقم :booking_number.',
            ],
            default => null,
        };
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    private function interpolate(string $template, array $context): string
    {
        if ($template === '' || $context === []) {
            return $template;
        }

        $replace = [];
        foreach ($context as $key => $value) {
            $replace[':'.(string) $key] = $value === null ? '' : (string) $value;
        }

        return strtr($template, $replace);
    }
}
