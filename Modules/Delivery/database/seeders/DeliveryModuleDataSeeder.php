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
use Modules\Delivery\Models\DeliveryCompanyStaff;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryDriverLocation;
use Modules\Delivery\Models\DeliveryDriverTrustLog;
use Modules\Delivery\Models\DeliveryFinancialAccount;
use Modules\Delivery\Models\DeliveryFinancialTransaction;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Models\DeliveryOrderEvent;
use Spatie\Permission\Models\Role;

final class DeliveryModuleDataSeeder extends Seeder
{
    public function run(): void
    {
        $owner = $this->upsertUser(
            email: 'delivery.owner@dllni.sy',
            name: 'Delivery Owner',
            phone: '+963944700001',
            moduleType: null,
        );

        $staff = $this->upsertUser(
            email: 'delivery.staff@dllni.sy',
            name: 'Delivery Staff',
            phone: '+963944700002',
            moduleType: null,
        );

        $drivers = [
            $this->upsertUser('delivery.driver1@dllni.sy', 'Driver One', '+963944700101', UserModuleType::DeliveryDriver),
            $this->upsertUser('delivery.driver2@dllni.sy', 'Driver Two', '+963944700102', UserModuleType::DeliveryDriver),
            $this->upsertUser('delivery.driver3@dllni.sy', 'Driver Three', '+963944700103', UserModuleType::DeliveryDriver),
            $this->upsertUser('delivery.driver4@dllni.sy', 'Driver Four', '+963944700104', UserModuleType::DeliveryDriver),
        ];

        $this->assignRoleIfExists($owner, 'delivery_company_admin');
        $this->assignRoleIfExists($staff, 'delivery_company_staff');

        $company = DeliveryCompany::updateOrCreate(
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
        );

        DeliveryCompanyStaff::updateOrCreate(
            ['company_id' => $company->id, 'user_id' => $staff->id],
            ['role_key' => 'operations', 'is_active' => true],
        );

        DeliveryCompanyStaff::updateOrCreate(
            ['company_id' => $company->id, 'user_id' => $owner->id],
            ['role_key' => 'admin', 'is_active' => true],
        );

        $driverModels = $this->seedDrivers($company, $drivers);
        $orders = $this->seedOrders($company, $owner, $driverModels);

        $this->seedAttemptsAndEvents($orders, $driverModels);
        $this->seedLocations($driverModels);
        $this->seedFinancial($company, $owner, $driverModels, $orders);
        $this->seedDisputesAndTrustLogs($driverModels, $orders);
        $this->seedDriverNotifications($driverModels, $orders);
    }

