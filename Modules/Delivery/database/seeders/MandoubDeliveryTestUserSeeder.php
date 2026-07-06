<?php

declare(strict_types=1);

namespace Modules\Delivery\Database\Seeders;

use App\Enums\DisputeCategory;
use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use App\Enums\UserModuleType;
use App\Models\Dispute;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Delivery\Enums\DeliveryAssignmentAttemptStatus;
use Modules\Delivery\Enums\DeliveryDriverAvailabilityStatus;
use Modules\Delivery\Enums\DeliveryFinancialDirection;
use Modules\Delivery\Enums\DeliveryFinancialTransactionType;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryAssignmentAttempt;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryDriverLocation;
use Modules\Delivery\Models\DeliveryDriverTrustLog;
use Modules\Delivery\Models\DeliveryFinancialAccount;
use Modules\Delivery\Models\DeliveryFinancialTransaction;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Models\DeliveryOrderEvent;

final class MandoubDeliveryTestUserSeeder extends Seeder
{
    private const TEST_EMAIL = 'mandoub.test@dllni.sy';
    private const TEST_PHONE = '+963900000001';
    private const TEST_PASSWORD = 'secret123';

    public function run(): void
    {
        $owner = $this->ownerUser();
        $company = $this->company($owner);
        $user = $this->testUser();
        $driver = $this->driver($company, $user);

        $orders = $this->orders($company, $owner, $driver);

        $this->assignmentAttempts($driver, $orders);
        $this->orderEvents($owner, $driver, $orders);
        $this->locations($driver);
        $this->financialAccount($driver, $owner, $orders);
        $this->disputeAndTrustLog($driver, $orders['cancelled']);
        $this->notifications($driver, $orders);
    }

    private function ownerUser(): User
    {
        return User::updateOrCreate(
            ['email' => 'delivery.owner@dllni.sy'],
            [
                'name' => 'Delivery Owner',
                'phone' => '+963944700001',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
            ],
        )->fresh();
    }

    private function company(User $owner): DeliveryCompany
    {
        return DeliveryCompany::updateOrCreate(
            ['owner_user_id' => $owner->id],
            [
                'name' => 'Dllni Fast Delivery',
                'legal_name' => 'Dllni Fast Delivery LLC',
                'phone' => '+963211123456',
                'email' => 'ops@dllni-delivery.sy',
                'address' => 'Aleppo, Syria',
                'latitude' => 36.20210411,
                'longitude' => 37.13426044,
                'is_active' => true,
                'is_suspended' => false,
                'suspension_reason' => null,
                'suspended_until' => null,
                'financial_limit' => 350000,
            ],
        )->fresh();
    }

    private function testUser(): User
    {
        return User::updateOrCreate(
            ['email' => self::TEST_EMAIL],
            [
                'name' => 'مندوب اختبار',
                'phone' => self::TEST_PHONE,
                'module_type' => UserModuleType::DeliveryDriver->value,
                'password' => bcrypt(self::TEST_PASSWORD),
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
            ],
        )->fresh();
    }

    private function driver(DeliveryCompany $company, User $user): DeliveryDriver
    {
        return DeliveryDriver::updateOrCreate(
            ['user_id' => $user->id],
            [
                'company_id' => $company->id,
                'first_name' => 'مندوب الاختبار',
                'phone' => self::TEST_PHONE,
                'vehicle_type' => 'motorbike',
                'plate_number' => 'TEST-9001',
                'availability_status' => DeliveryDriverAvailabilityStatus::Available->value,
                'is_active' => true,
                'is_suspended' => false,
                'suspended_until' => null,
                'suspension_reason' => null,
                'trust_score' => 98,
                'open_disputes_count' => 1,
                'last_seen_at' => now()->subMinute(),
            ],
        )->fresh();
    }

