<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Enums\GenderPreference;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\User\Models\UserAddress;

final class CleaningV3CustomerPreferences
{
    public function history(int $customerId, ?array $ids = null)
    {
        $completed = CleaningBookingStatus::Completed->value;
        $worked = CleaningBookingWorkerAssignmentStatus::Completed->value;
        $parent = DB::table('cleaning_booking_worker_assignments as a')
            ->join('cleaning_bookings as b', 'b.id', '=', 'a.cleaning_booking_id')
            ->where('b.customer_id', $customerId)
            ->where(fn ($q) => $q->where('a.status', $worked)
                ->orWhere(fn ($q) => $q->where('b.status', $completed)
                    ->whereIn('a.status', CleaningBookingWorkerAssignmentStatus::acceptedValues())))
            ->select('a.worker_id');
        $sessions = DB::table('cleaning_booking_session_worker_assignments as a')
            ->join('cleaning_booking_sessions as s', 's.id', '=', 'a.cleaning_booking_session_id')
            ->join('cleaning_bookings as b', 'b.id', '=', 's.cleaning_booking_id')
            ->where('b.customer_id', $customerId)->where('a.status', $worked)->select('a.worker_id');
        $legacy = DB::table('cleaning_bookings as b')->where('b.customer_id', $customerId)
            ->where('b.status', $completed)->whereNotNull('b.worker_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cleaning_booking_worker_assignments as a')->whereColumn('a.cleaning_booking_id', 'b.id'))
            ->select('b.worker_id');
        return Worker::query()->whereIn('id', $parent->union($sessions)->union($legacy))
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('id');
    }

    public function addresses(int $customerId): array
    {
        return UserAddress::query()->where('user_id', $customerId)->orderByDesc('is_default')->orderBy('id')
            ->get()->filter(fn (UserAddress $a) => $this->validAddress($a))
            ->map(fn (UserAddress $a) => $this->address($a))->values()->all();
    }

    private function validAddress(UserAddress $a): bool
    {
        return is_numeric($a->latitude) && is_numeric($a->longitude)
            && abs((float) $a->latitude) <= 90 && abs((float) $a->longitude) <= 180
            && ! ((float) $a->latitude === 0.0 && (float) $a->longitude === 0.0)
            && trim((string) $a->street) !== '';
    }

    private function address(UserAddress $a): array
    {
        $snapshot = [
            'addressId' => $a->id, 'label' => $a->label, 'address' => $a->street,
            'details' => $a->directions, 'latitude' => (string) $a->latitude,
            'longitude' => (string) $a->longitude, 'isDefault' => $a->is_default,
        ];
        return [...$snapshot, 'revision' => hash('sha256', json_encode([
            ...$snapshot, 'city' => $a->city, 'neighborhood' => $a->neighborhood,
            'building' => $a->building, 'floor' => $a->floor, 'mobile' => $a->mobile,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
    }

    /** Resolve a reviewed, owned destination and explicit preferences. No availability or booking claim. */
    public function resolve(int $customerId, mixed $input, bool $lock = false): array
    {
        if ($lock && DB::transactionLevel() < 1) {
            throw new \DomainException('V3_BOOKING_TRANSACTION_REQUIRED');
        }
        if (! is_array($input) || array_diff(array_keys($input), ['addressId', 'addressRevision', 'genderPreference', 'excludedWorkerIds']) !== []
            || ! is_int($input['addressId'] ?? null) || $input['addressId'] < 1
            || ! is_string($input['addressRevision'] ?? null)
            || ! is_string($input['genderPreference'] ?? null)
            || GenderPreference::tryFrom($input['genderPreference']) === null
            || ! is_array($input['excludedWorkerIds'] ?? null) || ! array_is_list($input['excludedWorkerIds'])
            || count($input['excludedWorkerIds']) > 100) {
            throw new CleaningV3VisitPlanException('preferences', 'V3_CUSTOMER_PREFERENCES_INVALID');
        }
        $ids = $input['excludedWorkerIds'];
        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1) {
                throw new CleaningV3VisitPlanException('excludedWorkerIds', 'V3_EXCLUDED_WORKER_INVALID');
            }
        }
        if (count(array_unique($ids)) !== count($ids) || ($ids !== [] && $this->history($customerId, $ids)->count() !== count($ids))) {
            throw new CleaningV3VisitPlanException('excludedWorkerIds', 'V3_EXCLUDED_WORKER_INVALID');
        }
        sort($ids, SORT_NUMERIC);
        $query = UserAddress::query()->where('user_id', $customerId)->whereKey($input['addressId']);
        $address = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($address === null || ! $this->validAddress($address)) {
            throw new CleaningV3VisitPlanException('addressId', 'V3_ADDRESS_UNAVAILABLE');
        }
        $snapshot = $this->address($address);
        if (! hash_equals($snapshot['revision'], $input['addressRevision'])) {
            throw new CleaningV3VisitPlanException('addressRevision', 'V3_ADDRESS_CHANGED');
        }
        return [
            'address' => $snapshot, 'genderPreference' => $input['genderPreference'],
            'excludedWorkerIds' => $ids, 'checked' => true, 'requiresActualWorkerConflictCheck' => true,
        ];
    }
}
