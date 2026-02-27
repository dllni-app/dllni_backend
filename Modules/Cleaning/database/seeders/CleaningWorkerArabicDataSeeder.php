<?php

declare(strict_types=1);

namespace Modules\Cleaning\Database\Seeders;

use App\Enums\DisputeCategory;
use App\Enums\DisputeStatus;
use App\Models\CancellationPolicy;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Database\Seeder;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningTimeWarningResponse;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;

final class CleaningWorkerArabicDataSeeder extends Seeder
{
    private const array ARABIC_CUSTOMERS = [
        ['name' => 'أحمد الأحمد', 'email' => 'ahmed.alahmad@example.com'],
        ['name' => 'فاطمة محمد', 'email' => 'fatima.mohammad@example.com'],
        ['name' => 'خالد السعيد', 'email' => 'khalid.alsaeed@example.com'],
        ['name' => 'نورة العلي', 'email' => 'nora.alali@example.com'],
        ['name' => 'عمر حسن', 'email' => 'omar.hassan@example.com'],
    ];

    private const array ARABIC_LOCATIONS = [
        [
            'location_name' => 'فيلا الحمدانية',
            'address' => 'الحمدانية، شارع النخيل',
            'latitude' => 33.5138,
            'longitude' => 36.2765,
        ],
        [
            'location_name' => 'شقة الميدان',
            'address' => 'الميدان، بناية ٥',
            'latitude' => 33.5155,
            'longitude' => 36.2801,
        ],
        [
            'location_name' => 'بيت الزهراء',
            'address' => 'الزهراء، قرب المسجد',
            'latitude' => 33.5202,
            'longitude' => 36.2704,
        ],
        [
            'location_name' => 'عمارة الروضة',
            'address' => 'الروضة، الطابق الثالث',
            'latitude' => 33.5099,
            'longitude' => 36.2857,
        ],
        [
            'location_name' => 'فيلا النور',
            'address' => 'النور، حي السعادة',
            'latitude' => 33.5181,
            'longitude' => 36.2903,
        ],
    ];

    private const array ARABIC_CANCELLATION_REASONS = [
        'ظرف عائلي طارئ',
        'تعارض مع موعد آخر',
        'الزبون ألغى الموعد',
    ];

    private const array ARABIC_REJECT_MESSAGES = [
        'أعتذر، لا أستطيع تمديد الوقت اليوم.',
        'لدي موعد آخر بعد هذه الخدمة.',
        'نأسف، الوقت الإضافي غير متاح.',
    ];