    /**
     * @return array<string, DeliveryOrder>
     */
    private function orders(DeliveryCompany $company, User $owner, DeliveryDriver $driver): array
    {
        return [
            'active' => $this->order(
                company: $company,
                owner: $owner,
                driver: $driver,
                number: 'DLV-MANDOUB-ACTIVE',
                status: DeliveryOrderStatus::Accepted,
                customerName: 'عميل الطلب النشط',
                customerPhone: '+963900000201',
                pickupAddress: 'حلب - الجميلية - متجر الاختبار',
                dropoffAddress: 'حلب - الفرقان - منزل الاختبار',
                fee: 15000,
                createdAt: now()->subMinutes(50),
            ),
            'completed' => $this->order(
                company: $company,
                owner: $owner,
                driver: $driver,
                number: 'DLV-MANDOUB-COMPLETED',
                status: DeliveryOrderStatus::Completed,
                customerName: 'عميل طلب مكتمل',
                customerPhone: '+963900000202',
                pickupAddress: 'حلب - العزيزية - مطعم الاختبار',
                dropoffAddress: 'حلب - المحافظة - بناء الاختبار',
                fee: 12500,
                createdAt: now()->subHours(5),
            ),
            'cancelled' => $this->order(
                company: $company,
                owner: $owner,
                driver: $driver,
                number: 'DLV-MANDOUB-CANCELLED',
                status: DeliveryOrderStatus::Cancelled,
                customerName: 'عميل بلاغ اختبار',
                customerPhone: '+963900000203',
                pickupAddress: 'حلب - الشهباء - نقطة استلام',
                dropoffAddress: 'حلب - الحمدانية - نقطة تسليم',
                fee: 9000,
                createdAt: now()->subDay(),
            ),
            'offer' => $this->order(
                company: $company,
                owner: $owner,
                driver: null,
                number: 'DLV-MANDOUB-OFFER',
                status: DeliveryOrderStatus::Offered,
                customerName: 'عميل عرض جديد',
                customerPhone: '+963900000204',
                pickupAddress: 'حلب - ساحة الجامعة - سوبرماركت الاختبار',
                dropoffAddress: 'حلب - الموكامبو - عنوان العميل',
                fee: 18000,
                createdAt: now()->subMinutes(4),
            ),
        ];
    }

    private function order(
        DeliveryCompany $company,
        User $owner,
        ?DeliveryDriver $driver,
        string $number,
        DeliveryOrderStatus $status,
        string $customerName,
        string $customerPhone,
        string $pickupAddress,
        string $dropoffAddress,
        float $fee,
        \Illuminate\Support\Carbon $createdAt,
    ): DeliveryOrder {
        $acceptedAt = in_array($status, [DeliveryOrderStatus::Accepted, DeliveryOrderStatus::InProgress, DeliveryOrderStatus::PickedUp, DeliveryOrderStatus::Delivered, DeliveryOrderStatus::Completed, DeliveryOrderStatus::Cancelled], true)
            ? $createdAt->copy()->addMinutes(6)
            : null;
        $startedAt = in_array($status, [DeliveryOrderStatus::InProgress, DeliveryOrderStatus::PickedUp, DeliveryOrderStatus::Delivered, DeliveryOrderStatus::Completed], true)
            ? $createdAt->copy()->addMinutes(14)
            : null;
        $pickedAt = in_array($status, [DeliveryOrderStatus::PickedUp, DeliveryOrderStatus::Delivered, DeliveryOrderStatus::Completed], true)
            ? $createdAt->copy()->addMinutes(28)
            : null;
        $deliveredAt = in_array($status, [DeliveryOrderStatus::Delivered, DeliveryOrderStatus::Completed], true)
            ? $createdAt->copy()->addMinutes(48)
            : null;
        $completedAt = $status === DeliveryOrderStatus::Completed ? $createdAt->copy()->addMinutes(52) : null;
        $cancelledAt = $status === DeliveryOrderStatus::Cancelled ? $createdAt->copy()->addMinutes(18) : null;

        return DeliveryOrder::updateOrCreate(
            ['order_number' => $number],
            [
                'company_id' => $company->id,
                'driver_id' => $driver?->id,
                'created_by_user_id' => $owner->id,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'customer_notes' => 'بيانات اختبار كاملة لتطبيق المندوب.',
                'pickup_address' => $pickupAddress,
                'pickup_latitude' => 36.20200000,
                'pickup_longitude' => 37.13400000,
                'dropoff_address' => $dropoffAddress,
                'dropoff_latitude' => 36.21400000,
                'dropoff_longitude' => 37.14900000,
                'distance_km' => 4.6,
                'delivery_fee' => $fee,
                'currency' => 'SYP',
                'status' => $status->value,
                'accepted_at' => $acceptedAt,
                'started_at' => $startedAt,
                'picked_up_at' => $pickedAt,
                'delivered_at' => $deliveredAt,
                'completed_at' => $completedAt,
                'cancelled_at' => $cancelledAt,
                'stopped_at' => null,
                'cancel_reason' => $status === DeliveryOrderStatus::Cancelled ? 'اختبار بلاغ مرتبط بطلب ملغي.' : null,
                'stop_reason' => null,
                'created_at' => $createdAt,
                'updated_at' => $completedAt ?? $cancelledAt ?? $startedAt ?? $acceptedAt ?? $createdAt,
            ],
        )->fresh();
    }

