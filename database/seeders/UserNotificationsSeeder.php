<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class UserNotificationsSeeder extends Seeder
{
    private const string SeedTag = 'user-notifications-seeder';

    private const string DatabaseType = 'Illuminate\\Notifications\\DatabaseNotification';

    public function run(): void
    {
        $this->seedAdminNotifications();
        $this->clearSeededNotificationsForUsers([
            '+963944100001',
            '+963944100002',
            '+963944100003',
            '+963944000222',
            'user@dllni.sy',
        ]);
        $this->seedCleaningWorkerNotifications();
        $this->seedRestaurantSellerNotifications();
        $this->seedSupermarketSellerNotifications();
        $this->seedAppUserNotifications();
    }

    private function seedAdminNotifications(): void
    {
        $this->seedForUser('admin@admin.com', [
            [
                'module' => 'restaurant',
                'type' => 'order_update',
                'title' => 'طلب مطعم جديد',
                'body' => 'يوجد طلب مطعم يحتاج متابعة من لوحة الإدارة.',
                'read_at' => null,
            ],
            [
                'module' => 'supermarket',
                'type' => 'inventory_alert',
                'title' => 'تنبيه مخزون',
                'body' => 'أحد منتجات السوبرماركت اقترب من النفاد.',
                'read_at' => now()->subMinutes(20),
            ],
            [
                'module' => 'cleaning',
                'type' => 'dispute_opened',
                'title' => 'نزاع جديد',
                'body' => 'تم فتح نزاع جديد على حجز تنظيف.',
                'disputeId' => 301,
                'read_at' => null,
            ],
        ]);
    }

    private function seedCleaningWorkerNotifications(): void
    {
        $this->seedForUser('+963944100001', [
            [
                'module' => 'cleaning',
                'type' => 'new_order',
                'title' => 'طلب تنظيف جديد',
                'body' => 'يوجد طلب تنظيف جديد بانتظار القبول.',
                'bookingId' => 2001,
                'read_at' => null,
            ],
            [
                'module' => 'cleaning',
                'type' => 'extension_request',
                'title' => 'طلب تمديد',
                'body' => 'العميل طلب تمديد مدة الحجز الحالية.',
                'bookingId' => 2002,
                'read_at' => now()->subMinutes(10),
            ],
            [
                'module' => 'cleaning',
                'type' => 'dispute_opened',
                'title' => 'نزاع على الحجز',
                'body' => 'تم تسجيل نزاع جديد حول إحدى الحجوزات.',
                'disputeId' => 3001,
                'read_at' => null,
            ],
        ]);
    }

    private function seedRestaurantSellerNotifications(): void
    {
        $this->seedForUser('+963944100002', [
            [
                'module' => 'restaurant',
                'type' => 'order_update',
                'title' => 'طلب مطعم جديد',
                'body' => 'وصل طلب مطعم جديد يحتاج تجهيز.',
                'read_at' => null,
            ],
            [
                'module' => 'restaurant',
                'type' => 'order_update',
                'title' => 'الطلب في التحضير',
                'body' => 'تم نقل الطلب إلى حالة التحضير.',
                'read_at' => now()->subMinutes(15),
            ],
            [
                'module' => 'restaurant',
                'type' => 'order_update',
                'title' => 'تقييم جديد',
                'body' => 'حصل المطعم على تقييم جديد من أحد العملاء.',
                'read_at' => null,
            ],
        ]);
    }

    private function seedSupermarketSellerNotifications(): void
    {
        $this->seedForUser('+963944100003', [
            [
                'module' => 'supermarket',
                'type' => 'smart_list_scheduled_order_sent',
                'title' => 'طلب ذكي جديد',
                'body' => 'تم إنشاء طلب مجدول جديد من قائمة ذكية.',
                'read_at' => null,
            ],
            [
                'module' => 'supermarket',
                'type' => 'smart_list_scheduled_order_failed',
                'title' => 'فشل إنشاء طلب',
                'body' => 'تعذر إرسال أحد الطلبات المجدولة إلى المنفذ.',
                'read_at' => now()->subMinutes(8),
            ],
            [
                'module' => 'supermarket',
                'type' => 'inventory_alert',
                'title' => 'مخزون منخفض',
                'body' => 'منتج مطلوب يحتاج إعادة تعبئة عاجلة.',
                'read_at' => null,
            ],
        ]);
    }

    private function seedAppUserNotifications(): void
    {
        $this->seedForUser('user@dllni.sy', [
            [
                'module' => 'cleaning',
                'type' => 'new_order',
                'title' => 'حجز تنظيف جديد',
                'body' => 'تم تأكيد حجز التنظيف الخاص بك بنجاح.',
                'bookingId' => 101,
                'read_at' => null,
            ],
            [
                'module' => 'restaurant',
                'type' => 'order_update',
                'title' => 'الطلب قيد التحضير',
                'body' => 'طلبك في المطعم أصبح قيد التحضير الآن.',
                'read_at' => null,
            ],
            [
                'module' => 'supermarket',
                'type' => 'smart_list_scheduled_order_sent',
                'title' => 'تم إرسال الطلب المجدول',
                'body' => 'تم إرسال أحد الطلبات المجدولة من قائمة التسوق الذكية.',
                'read_at' => now()->subMinutes(5),
            ],
            [
                'type' => 'account',
                'title' => 'تذكير بالحساب',
                'body' => 'يرجى مراجعة إعدادات حسابك وتحديث بياناتك عند الحاجة.',
                'read_at' => now()->subHours(2),
            ],
        ]);
    }

    /**
     * @param  array<int, string>  $identifiers
     */
    private function clearSeededNotificationsForUsers(array $identifiers): void
    {
        foreach ($identifiers as $identifier) {
            $user = User::query()
                ->where('email', $identifier)
                ->orWhere('phone', $identifier)
                ->first();

            if ($user === null) {
                continue;
            }

            $user->notifications()
                ->where('data->seedTag', self::SeedTag)
                ->delete();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $notifications
     */
    private function seedForUser(string $identifier, array $notifications): void
    {
        $user = User::query()
            ->where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->first();

        if ($user === null) {
            return;
        }

        $user->notifications()
            ->where('data->seedTag', self::SeedTag)
            ->delete();

        foreach ($notifications as $notification) {
            $readAt = $notification['read_at'] ?? null;
            unset($notification['read_at']);

            $user->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => self::DatabaseType,
                'data' => [...$notification, 'seedTag' => self::SeedTag],
                'read_at' => $readAt,
            ]);
        }
    }
}