    public function run(): void
    {
        $worker = Worker::whereHas('user', fn ($q) => $q->where('email', 'cleaning.worker@example.com'))->first();
        $billingPolicy = CleaningBillingPolicy::where('is_default', true)->first();
        $cancellationPolicy = CancellationPolicy::where('module', 'cleaning')->where('is_default', true)->first();

        if (! $worker || ! $billingPolicy) {
            return;
        }

        $worker->user->update(['name' => 'راما أحمد']);
        $worker->update([
            'first_name' => 'راما',
            'bio' => 'عاملة تنظيف ذات خبرة للتجارب داخل التطبيق.',
            'average_rating' => 4.8,
            'total_completed_jobs' => 120,
            'trust_score' => 85,
            'acceptance_rate' => 95.0,
            'cancellation_rate' => 2.0,
            'open_disputes_count' => 1,
            'is_active' => true,
            'is_suspended' => false,
            'home_address' => 'دمشق',
            'home_latitude' => 33.5138,
            'home_longitude' => 36.2765,
            'default_working_hours' => [
                'sunday' => ['available' => true, 'data' => [['09:00' => '17:00']]],
                'monday' => ['available' => true, 'data' => [['09:00' => '17:00']]],
                'tuesday' => ['available' => true, 'data' => [['09:00' => '17:00']]],
                'wednesday' => ['available' => true, 'data' => [['09:00' => '17:00']]],
                'thursday' => ['available' => true, 'data' => [['09:00' => '17:00']]],
                'friday' => ['available' => false, 'data' => []],
                'saturday' => ['available' => false, 'data' => []],
            ],
        ]);

        $customers = $this->ensureArabicCustomers();
        $today = now()->startOfDay();

        $bookings = [];

        foreach (self::ARABIC_LOCATIONS as $index => $location) {
            $customer = $customers[$index % count($customers)];
            $statuses = [
                CleaningBookingStatus::Completed,
                CleaningBookingStatus::InProgress,
                CleaningBookingStatus::Completed,
                CleaningBookingStatus::WorkerAssigned,
                CleaningBookingStatus::Cancelled,
            ];
            $status = $statuses[$index % count($statuses)];

            $scheduledDate = $today->copy()->addDays($index);
            $basePrice = 50 + ($index * 15);
            $travelFee = 10;
            $totalPrice = $basePrice + $travelFee;
            $rooms = 2 + ($index % 2);
            $bathrooms = 1 + ($index % 2);

            $startedTravelAt = null;
            $arrivedAt = null;

            if (in_array($status, [CleaningBookingStatus::InProgress, CleaningBookingStatus::Completed], true)) {
                $startedTravelAt = $scheduledDate->copy()->setTime(9, 30);
                $arrivedAt = $scheduledDate->copy()->setTime(9, 50);
            }

            $bookingNumber = 'CLN-AR-'.mb_str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
            if (CleaningBooking::where('booking_number', $bookingNumber)->exists()) {
                continue;
            }

            $booking = CleaningBooking::create([
                'customer_id' => $customer->id,
                'worker_id' => $worker->id,
                'cancellation_policy_id' => $cancellationPolicy?->id,
                'billing_policy_id' => $billingPolicy->id,
                'booking_number' => $bookingNumber,
                'status' => $status,
                'property_type' => $index % 2 === 0 ? 'villa' : 'apartment',
                'property_details' => [
                    'location_name' => $location['location_name'],
                    'address' => $location['address'],
                    'bedrooms' => $rooms,
                    'rooms' => $rooms + 1,
                    'bathrooms' => $bathrooms,
                    'kitchen_included' => true,
                ],
                'estimated_sqm' => 120 + ($index * 25),
                'estimated_hours' => 3 + ($index % 2) * 0.5,
                'scheduled_date' => $scheduledDate,
                'scheduled_time' => '10:00',
                'total_hours' => 3.5,
                'base_price' => $basePrice,
                'addons_total' => 0,
                'travel_fee' => $travelFee,
                'cancellation_fee' => 0,
                'total_price' => $totalPrice,
                'terms_accepted' => true,
                'address_latitude' => $location['latitude'],
                'address_longitude' => $location['longitude'],
                'work_started_at' => in_array($status, [CleaningBookingStatus::Completed, CleaningBookingStatus::InProgress]) ? $scheduledDate->copy()->setTime(10, 0) : null,
                'work_finished_at' => $status === CleaningBookingStatus::Completed ? $scheduledDate->copy()->setTime(13, 30) : null,
                'started_travel_at' => $startedTravelAt,
                'arrived_at' => $arrivedAt,
                'customer_confirmed_at' => in_array($status, [CleaningBookingStatus::Completed, CleaningBookingStatus::InProgress]) ? $scheduledDate->copy()->setTime(9, 0) : null,
                'cancelled_at' => $status === CleaningBookingStatus::Cancelled ? now() : null,
                'cancellation_reason' => $status === CleaningBookingStatus::Cancelled ? self::ARABIC_CANCELLATION_REASONS[$index % count(self::ARABIC_CANCELLATION_REASONS)] : null,
            ]);

            $bookings[] = $booking;
        }

        $inProgressOrCompleted = collect($bookings)->filter(
            fn (CleaningBooking $b) => $b->status === CleaningBookingStatus::InProgress || $b->status === CleaningBookingStatus::Completed
        );

        foreach ($inProgressOrCompleted->take(3) as $idx => $booking) {
            $pending = $idx === 0;
            CleaningTimeWarning::firstOrCreate(
                [
                    'booking_id' => $booking->id,
                    'booking_type' => 'cleaning_booking',
                ],
                [
                    'customer_response' => CleaningTimeWarningResponse::ExtendTime->value,
                    'worker_response' => $pending ? null : ($idx === 1 ? CleaningTimeWarningResponse::ExtendTime->value : CleaningTimeWarningResponse::CommitCurrentTime->value),
                    'sent_at' => now()->subMinutes(30),
                    'customer_responded_at' => now()->subMinutes(25),
                    'worker_responded_at' => $pending ? null : now()->subMinutes(20),
                    'worker_reject_message' => ($idx === 2 && ! $pending) ? self::ARABIC_REJECT_MESSAGES[$idx % count(self::ARABIC_REJECT_MESSAGES)] : null,
                ]
            );
        }

        $pendingBookingNumber = 'CLN-AR-PEND-0001';
        if (! CleaningBooking::where('booking_number', $pendingBookingNumber)->exists()) {
            $pendingDate = $today->copy()->addDays(count(self::ARABIC_LOCATIONS));
            $pendingLocation = self::ARABIC_LOCATIONS[0];
            $pendingBasePrice = 80;
            $pendingTravelFee = 15;
            $pendingTotalPrice = $pendingBasePrice + $pendingTravelFee;

            $bookings[] = CleaningBooking::create([
                'customer_id' => $customers[0]->id,
                'worker_id' => null,
                'preferred_worker_id' => $worker->id,
                'cancellation_policy_id' => $cancellationPolicy?->id,
                'billing_policy_id' => $billingPolicy->id,
                'booking_number' => $pendingBookingNumber,
                'status' => CleaningBookingStatus::Pending,
                'property_type' => 'apartment',
                'property_details' => [
                    'location_name' => $pendingLocation['location_name'],
                    'address' => $pendingLocation['address'],
                    'bedrooms' => 3,
                    'rooms' => 4,
                    'bathrooms' => 2,
                    'kitchen_included' => true,
                ],
                'estimated_sqm' => 90,
                'estimated_hours' => 3,
                'scheduled_date' => $pendingDate,
                'scheduled_time' => '16:00',
                'total_hours' => 3,
                'base_price' => $pendingBasePrice,
                'addons_total' => 0,
                'travel_fee' => $pendingTravelFee,
                'cancellation_fee' => 0,
                'total_price' => $pendingTotalPrice,
                'terms_accepted' => true,
                'address_latitude' => $pendingLocation['latitude'],
                'address_longitude' => $pendingLocation['longitude'],
            ]);
        }

        $travelBookingNumber = 'CLN-AR-TRAVEL-0001';
        if (! CleaningBooking::where('booking_number', $travelBookingNumber)->exists()) {
            $travelDate = $today->copy()->addDays(count(self::ARABIC_LOCATIONS) + 1);
            $travelLocation = self::ARABIC_LOCATIONS[1 % count(self::ARABIC_LOCATIONS)];
            $travelBasePrice = 120;
            $travelFee = 20;
            $travelTotalPrice = $travelBasePrice + $travelFee;

            $bookings[] = CleaningBooking::create([
                'customer_id' => $customers[1]->id,
                'worker_id' => $worker->id,
                'cancellation_policy_id' => $cancellationPolicy?->id,
                'billing_policy_id' => $billingPolicy->id,
                'booking_number' => $travelBookingNumber,
                'status' => CleaningBookingStatus::WorkerAssigned,
                'property_type' => 'villa',
                'property_details' => [
                    'location_name' => $travelLocation['location_name'],
                    'address' => $travelLocation['address'],
                    'bedrooms' => 4,
                    'rooms' => 6,
                    'bathrooms' => 3,
                    'kitchen_included' => true,
                ],
                'estimated_sqm' => 140,
                'estimated_hours' => 4,
                'scheduled_date' => $travelDate,
                'scheduled_time' => '11:00',
                'total_hours' => 4,
                'base_price' => $travelBasePrice,
                'addons_total' => 0,
                'travel_fee' => $travelFee,
                'cancellation_fee' => 0,
                'total_price' => $travelTotalPrice,
                'terms_accepted' => true,
                'address_latitude' => $travelLocation['latitude'],
                'address_longitude' => $travelLocation['longitude'],
                'started_travel_at' => $travelDate->copy()->setTime(10, 30),
                'arrived_at' => null,
            ]);
        }

        $completedForDispute = collect($bookings)->first(
            fn (CleaningBooking $b) => $b->status === CleaningBookingStatus::Completed
        );

        if ($completedForDispute) {
            $dispute = Dispute::firstOrCreate(
                [
                    'booking_id' => $completedForDispute->id,
                    'booking_type' => 'cleaning_booking',
                ],
                [
                    'ticket_number' => 'DSP-AR-0001',
                    'category' => DisputeCategory::PoorQuality,
                    'status' => DisputeStatus::Open,
                ]
            );

            if (! $dispute->messages()->exists()) {
                $customerUser = $completedForDispute->customer;

                DisputeMessage::create([
                    'dispute_id' => $dispute->id,
                    'sender_id' => $customerUser?->id ?? $worker->user_id,
                    'sender_type' => $customerUser ? 'customer' : 'worker',
                    'body' => 'الخدمة لم تكن بالمستوى المتوقع، يرجى المساعدة في حل المشكلة.',
                ]);
            }
        }
    }

    /**
     * @return array<int, User>
     */
    private function ensureArabicCustomers(): array
    {
        $users = [];
        foreach (self::ARABIC_CUSTOMERS as $data) {
            $users[] = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'phone' => '+9639'.mb_str_pad((string) fake()->unique()->numberBetween(1000000, 9999999), 7, '0'),
                    'password' => bcrypt('password'),
                    'email_verified_at' => now(),
                ]
            );
        }

        return $users;
    }
}
