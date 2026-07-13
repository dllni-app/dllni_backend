<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Illuminate\Support\Facades\Lang;

final class ArabicDashboardLabels
{
    private const array PermissionActions = [
        'view',
        'list',
        'create',
        'update',
        'edit',
        'delete',
        'approve',
        'reject',
        'manage',
        'export',
        'import',
        'resolve',
        'acknowledge',
    ];

    public static function money(float|int|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return number_format((float) $value, 2).' ل.س';
    }

    public static function guardName(?string $guard): string
    {
        return match ($guard) {
            'web' => 'ويب',
            'api' => 'واجهة برمجة التطبيقات',
            null, '' => '—',
            default => $guard,
        };
    }

    public static function roleName(?string $name): string
    {
        return match ($name) {
            'admin' => 'مدير',
            'Super Admin' => 'مدير أعلى',
            'Cleaning Ops Manager' => 'مدير عمليات التنظيف',
            'Customer Support' => 'دعم العملاء',
            'Onboarding Specialist' => 'مسؤول الانضمام',
            'Accountant' => 'محاسب',
            'delivery_company_admin' => 'مدير شركة توصيل',
            'delivery_company_staff' => 'موظف شركة توصيل',
            null, '' => '—',
            default => str($name)->replace(['_', '-'], ' ')->headline()->toString(),
        };
    }

    public static function permissionName(string $permission, ?string $slug = null): string
    {
        if (filled($slug)) {
            return trim((string) $slug);
        }

        [$resource, $action] = self::permissionParts($permission);
        $resourceLabel = self::permissionResourceLabel($resource);

        if ($action === null) {
            return $resourceLabel;
        }

        return trim(self::permissionActionLabel($action).' '.$resourceLabel);
    }

    public static function permissionSectionName(string $permission, ?string $group = null): string
    {
        if (filled($group) && Lang::has("permissions.sections.{$group}")) {
            return __("permissions.sections.{$group}");
        }

        [$resource] = self::permissionParts($permission);

        if (in_array($resource, ['admins', 'admin_users', 'users', 'roles', 'permissions'], true)) {
            return __('permissions.sections.administration');
        }

        return self::permissionResourceLabel($resource);
    }

    public static function depositStatus(?string $status): string
    {
        return match ($status) {
            'active' => 'نشط',
            'insufficient_balance' => 'رصيد غير كافٍ',
            'missing_deposit' => 'لا يوجد تأمين',
            'suspended' => 'موقوف',
            null, '' => 'غير معروف',
            default => str($status)->replace('_', ' ')->headline()->toString(),
        };
    }

    /** @return array{0: string, 1: string|null} */
    private static function permissionParts(string $permission): array
    {
        $segments = explode('.', $permission);
        $candidateAction = count($segments) > 1 ? end($segments) : null;

        if (is_string($candidateAction) && in_array($candidateAction, self::PermissionActions, true)) {
            array_pop($segments);

            return [self::normalizePermissionResource(implode('.', $segments)), $candidateAction];
        }

        if (str_starts_with($permission, 'ro.')) {
            return [self::normalizePermissionResource(substr($permission, 3)), null];
        }

        return [self::normalizePermissionResource($permission), null];
    }

    private static function normalizePermissionResource(string $resource): string
    {
        $resource = str_replace('.', '_', $resource);

        if (str_starts_with($resource, 'sm_')) {
            $resource = substr($resource, 3);
        }

        return $resource;
    }

    private static function permissionResourceLabel(string $resource): string
    {
        $key = "permissions.resources.{$resource}";

        if (Lang::has($key)) {
            return __($key);
        }

        return str($resource)->replace(['_', '.'], ' ')->headline()->toString();
    }

    private static function permissionActionLabel(string $action): string
    {
        $key = "permissions.actions.{$action}";

        if (Lang::has($key)) {
            return __($key);
        }

        return str($action)->replace('_', ' ')->headline()->toString();
    }
}
