<?php

declare(strict_types=1);

namespace Modules\Delivery\Database\Seeders;

use App\Enums\DisputeCategory;
use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use App\Enums\UserModuleType;
use App\Models\Dispute;
use App\Models\User;
use Carbon\CarbonInterface;
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
    private const TEST_PASSWORD = 'secret123';

    /**
     * @var array<string, array{email: string, phone: string, name: string, first_name: string, vehicle_type: string, plate_number: string}>
     */
    private const TEST_DRIVERS = [
        'primary' => [
            'email' => 'mandoub.test@dllni.sy',
            'phone' => '+963900000001',
            'name' => 'مندوب اختبار',
            'first_name' => 'مندوب الاختبار',
            'vehicle_type' => 'motorbike',
            'plate_number' => 'TEST-9001',
        ],
        'nearby' => [
            'email' => 'mandoub.nearby@dllni.sy',
            'phone' => '+963900000002',
            'name' => 'مندوب قريب',
            'first_name' => 'مندوب قريب',
            'vehicle_type' => 'motorbike',
            'plate_number' => 'TEST-9002',
        ],
        'fallback' => [
            'email' => 'mandoub.fallback@dllni.sy',
            'phone' => '+963900000003',
            'name' => 'مندوب بديل',
            'first_name' => 'مندوب بديل',
            'vehicle_type' => 'car',
            'plate_number' => 'TEST-9003',
        ],
        'offline' => [
            'email' => 'mandoub.offline@dllni.sy',
            'phone' => '+963900000004',
            'name' => 'مندوب غير متصل',
            'first_name' => 'مندوب غير متصل',
            'vehicle_type' => 'motorbike',
            'plate_number' => 'TEST-9004',
        ],
    ];

    public function run(): void
    {
        $owner = $this->ownerUser();
        $company = $this->company($owner);
        $users = $this->testUsers();
        $drivers = $this->drivers($company, $users);
        $orders = $this->orders($company, $owner, $drivers['primary']);

        $this->assignmentAttempts($drivers['primary'], $drivers['nearby'], $orders);
        $this->orderEvents($owner, $drivers['primary'], $drivers['nearby'], $orders);
        $this->locations($drivers);
        $this->financialAccounts($drivers, $owner, $orders);
        $this->disputeAndTrustLog($drivers['primary'], $orders['cancelled']);
        $this->notifications($drivers['primary'], $drivers['nearby'], $orders);
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

    /** @return array<string, User> */
    private function testUsers(): array
    {
        $users = [];

        foreach (self::TEST_DRIVERS as $key => $profile) {
            $users[$key] = User::updateOrCreate(
                ['email' => $profile['email']],
                [
                    'name' => $profile['name'],
                    'phone' => $profile['phone'],
                    'module_type' => UserModuleType::DeliveryDriver->value,
                    'password' => bcrypt(self::TEST_PASSWORD),
                    'email_verified_at' => now(),
                    'phone_verified_at' => now(),
                ],
            )->fresh();
        }

        return $users;
    }

    /**
     * @param  array<string, User>  $users
     * @return array<string, DeliveryDriver>
     */
    private function drivers(DeliveryCompany $company, array $users): array
    {
        $runtime = [
            'primary' => [
                'availability_status' => DeliveryDriverAvailabilityStatus::Busy->value,
                'trust_score' => 98,
                'open_disputes_count' => 1,
                'last_seen_at' => now()->subMinute(),
            ],
            'nearby' => [
                'availability_status' => DeliveryDriverAvailabilityStatus::Available->value,
                'trust_score' => 100,
                'open_disputes_count' => 0,
                'last_seen_at' => now()->subMinute(),
            ],
            'fallback' => [
                'availability_status' => DeliveryDriverAvailabilityStatus::Available->value,
                'trust_score' => 95,
                'open_disputes_count' => 0,
                'last_seen_at' => now()->subMinutes(2),
            ],
            'offline' => [
                'availability_status' => DeliveryDriverAvailabilityStatus::Offline->value,
                'trust_score' => 82,
                'open_disputes_count' => 0,
                'last_seen_at' => now()->subHours(2),
            ],
        ];

        $drivers = [];

        foreach ($users as $key => $user) {
            $profile = self::TEST_DRIVERS[$key];
            $state = $runtime[$key];

            $drivers[$key] = DeliveryDriver::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'company_id' => $company->id,
                    'first_name' => $profile['first_name'],
                    'phone' => $profile['phone'],
                    'vehicle_type' => $profile['vehicle_type'],
                    'plate_number' => $profile['plate_number'],
                    'availability_status' => $state['availability_status'],
                    'is_active' => true,
                    'is_suspended' => false,
                    'suspended_until' => null,
                    'suspension_reason' => null,
                    'trust_score' => $state['trust_score'],
                    'open_disputes_count' => $state['open_disputes_count'],
                    'last_seen_at' => $state['last_seen_at'],
                ],
            )->fresh();
        }

        return $drivers;
    }

    /** @return array<string, DeliveryOrder> */
    private function orders(DeliveryCompany $company, User $owner, DeliveryDriver $driver): array
    {
        return [
            'active' => $this->order($company, $owner, $driver, 'DLV-MANDOUB-ACTIVE', DeliveryOrderStatus::Accepted, 'عميل الطلب النشط', '+963900000201', 'حلب - الجميلية - متجر الاختبار', 'حلب - الفرقان - منزل الاختبار', 15000, now()->subMinutes(50)),
            'completed' => $this->order($company, $owner, $driver, 'DLV-MANDOUB-COMPLETED', DeliveryOrderStatus::Completed, 'عميل طلب مكتمل', '+963900000202', 'حلب - العزيزية - مطعم الاختبار', 'حلب - المحافظة - بناء الاختبار', 12500, now()->subHours(5)),
            'cancelled' => $this->order($company, $owner, $driver, 'DLV-MANDOUB-CANCELLED', DeliveryOrderStatus::Cancelled, 'عميل بلاغ اختبار', '+963900000203', 'حلب - الشهباء - نقطة استلام', 'حلب - الحمدانية - نقطة تسليم', 9000, now()->subDay()),
            'offer' => $this->order($company, $owner, null, 'DLV-MANDOUB-OFFER', DeliveryOrderStatus::Offered, 'عميل عرض جديد', '+963900000204', 'حلب - ساحة الجامعة - سوبرماركت الاختبار', 'حلب - الموكامبو - عنوان العميل', 18000, now()->subMinutes(4)),
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
        CarbonInterface $createdAt,
    ): DeliveryOrder {
        $acceptedAt = in_array($status, [DeliveryOrderStatus::Accepted, DeliveryOrderStatus::InProgress, DeliveryOrderStatus::PickedUp, DeliveryOrderStatus::Delivered, DeliveryOrderStatus::Completed, DeliveryOrderStatus::Cancelled], true) ? $createdAt->copy()->addMinutes(6) : null;
        $startedAt = in_array($status, [DeliveryOrderStatus::InProgress, DeliveryOrderStatus::PickedUp, DeliveryOrderStatus::Delivered, DeliveryOrderStatus::Completed], true) ? $createdAt->copy()->addMinutes(14) : null;
        $pickedAt = in_array($status, [DeliveryOrderStatus::PickedUp, DeliveryOrderStatus::Delivered, DeliveryOrderStatus::Completed], true) ? $createdAt->copy()->addMinutes(28) : null;
        $deliveredAt = in_array($status, [DeliveryOrderStatus::Delivered, DeliveryOrderStatus::Completed], true) ? $createdAt->copy()->addMinutes(48) : null;
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
            ],
        )->fresh();
    }

    /** @param array<string, DeliveryOrder> $orders */
    private function assignmentAttempts(DeliveryDriver $primaryDriver, DeliveryDriver $offerDriver, array $orders): void
    {
        $this->attempt($orders['active'], $primaryDriver, DeliveryAssignmentAttemptStatus::Accepted, 1.1, now()->subHour(), now()->subMinutes(54));
        $this->attempt($orders['completed'], $primaryDriver, DeliveryAssignmentAttemptStatus::Accepted, 1.8, now()->subHours(5), now()->subHours(5)->addMinute());
        $this->attempt($orders['cancelled'], $primaryDriver, DeliveryAssignmentAttemptStatus::Accepted, 2.2, now()->subDay(), now()->subDay()->addMinutes(2));
        $this->attempt($orders['offer'], $offerDriver, DeliveryAssignmentAttemptStatus::Open, 0.9, now()->subMinute(), null, now()->addMinutes(10));
    }

    private function attempt(DeliveryOrder $order, DeliveryDriver $driver, DeliveryAssignmentAttemptStatus $status, float $distance, CarbonInterface $offeredAt, ?CarbonInterface $respondedAt, ?CarbonInterface $expiresAt = null): void
    {
        DeliveryAssignmentAttempt::updateOrCreate(
            ['order_id' => $order->id, 'driver_id' => $driver->id, 'attempt_no' => 1],
            [
                'status' => $status->value,
                'distance_to_pickup_km' => $distance,
                'offered_at' => $offeredAt,
                'expires_at' => $expiresAt ?? $offeredAt->copy()->addMinutes(3),
                'responded_at' => $respondedAt,
                'reject_reason' => null,
            ],
        );
    }

    /** @param array<string, DeliveryOrder> $orders */
    private function orderEvents(User $owner, DeliveryDriver $primaryDriver, DeliveryDriver $offerDriver, array $orders): void
    {
        foreach ($orders as $key => $order) {
            DeliveryOrderEvent::updateOrCreate(
                ['order_id' => $order->id, 'to_status' => DeliveryOrderStatus::New->value],
                ['actor_type' => User::class, 'actor_id' => $owner->id, 'from_status' => null, 'note' => 'Mandoub test order created', 'payload' => ['seed' => 'mandoub_test_user']],
            );

            DeliveryOrderEvent::updateOrCreate(
                ['order_id' => $order->id, 'to_status' => $order->status],
                [
                    'actor_type' => DeliveryDriver::class,
                    'actor_id' => $order->driver_id ?? ($key === 'offer' ? $offerDriver->id : $primaryDriver->id),
                    'from_status' => $order->driver_id !== null ? DeliveryOrderStatus::Accepted->value : DeliveryOrderStatus::Dispatching->value,
                    'note' => 'Mandoub test order status '.$order->status,
                    'payload' => ['seed' => 'mandoub_test_user', 'currentStatus' => $order->status],
                ],
            );
        }
    }

    /** @param array<string, DeliveryDriver> $drivers */
    private function locations(array $drivers): void
    {
        $rows = [
            'primary' => ['recorded_at' => now()->subMinutes(2)->startOfMinute(), 'latitude' => 36.20690000, 'longitude' => 37.13890000, 'accuracy' => 3.5, 'speed' => 18.0, 'heading' => 185],
            'nearby' => ['recorded_at' => now()->subMinute()->startOfMinute(), 'latitude' => 36.20230000, 'longitude' => 37.13440000, 'accuracy' => 2.8, 'speed' => 12.0, 'heading' => 90],
            'fallback' => ['recorded_at' => now()->subMinutes(2)->startOfMinute(), 'latitude' => 36.21100000, 'longitude' => 37.14200000, 'accuracy' => 4.0, 'speed' => 10.0, 'heading' => 75],
            'offline' => ['recorded_at' => now()->subHours(2)->startOfMinute(), 'latitude' => 36.25000000, 'longitude' => 37.18000000, 'accuracy' => 8.0, 'speed' => 0.0, 'heading' => 0],
        ];

        foreach ($rows as $key => $row) {
            DeliveryDriverLocation::updateOrCreate(
                ['driver_id' => $drivers[$key]->id, 'recorded_at' => $row['recorded_at']],
                ['latitude' => $row['latitude'], 'longitude' => $row['longitude'], 'accuracy' => $row['accuracy'], 'speed' => $row['speed'], 'heading' => $row['heading']],
            );
        }
    }

    /**
     * @param array<string, DeliveryDriver> $drivers
     * @param array<string, DeliveryOrder> $orders
     */
    private function financialAccounts(array $drivers, User $owner, array $orders): void
    {
        foreach ($drivers as $key => $driver) {
            $account = DeliveryFinancialAccount::updateOrCreate(
                ['owner_type' => DeliveryDriver::class, 'owner_id' => $driver->id, 'currency' => 'SYP'],
                [
                    'current_balance' => $key === 'primary' ? 87500 : 0,
                    'financial_limit' => 150000,
                    'is_suspended' => false,
                    'suspension_reason' => null,
                    'suspended_at' => null,
                ],
            );

            if ($key !== 'primary') {
                continue;
            }

            foreach ([
                [DeliveryFinancialTransactionType::OrderFeeDebit->value, DeliveryFinancialDirection::Debit->value, 12500, 100000, 87500, $orders['completed']->id, 'Seed completed order fee'],
                [DeliveryFinancialTransactionType::CollectionCredit->value, DeliveryFinancialDirection::Credit->value, 15000, 72500, 87500, $orders['active']->id, 'Seed active order collection'],
            ] as $index => [$type, $direction, $amount, $before, $after, $referenceId, $note]) {
                DeliveryFinancialTransaction::updateOrCreate(
                    ['account_id' => $account->id, 'transaction_type' => $type, 'reference_id' => $referenceId, 'amount' => $amount],
                    ['direction' => $direction, 'balance_before' => $before, 'balance_after' => $after, 'reference_type' => DeliveryOrder::class, 'note' => $note, 'metadata' => ['seed' => 'mandoub_test_user', 'index' => $index + 1], 'created_by_user_id' => $owner->id],
                );
            }
        }
    }

    private function disputeAndTrustLog(DeliveryDriver $driver, DeliveryOrder $cancelledOrder): void
    {
        $dispute = Dispute::withoutEvents(fn (): Dispute => Dispute::query()->updateOrCreate(
            ['ticket_number' => 'DLY-MANDOUB-DSP-0001'],
            ['booking_id' => $cancelledOrder->id, 'booking_type' => 'delivery_order', 'description' => 'بلاغ اختبار مرتبط بمندوب الاختبار لعرض شاشة البلاغات.', 'category' => DisputeCategory::Other->value, 'status' => DisputeStatus::UnderReview->value, 'resolution' => DisputeResolution::Dismissed->value, 'worker_earnings_frozen' => false],
        ));

        DeliveryDriverTrustLog::updateOrCreate(
            ['driver_id' => $driver->id, 'reason' => 'mandoub_test_seed', 'score_delta' => -2],
            ['score_after' => 98, 'related_dispute_id' => $dispute->id],
        );
    }

    /** @param array<string, DeliveryOrder> $orders */
    private function notifications(DeliveryDriver $primaryDriver, DeliveryDriver $offerDriver, array $orders): void
    {
        $this->notification($offerDriver, 'طلب توصيل جديد', 'يوجد عرض توصيل جديد بانتظار قرارك.', $orders['offer'], false, 0);
        $this->notification($primaryDriver, 'طلب نشط', 'لديك طلب نشط جاهز للبدء.', $orders['active'], false, 1);
        $this->notification($primaryDriver, 'تم إكمال طلب', 'تم تسجيل طلب مكتمل في سجل الحركة.', $orders['completed'], true, 2);
    }

    private function notification(DeliveryDriver $driver, string $title, string $body, DeliveryOrder $order, bool $read, int $index): void
    {
        DB::table('notifications')->updateOrInsert(
            [
                'notifiable_type' => User::class,
                'notifiable_id' => $driver->user_id,
                'type' => \Modules\Delivery\Notifications\DeliveryCanonicalNotification::class,
                'data' => json_encode(['module' => 'delivery', 'type' => 'delivery_order_update', 'category' => 'orders', 'priority' => $index === 0 ? 'high' : 'normal', 'title' => $title, 'body' => $body, 'message' => $body, 'orderId' => $order->id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
            ['id' => (string) Str::uuid(), 'read_at' => $read ? now()->subHour() : null, 'created_at' => now()->subMinutes(20 - ($index * 5)), 'updated_at' => now()->subMinutes(20 - ($index * 5))],
        );
    }
}
