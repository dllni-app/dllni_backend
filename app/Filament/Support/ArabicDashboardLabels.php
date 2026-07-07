<?php

declare(strict_types=1);

namespace App\Filament\Support;

final class ArabicDashboardLabels
{
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

    public static function permissionName(string $permission): string
    {
        [$resource, $action] = array_pad(explode('.', $permission, 2), 2, null);

        $resourceLabel = self::permissionResourceLabel($resource);
        $actionLabel = self::permissionActionLabel($action ?? 'view');

        if ($resourceLabel === null) {
            return str($permission)->replace(['_', '.'], ' ')->headline()->toString();
        }

        return trim($actionLabel.' '.$resourceLabel);
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

    private static function permissionResourceLabel(?string $resource): ?string
    {
        return match ($resource) {
            'admins', 'admin_users' => 'مدراء النظام',
            'banners' => 'البنرات',
            'bookings', 'orders' => 'الطلبات',
            'catalog' => 'الكتالوج',
            'categories' => 'التصنيفات',
            'cleaning_bookings' => 'حجوزات التنظيف',
            'cleaning_services' => 'خدمات التنظيف',
            'coupons' => 'الكوبونات',
            'disputes' => 'النزاعات',
            'financial_settings' => 'الإعدادات المالية',
            'inventory' => 'المخزون',
            'offers' => 'العروض',
            'permissions' => 'الصلاحيات',
            'products' => 'المنتجات',
            'reports' => 'التقارير',
            'roles' => 'الأدوار',
            'sos_alerts' => 'تنبيهات SOS',
            'stores' => 'المتاجر',
            'users' => 'المستخدمين',
            'workers' => 'العاملين',
            null, '' => null,
            default => str($resource)->replace('_', ' ')->headline()->toString(),
        };
    }

    private static function permissionActionLabel(?string $action): string
    {
        return match ($action) {
            'view', 'list' => 'عرض',
            'create' => 'إنشاء',
            'update', 'edit' => 'تعديل',
            'delete' => 'حذف',
            'approve' => 'قبول',
            'reject' => 'رفض',
            'manage' => 'إدارة',
            'export' => 'تصدير',
            'import' => 'استيراد',
            'resolve' => 'حل',
            'acknowledge' => 'إقرار',
            null, '' => '',
            default => str($action)->replace('_', ' ')->headline()->toString(),
        };
    }
}