    private function upsertUser(string $email, string $name, string $phone, ?UserModuleType $moduleType): User
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'phone' => $phone,
                'module_type' => $moduleType?->value,
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
            ],
        );

        return $user->fresh();
    }

    private function assignRoleIfExists(User $user, string $roleName): void
    {
        $role = Role::query()->where('name', $roleName)->first();
        if ($role instanceof Role) {
            $user->assignRole($role);
        }
    }

    /**
     * @param  list<User>  $users
     * @return list<DeliveryDriver>
     */
    private function seedDrivers(DeliveryCompany $company, array $users): array
    {
        $dataset = [
            ['first_name' => 'Kareem', 'vehicle_type' => 'motorbike', 'plate_number' => 'ALE-1001', 'status' => DeliveryDriverAvailabilityStatus::Available->value, 'trust_score' => 96],
            ['first_name' => 'Lina', 'vehicle_type' => 'car', 'plate_number' => 'ALE-1002', 'status' => DeliveryDriverAvailabilityStatus::Busy->value, 'trust_score' => 88],
            ['first_name' => 'Omar', 'vehicle_type' => 'motorbike', 'plate_number' => 'ALE-1003', 'status' => DeliveryDriverAvailabilityStatus::Offline->value, 'trust_score' => 73],
            ['first_name' => 'Rama', 'vehicle_type' => 'car', 'plate_number' => 'ALE-1004', 'status' => DeliveryDriverAvailabilityStatus::Available->value, 'trust_score' => 99],
        ];

        $drivers = [];
        foreach ($users as $index => $user) {
            $profile = $dataset[$index];
            $driver = DeliveryDriver::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'company_id' => $company->id,
                    'first_name' => $profile['first_name'],
                    'phone' => $user->phone,
                    'vehicle_type' => $profile['vehicle_type'],
                    'plate_number' => $profile['plate_number'],
                    'availability_status' => $profile['status'],
                    'is_active' => true,
                    'is_suspended' => false,
                    'suspended_until' => null,
                    'suspension_reason' => null,
                    'trust_score' => $profile['trust_score'],
                    'open_disputes_count' => 0,
                    'last_seen_at' => now()->subMinutes(($index + 1) * 3),
                ],
            );

            $drivers[] = $driver->fresh();
        }

        return $drivers;
    }

    /**
     * @param  list<DeliveryDriver>  $drivers
     * @return list<DeliveryOrder>
     */
    private function seedOrders(DeliveryCompany $company, User $owner, array $drivers): array
    {
        $rows = [
            ['number' => 'DLV-100001', 'status' => DeliveryOrderStatus::Completed->value, 'driver' => $drivers[0], 'fee' => 12500.00],
            ['number' => 'DLV-100002', 'status' => DeliveryOrderStatus::InProgress->value, 'driver' => $drivers[1], 'fee' => 9000.00],
            ['number' => 'DLV-100003', 'status' => DeliveryOrderStatus::Offered->value, 'driver' => null, 'fee' => 11000.00],
            ['number' => 'DLV-100004', 'status' => DeliveryOrderStatus::Stopped->value, 'driver' => null, 'fee' => 16000.00],
            ['number' => 'DLV-100005', 'status' => DeliveryOrderStatus::Cancelled->value, 'driver' => $drivers[2], 'fee' => 7000.00],
        ];

        $orders = [];
        foreach ($rows as $index => $row) {
            $createdAt = now()->subDays(5 - $index);
            $acceptedAt = in_array($row['status'], [DeliveryOrderStatus::Completed->value, DeliveryOrderStatus::InProgress->value, DeliveryOrderStatus::Cancelled->value], true) ? $createdAt->copy()->addMinutes(8) : null;
            $startedAt = in_array($row['status'], [DeliveryOrderStatus::Completed->value, DeliveryOrderStatus::InProgress->value], true) ? $createdAt->copy()->addMinutes(16) : null;
            $pickedAt = $row['status'] === DeliveryOrderStatus::Completed->value ? $createdAt->copy()->addMinutes(32) : null;
            $deliveredAt = $row['status'] === DeliveryOrderStatus::Completed->value ? $createdAt->copy()->addMinutes(54) : null;
            $completedAt = $row['status'] === DeliveryOrderStatus::Completed->value ? $createdAt->copy()->addMinutes(58) : null;
            $cancelledAt = $row['status'] === DeliveryOrderStatus::Cancelled->value ? $createdAt->copy()->addMinutes(25) : null;
            $stoppedAt = $row['status'] === DeliveryOrderStatus::Stopped->value ? $createdAt->copy()->addMinutes(30) : null;

            $order = DeliveryOrder::updateOrCreate(
                ['order_number' => $row['number']],
                [
                    'company_id' => $company->id,
                    'driver_id' => $row['driver']?->id,
                    'created_by_user_id' => $owner->id,
                    'customer_name' => 'Customer '.($index + 1),
                    'customer_phone' => '+9639448880'.($index + 1),
                    'customer_notes' => 'Handle with care. Seeded delivery order #'.($index + 1),
                    'pickup_address' => 'Aleppo, Pickup District '.($index + 1),
                    'pickup_latitude' => 36.20000000 + ($index * 0.01),
                    'pickup_longitude' => 37.13000000 + ($index * 0.01),
                    'dropoff_address' => 'Aleppo, Dropoff District '.($index + 1),
                    'dropoff_latitude' => 36.21000000 + ($index * 0.01),
                    'dropoff_longitude' => 37.14000000 + ($index * 0.01),
                    'distance_km' => 3.2 + $index,
                    'delivery_fee' => $row['fee'],
                    'currency' => 'SYP',
                    'status' => $row['status'],
                    'accepted_at' => $acceptedAt,
                    'started_at' => $startedAt,
                    'picked_up_at' => $pickedAt,
                    'delivered_at' => $deliveredAt,
                    'completed_at' => $completedAt,
                    'cancelled_at' => $cancelledAt,
                    'stopped_at' => $stoppedAt,
                    'cancel_reason' => $row['status'] === DeliveryOrderStatus::Cancelled->value ? 'Customer requested cancellation' : null,
                    'stop_reason' => $row['status'] === DeliveryOrderStatus::Stopped->value ? 'No available nearby driver before timeout' : null,
                ],
            );

            $orders[] = $order->fresh();
        }

        return $orders;
    }

    /**
     * @param  list<DeliveryOrder>  $orders
     * @param  list<DeliveryDriver>  $drivers
     */
    private function seedAttemptsAndEvents(array $orders, array $drivers): void
    {
        $offeredOrder = $orders[2];
        $stoppedOrder = $orders[3];
        $cancelledOrder = $orders[4];

        DeliveryAssignmentAttempt::updateOrCreate(
            ['order_id' => $offeredOrder->id, 'driver_id' => $drivers[0]->id, 'attempt_no' => 1],
            [
                'status' => DeliveryAssignmentAttemptStatus::Open->value,
                'distance_to_pickup_km' => 1.5,
                'offered_at' => now()->subMinutes(6),
                'expires_at' => now()->addMinutes(2),
                'responded_at' => null,
                'reject_reason' => null,
            ],
        );

        DeliveryAssignmentAttempt::updateOrCreate(
            ['order_id' => $stoppedOrder->id, 'driver_id' => $drivers[1]->id, 'attempt_no' => 1],
            [
                'status' => DeliveryAssignmentAttemptStatus::Rejected->value,
                'distance_to_pickup_km' => 2.1,
                'offered_at' => now()->subHours(8),
                'expires_at' => now()->subHours(8)->addMinutes(2),
                'responded_at' => now()->subHours(8)->addMinute(),
                'reject_reason' => 'Heavy traffic',
            ],
        );

        DeliveryAssignmentAttempt::updateOrCreate(
            ['order_id' => $stoppedOrder->id, 'driver_id' => $drivers[2]->id, 'attempt_no' => 2],
            [
                'status' => DeliveryAssignmentAttemptStatus::TimedOut->value,
                'distance_to_pickup_km' => 3.7,
                'offered_at' => now()->subHours(7),
                'expires_at' => now()->subHours(7)->addMinutes(2),
                'responded_at' => null,
                'reject_reason' => null,
            ],
        );

        DeliveryAssignmentAttempt::updateOrCreate(
            ['order_id' => $cancelledOrder->id, 'driver_id' => $drivers[2]->id, 'attempt_no' => 1],
            [
                'status' => DeliveryAssignmentAttemptStatus::Accepted->value,
                'distance_to_pickup_km' => 1.2,
                'offered_at' => now()->subDay(),
                'expires_at' => now()->subDay()->addMinutes(2),
                'responded_at' => now()->subDay()->addMinute(),
                'reject_reason' => null,
            ],
        );

        foreach ($orders as $order) {
            DeliveryOrderEvent::updateOrCreate(
                ['order_id' => $order->id, 'to_status' => DeliveryOrderStatus::New->value],
                [
                    'actor_type' => User::class,
                    'actor_id' => $order->created_by_user_id,
                    'from_status' => null,
                    'note' => 'Order created',
                    'payload' => ['source' => 'seeder'],
                ],
            );

            if ($order->accepted_at !== null) {
                DeliveryOrderEvent::updateOrCreate(
                    ['order_id' => $order->id, 'to_status' => DeliveryOrderStatus::Accepted->value],
                    [
                        'actor_type' => DeliveryDriver::class,
                        'actor_id' => $order->driver_id,
                        'from_status' => DeliveryOrderStatus::Offered->value,
                        'note' => 'Driver accepted assignment',
                        'payload' => ['source' => 'seeder'],
                    ],
                );
            }

            DeliveryOrderEvent::updateOrCreate(
                ['order_id' => $order->id, 'to_status' => $order->status],
                [
                    'actor_type' => DeliveryDriver::class,
                    'actor_id' => $order->driver_id,
                    'from_status' => $order->accepted_at !== null ? DeliveryOrderStatus::Accepted->value : DeliveryOrderStatus::Dispatching->value,
                    'note' => 'Order transitioned to '.$order->status,
                    'payload' => ['source' => 'seeder', 'currentStatus' => $order->status],
                ],
            );
        }
    }

    /**
     * @param  list<DeliveryDriver>  $drivers
     */
    private function seedLocations(array $drivers): void
    {
        foreach ($drivers as $index => $driver) {
            DeliveryDriverLocation::updateOrCreate(
                ['driver_id' => $driver->id, 'recorded_at' => now()->subMinutes(10 + $index)],
                [
                    'latitude' => 36.21010000 + ($index * 0.002),
                    'longitude' => 37.14120000 + ($index * 0.002),
                    'accuracy' => 4.5 + $index,
                    'speed' => 18.2 + $index,
                    'heading' => 180 + ($index * 10),
                ],
            );

            DeliveryDriverLocation::updateOrCreate(
                ['driver_id' => $driver->id, 'recorded_at' => now()->subMinutes(2 + $index)],
                [
                    'latitude' => 36.21110000 + ($index * 0.002),
                    'longitude' => 37.14210000 + ($index * 0.002),
                    'accuracy' => 3.2 + $index,
                    'speed' => 22.5 + $index,
                    'heading' => 210 + ($index * 8),
                ],
            );
        }
    }

    /**
     * @param  list<DeliveryDriver>  $drivers
     * @param  list<DeliveryOrder>  $orders
     */
    private function seedFinancial(DeliveryCompany $company, User $owner, array $drivers, array $orders): void
    {
        $companyAccount = DeliveryFinancialAccount::updateOrCreate(
            ['owner_type' => DeliveryCompany::class, 'owner_id' => $company->id, 'currency' => 'SYP'],
            [
                'current_balance' => 145000,
                'financial_limit' => 350000,
                'is_suspended' => false,
                'suspension_reason' => null,
                'suspended_at' => null,
            ],
        );

        $this->seedFinancialTransactions($companyAccount, $owner, $orders);

        foreach ($drivers as $index => $driver) {
            DeliveryFinancialAccount::updateOrCreate(
                ['owner_type' => DeliveryDriver::class, 'owner_id' => $driver->id, 'currency' => 'SYP'],
                [
                    'current_balance' => 20000 + ($index * 5000),
                    'financial_limit' => 0,
                    'is_suspended' => false,
                    'suspension_reason' => null,
                    'suspended_at' => null,
                ],
            );
        }
    }

    /**
     * @param  list<DeliveryOrder>  $orders
     */
    private function seedFinancialTransactions(DeliveryFinancialAccount $account, User $owner, array $orders): void
    {
        $rows = [
            [
                'type' => DeliveryFinancialTransactionType::OrderFeeDebit->value,
                'direction' => DeliveryFinancialDirection::Debit->value,
                'amount' => 12500,
                'before' => 160000,
                'after' => 147500,
                'reference_id' => $orders[0]->id,
                'note' => 'Delivery fee charged for completed order',
            ],
            [
                'type' => DeliveryFinancialTransactionType::CollectionCredit->value,
                'direction' => DeliveryFinancialDirection::Credit->value,
                'amount' => 5500,
                'before' => 147500,
                'after' => 153000,
                'reference_id' => $orders[0]->id,
                'note' => 'Cash collection settled',
            ],
            [
                'type' => DeliveryFinancialTransactionType::ManualAdjustmentDebit->value,
                'direction' => DeliveryFinancialDirection::Debit->value,
                'amount' => 8000,
                'before' => 153000,
                'after' => 145000,
                'reference_id' => $orders[3]->id,
                'note' => 'Manual correction',
            ],
        ];

        foreach ($rows as $index => $row) {
            DeliveryFinancialTransaction::updateOrCreate(
                ['account_id' => $account->id, 'transaction_type' => $row['type'], 'reference_id' => $row['reference_id'], 'amount' => $row['amount']],
                [
                    'direction' => $row['direction'],
                    'balance_before' => $row['before'],
                    'balance_after' => $row['after'],
                    'reference_type' => DeliveryOrder::class,
                    'note' => $row['note'],
                    'metadata' => ['seed' => true, 'index' => $index + 1],
                    'created_by_user_id' => $owner->id,
                    'created_at' => now()->subDays(3 - $index),
                    'updated_at' => now()->subDays(3 - $index),
                ],
            );
        }
    }

    /**
     * @param  list<DeliveryDriver>  $drivers
     * @param  list<DeliveryOrder>  $orders
     */
    private function seedDisputesAndTrustLogs(array $drivers, array $orders): void
    {
        $dispute = Dispute::withoutEvents(function () use ($orders): Dispute {
            /** @var Dispute $dispute */
            $dispute = Dispute::query()->updateOrCreate(
                ['ticket_number' => 'DLY-DSP-1001'],
                [
                    'booking_id' => $orders[4]->id,
                    'booking_type' => 'delivery_order',
                    'description' => 'Driver reported customer unavailable and cancellation dispute was raised.',
                    'category' => DisputeCategory::Other->value,
                    'status' => DisputeStatus::UnderReview->value,
                    'resolution' => DisputeResolution::Dismissed->value,
                    'worker_earnings_frozen' => false,
                ],
            );

            return $dispute;
        });

        DeliveryDriverTrustLog::updateOrCreate(
            ['driver_id' => $drivers[2]->id, 'reason' => 'late_response', 'score_delta' => -8],
            [
                'score_after' => max(0, $drivers[2]->trust_score - 8),
                'related_dispute_id' => $dispute->id,
            ],
        );
    }

    /**
     * @param  list<DeliveryDriver>  $drivers
     * @param  list<DeliveryOrder>  $orders
     */
    private function seedDriverNotifications(array $drivers, array $orders): void
    {
        foreach ($drivers as $index => $driver) {
            DB::table('notifications')->updateOrInsert(
                [
                    'notifiable_type' => User::class,
                    'notifiable_id' => $driver->user_id,
                    'type' => \Modules\Delivery\Notifications\DeliveryCanonicalNotification::class,
                    'data' => json_encode([
                        'module' => 'delivery',
                        'type' => 'delivery_order_update',
                        'category' => 'orders',
                        'priority' => $index % 2 === 0 ? 'high' : 'normal',
                        'title' => 'Order update',
                        'body' => 'Delivery order '.$orders[min($index, count($orders) - 1)]->order_number.' status changed.',
                        'message' => 'Delivery order status changed',
                        'orderId' => $orders[min($index, count($orders) - 1)]->id,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
                [
                    'id' => (string) Str::uuid(),
                    'read_at' => $index === 0 ? now()->subHour() : null,
                    'created_at' => now()->subMinutes(30 - ($index * 3)),
                    'updated_at' => now()->subMinutes(30 - ($index * 3)),
                ],
            );
        }
    }
}
