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
use Database\Seeders\Support\SeederMedia;
use Illuminate\Database\Seeder;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningTimeWarningResponse;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;

final class CleaningWorkerArabicDataSeeder extends Seeder
{
    private const array ARABIC_CUSTOMERS = [
        ['name' => 'أح�.د ا�"أح�.د', 'email' => 'ahmed.alahmad@dllni.sy'],
        ['name' => 'فاط�.ة �.ح�.د', 'email' => 'fatima.mohammad@dllni.sy'],
        ['name' => 'خا�"د ا�"سع�Sد', 'email' => 'khalid.alsaeed@dllni.sy'],
        ['name' => '�?�^رة ا�"ع�"�S', 'email' => 'nora.alali@dllni.sy'],
        ['name' => 'ع�.ر حس�?', 'email' => 'omar.hassan@dllni.sy'],
    ];

    private const array ARABIC_LOCATIONS = [
        [
            'location_name' => 'ف�S�"ا ا�"ح�.دا�?�Sة',
            'address' => 'ا�"ح�.دا�?�Sة�O شارع ا�"�,دس',
            'latitude' => 36.1795,
            'longitude' => 37.1082,
        ],
        [
            'location_name' => 'ش�,ة ا�"�.�Sدا�?',
            'address' => 'ا�"فر�,ا�?�O ب�?ا�Sة ٥',
            'latitude' => 36.2021,
            'longitude' => 37.1343,
        ],
        [
            'location_name' => 'ب�Sت ا�"ز�?راء',
            'address' => 'ا�"أشرف�Sة�O �,رب ا�"�.سجد',
            'latitude' => 36.2308,
            'longitude' => 37.1279,
        ],
        [
            'location_name' => 'ع�.ارة ا�"ر�^ضة',
            'address' => 'ا�"سر�Sا�? ا�"جد�Sدة�O ا�"طاب�, ا�"ثا�"ث',
            'latitude' => 36.2168,
            'longitude' => 37.1317,
        ],
        [
            'location_name' => 'ف�S�"ا ا�"�?�^ر',
            'address' => 'ا�"ج�.�S�"�Sة�O ح�S ا�"سعادة',
            'latitude' => 36.2127,
            'longitude' => 37.1456,
        ],
    ];

    private const array ARABIC_CANCELLATION_REASONS = [
        'ظرف عائ�"�S طارئ',
        'تعارض �.ع �.�^عد آخر',
        'ا�"زب�^�? أ�"غ�? ا�"�.�^عد',
    ];

    private const array ARABIC_REJECT_MESSAGES = [
        'أعتذر�O �"ا أستط�Sع ت�.د�Sد ا�"�^�,ت ا�"�S�^�..',
        '�"د�S �.�^عد آخر بعد �?ذ�? ا�"خد�.ة.',
        '�?أسف�O ا�"�^�,ت ا�"إضاف�S غ�Sر �.تاح.',
    ];

