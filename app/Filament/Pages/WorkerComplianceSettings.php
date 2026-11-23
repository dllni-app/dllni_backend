<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

final class WorkerComplianceSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 23;

    protected string $view = 'filament.pages.worker-compliance-settings';

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::cleaning();
    }

    public static function getNavigationLabel(): string
    {
        return __('worker_compliance.settings.nav');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('cleaning.worker_compliance.configure') ?? false;
    }

    public function getTitle(): string
    {
        return __('worker_compliance.settings.nav');
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $key = 'cleaning.worker_compliance.';
        $enabledPlatforms = config($key.'enabled_platforms', []);
        $policyUrl = (string) config($key.'privacy_policy_url', '');
        $hasSecureNotice = filter_var($policyUrl, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($policyUrl, PHP_URL_SCHEME)) === 'https';

        $status = static fn (bool $enabled): string => __(
            'worker_compliance.settings.'.($enabled ? 'enabled' : 'disabled'),
        );

        return [
            'configurationGroups' => [
                __('worker_compliance.settings.collection') => [
                    __('worker_compliance.settings.feature_enabled') => $status((bool) config($key.'enabled', false)),
                    __('worker_compliance.settings.platforms') => $enabledPlatforms === []
                        ? __('worker_compliance.settings.none')
                        : implode(', ', $enabledPlatforms),
                    __('worker_compliance.settings.notice') => $status($hasSecureNotice),
                    __('worker_compliance.settings.notice_version') => (string) config($key.'notice_version'),
                    __('worker_compliance.settings.sample_retention') => (string) config($key.'sample_retention_days', 7),
                    __('worker_compliance.settings.session_lease') => (string) config($key.'session_lease_minutes', 60),
                ],
                __('worker_compliance.settings.detection') => [
                    __('worker_compliance.settings.matching_enabled') => $status((bool) config($key.'matching_enabled', false)),
                    __('worker_compliance.settings.notifications_enabled') => $status((bool) config($key.'notifications_enabled', false)),
                    __('worker_compliance.settings.radius') => (string) config($key.'proximity_radius_m', 100),
                    __('worker_compliance.settings.dwell') => (string) config($key.'minimum_dwell_seconds', 600),
                    __('worker_compliance.settings.accuracy') => (string) config($key.'maximum_accuracy_m', 50),
                    __('worker_compliance.settings.minimum_samples') => (string) config($key.'minimum_sample_count', 4),
                    __('worker_compliance.settings.site_retention') => (string) config($key.'site_eligibility_retention_days', 90),
                    __('worker_compliance.settings.case_retention') => (string) config($key.'review_case_retention_days', 90),
                    __('worker_compliance.settings.audit_retention') => (string) config($key.'review_audit_retention_days', 180),
                    __('worker_compliance.settings.rule_version') => (string) config($key.'rule_version', 'pilot-v1'),
                ],
            ],
        ];
    }
}
