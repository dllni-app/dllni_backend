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
        $template = is_array($templates[$resolvedLocale] ?? null) ? $templates[$resolvedLocale] : [];

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

        return is_string($firstKey) ? $firstKey : 'en';
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