    /**
     * @param  array<string, DeliveryOrder>  $orders
     */
    private function assignmentAttempts(DeliveryDriver $driver, array $orders): void
    {
        DeliveryAssignmentAttempt::updateOrCreate(
            ['order_id' => $orders['active']->id, 'driver_id' => $driver->id, 'attempt_no' => 1],
            [
                'status' => DeliveryAssignmentAttemptStatus::Accepted->value,
                'distance_to_pickup_km' => 1.1,
                'offered_at' => now()->subHour(),
                'expires_at' => now()->subHour()->addMinutes(3),
                'responded_at' => now()->subMinutes(54),
                'reject_reason' => null,
            ],
        );

        DeliveryAssignmentAttempt::updateOrCreate(
            ['order_id' => $orders['completed']->id, 'driver_id' => $driver->id, 'attempt_no' => 1],
            [
                'status' => DeliveryAssignmentAttemptStatus::Accepted->value,
                'distance_to_pickup_km' => 1.8,
                'offered_at' => now()->subHours(5),
                'expires_at' => now()->subHours(5)->addMinutes(3),
                'responded_at' => now()->subHours(5)->addMinutes(1),
                'reject_reason' => null,
            ],
        );

        DeliveryAssignmentAttempt::updateOrCreate(
            ['order_id' => $orders['offer']->id, 'driver_id' => $driver->id, 'attempt_no' => 1],
            [
                'status' => DeliveryAssignmentAttemptStatus::Open->value,
                'distance_to_pickup_km' => 0.9,
                'offered_at' => now()->subMinute(),
                'expires_at' => now()->addMinutes(10),
                'responded_at' => null,
                'reject_reason' => null,
            ],
        );
    }

    /**
     * @param  array<string, DeliveryOrder>  $orders
     */
    private function orderEvents(User $owner, DeliveryDriver $driver, array $orders): void
    {
        foreach ($orders as $order) {
            DeliveryOrderEvent::updateOrCreate(
                ['order_id' => $order->id, 'to_status' => DeliveryOrderStatus::New->value],
                [
                    'actor_type' => User::class,
                    'actor_id' => $owner->id,
                    'from_status' => null,
                    'note' => 'Mandoub test order created',
                    'payload' => ['seed' => 'mandoub_test_user'],
                ],
            );

            DeliveryOrderEvent::updateOrCreate(
                ['order_id' => $order->id, 'to_status' => $order->status],
                [
                    'actor_type' => DeliveryDriver::class,
                    'actor_id' => $order->driver_id ?? $driver->id,
                    'from_status' => $order->driver_id !== null ? DeliveryOrderStatus::Accepted->value : DeliveryOrderStatus::Dispatching->value,
                    'note' => 'Mandoub test order status '.$order->status,
                    'payload' => ['seed' => 'mandoub_test_user', 'currentStatus' => $order->status],
                ],
            );
        }
    }

    private function locations(DeliveryDriver $driver): void
    {
        DeliveryDriverLocation::updateOrCreate(
            ['driver_id' => $driver->id, 'recorded_at' => now()->subMinutes(2)->startOfMinute()],
            [
                'latitude' => 36.20690000,
                'longitude' => 37.13890000,
                'accuracy' => 3.5,
                'speed' => 18.0,
                'heading' => 185,
            ],
        );
    }