    public function run(): void
    {
        $worker = Worker::whereHas('user', fn ($q) => $q->where('email', 'cleaning.worker@dllni.sy'))->first();
        $billingPolicy = CleaningBillingPolicy::where('is_default', true)->first();
        $cancellationPolicy = CancellationPolicy::where('module', 'cleaning')->where('is_default', true)->first();

        if (! $worker || ! $billingPolicy) {
            return;
        }

        $worker->user->update(['name' => 'را�.ا أح�.د']);
        $worker->update([
            'first_name' => 'را�.ا',
            'bio' => 'عا�.�"ة ت�?ظ�Sف ذات خبرة �"�"تجارب داخ�" ا�"تطب�S�,.',
            'average_rating' => 4.8,
            'total_completed_jobs' => 120,
            'trust_score' => 85,
            'acceptance_rate' => 95.0,
            'cancellation_rate' => 2.0,
            'open_disputes_count' => 1,
            'is_active' => true,
            'is_suspended' => false,
            'home_address' => 'ح�"ب - ا�"ح�.دا�?�Sة',
            'home_latitude' => 36.1795,
            'home_longitude' => 37.1082,
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

        SeederMedia::ensureSingleMedia(
            $worker,
            'avatar',
            'https://images.unsplash.com/photo-1580489944761-15a19d654956?auto=format&fit=crop&w=512&q=80',
            "cleaning-worker-{$worker->id}-avatar"
        );

        if ($worker->user) {
            SeederMedia::ensureSingleMedia(
                $worker->user,
                'primary-image',
                'https://images.unsplash.com/photo-1494790108377-be9c29b29330?auto=format&fit=crop&w=600&q=80',
                "cleaning-worker-user-{$worker->user->id}-primary"
            );
        }

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
                    'kitchens' => 1,
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
                    'additional_minutes' => $idx === 1 && ! $pending ? 30 : null,
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
                    'kitchens' => 1,
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
                    'kitchens' => 1,
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

        $assignedPendingBookingNumber = 'CLN-AR-PEND-WORKER-0001';
        if (! CleaningBooking::where('booking_number', $assignedPendingBookingNumber)->exists()) {
            $assignedPendingDate = $today->copy()->addDays(count(self::ARABIC_LOCATIONS) + 2);
            $assignedPendingLocation = self::ARABIC_LOCATIONS[2 % count(self::ARABIC_LOCATIONS)];
            $assignedPendingBasePrice = 95;
            $assignedPendingTravelFee = 10;

            $bookings[] = CleaningBooking::create([
                'customer_id' => $customers[2]->id,
                'worker_id' => $worker->id,
                'preferred_worker_id' => $worker->id,
                'cancellation_policy_id' => $cancellationPolicy?->id,
                'billing_policy_id' => $billingPolicy->id,
                'booking_number' => $assignedPendingBookingNumber,
                'status' => CleaningBookingStatus::Pending,
                'property_type' => 'apartment',
                'property_details' => [
                    'location_name' => $assignedPendingLocation['location_name'],
                    'address' => $assignedPendingLocation['address'],
                    'bedrooms' => 2,
                    'rooms' => 3,
                    'bathrooms' => 1,
                    'kitchens' => 1,
                ],
                'estimated_sqm' => 85,
                'estimated_hours' => 2.5,
                'scheduled_date' => $assignedPendingDate,
                'scheduled_time' => '14:00',
                'total_hours' => 2.5,
                'base_price' => $assignedPendingBasePrice,
                'addons_total' => 0,
                'travel_fee' => $assignedPendingTravelFee,
                'cancellation_fee' => 0,
                'total_price' => $assignedPendingBasePrice + $assignedPendingTravelFee,
                'terms_accepted' => true,
                'address_latitude' => $assignedPendingLocation['latitude'],
                'address_longitude' => $assignedPendingLocation['longitude'],
            ]);
        }

        $arrivedBookingNumber = 'CLN-AR-ARRIVED-0001';
        if (! CleaningBooking::where('booking_number', $arrivedBookingNumber)->exists()) {
            $arrivedDate = $today->copy()->addDays(count(self::ARABIC_LOCATIONS) + 3);
            $arrivedLocation = self::ARABIC_LOCATIONS[3 % count(self::ARABIC_LOCATIONS)];
            $arrivedBasePrice = 130;
            $arrivedTravelFee = 20;

            $bookings[] = CleaningBooking::create([
                'customer_id' => $customers[3]->id,
                'worker_id' => $worker->id,
                'cancellation_policy_id' => $cancellationPolicy?->id,
                'billing_policy_id' => $billingPolicy->id,
                'booking_number' => $arrivedBookingNumber,
                'status' => CleaningBookingStatus::WorkerAssigned,
                'property_type' => 'villa',
                'property_details' => [
                    'location_name' => $arrivedLocation['location_name'],
                    'address' => $arrivedLocation['address'],
                    'bedrooms' => 4,
                    'rooms' => 5,
                    'bathrooms' => 3,
                    'kitchens' => 1,
                ],
                'estimated_sqm' => 150,
                'estimated_hours' => 4,
                'scheduled_date' => $arrivedDate,
                'scheduled_time' => '18:00',
                'total_hours' => 4,
                'base_price' => $arrivedBasePrice,
                'addons_total' => 0,
                'travel_fee' => $arrivedTravelFee,
                'cancellation_fee' => 0,
                'total_price' => $arrivedBasePrice + $arrivedTravelFee,
                'terms_accepted' => true,
                'address_latitude' => $arrivedLocation['latitude'],
                'address_longitude' => $arrivedLocation['longitude'],
                'started_travel_at' => $arrivedDate->copy()->setTime(17, 15),
                'arrived_at' => $arrivedDate->copy()->setTime(17, 45),
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
                    'body' => 'ا�"خد�.ة �"�. ت�f�? با�"�.ست�^�? ا�"�.ت�^�,ع�O �Sرج�? ا�"�.ساعدة ف�S ح�" ا�"�.ش�f�"ة.',
                ]);
            }

            SeederMedia::ensureSingleMedia(
                $dispute,
                'images',
                'https://images.unsplash.com/photo-1581578731548-c64695cc6952?auto=format&fit=crop&w=1200&q=80',
                "cleaning-dispute-{$dispute->id}-image"
            );
        }
    }

    /**
     * @return array<int, User>
     */
    private function ensureArabicCustomers(): array
    {
        $users = [];
        foreach (self::ARABIC_CUSTOMERS as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'phone' => '+9639'.mb_str_pad((string) fake()->unique()->numberBetween(1000000, 9999999), 7, '0'),
                    'password' => bcrypt('password'),
                    'email_verified_at' => now(),
                ]
            );

            SeederMedia::ensureSingleMedia(
                $user,
                'primary-image',
                'https://images.unsplash.com/photo-1531123897727-8f129e1688ce?auto=format&fit=crop&w=600&q=80',
                "cleaning-customer-{$user->id}-primary"
            );

            $users[] = $user;
        }

        return $users;
    }
}