    /**
     * @param  array<string, DeliveryOrder>  $orders
     */
    private function financialAccount(DeliveryDriver $driver, User $owner, array $orders): void
    {
        $account = DeliveryFinancialAccount::updateOrCreate(
            ['owner_type' => DeliveryDriver::class, 'owner_id' => $driver->id, 'currency' => 'SYP'],
            [
                'current_balance' => 87500,
                'financial_limit' => 150000,
                'is_suspended' => false,
                'suspension_reason' => null,
                'suspended_at' => null,
            ],
        );

        $rows = [
            [DeliveryFinancialTransactionType::OrderFeeDebit->value, DeliveryFinancialDirection::Debit->value, 12500, 100000, 87500, $orders['completed']->id, 'Seed completed order fee'],
            [DeliveryFinancialTransactionType::CollectionCredit->value, DeliveryFinancialDirection::Credit->value, 15000, 72500, 87500, $orders['active']->id, 'Seed active order collection'],
        ];

        foreach ($rows as $index => [$type, $direction, $amount, $before, $after, $referenceId, $note]) {
            DeliveryFinancialTransaction::updateOrCreate(
                ['account_id' => $account->id, 'transaction_type' => $type, 'reference_id' => $referenceId, 'amount' => $amount],
                [
                    'direction' => $direction,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'reference_type' => DeliveryOrder::class,
                    'note' => $note,
                    'metadata' => ['seed' => 'mandoub_test_user', 'index' => $index + 1],
                    'created_by_user_id' => $owner->id,
                    'created_at' => now()->subHours(4 - $index),
                    'updated_at' => now()->subHours(4 - $index),
                ],
            );
        }
    }

    private function disputeAndTrustLog(DeliveryDriver $driver, DeliveryOrder $cancelledOrder): void
    {
        $dispute = Dispute::withoutEvents(function () use ($cancelledOrder): Dispute {
            return Dispute::query()->updateOrCreate(
                ['ticket_number' => 'DLY-MANDOUB-DSP-0001'],
                [
                    'booking_id' => $cancelledOrder->id,
                    'booking_type' => 'delivery_order',
                    'description' => 'بلاغ اختبار مرتبط بمندوب الاختبار لعرض شاشة البلاغات.',
                    'category' => DisputeCategory::Other->value,
                    'status' => DisputeStatus::UnderReview->value,
                    'resolution' => DisputeResolution::Dismissed->value,
                    'worker_earnings_frozen' => false,
                ],
            );
        });

        DeliveryDriverTrustLog::updateOrCreate(
            ['driver_id' => $driver->id, 'reason' => 'mandoub_test_seed', 'score_delta' => -2],
            [
                'score_after' => 98,
                'related_dispute_id' => $dispute->id,
            ],
        );
    }

    /**
     * @param  array<string, DeliveryOrder>  $orders
     */
    private function notifications(DeliveryDriver $driver, array $orders): void
    {
        foreach ([
            ['title' => 'طلب توصيل جديد', 'body' => 'يوجد عرض توصيل جديد بانتظار قرارك.', 'order' => $orders['offer'], 'read' => false],
            ['title' => 'طلب نشط', 'body' => 'لديك طلب نشط جاهز للبدء.', 'order' => $orders['active'], 'read' => false],
            ['title' => 'تم إكمال طلب', 'body' => 'تم تسجيل طلب مكتمل في سجل الحركة.', 'order' => $orders['completed'], 'read' => true],
        ] as $index => $notification) {
            DB::table('notifications')->updateOrInsert(
                [
                    'notifiable_type' => User::class,
                    'notifiable_id' => $driver->user_id,
                    'type' => \Modules\Delivery\Notifications\DeliveryCanonicalNotification::class,
                    'data' => json_encode([
                        'module' => 'delivery',
                        'type' => 'delivery_order_update',
                        'category' => 'orders',
                        'priority' => $index === 0 ? 'high' : 'normal',
                        'title' => $notification['title'],
                        'body' => $notification['body'],
                        'message' => $notification['body'],
                        'orderId' => $notification['order']->id,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
                [
                    'id' => (string) Str::uuid(),
                    'read_at' => $notification['read'] ? now()->subHour() : null,
                    'created_at' => now()->subMinutes(20 - ($index * 5)),
                    'updated_at' => now()->subMinutes(20 - ($index * 5)),
                ],
            );
        }
    }
}
