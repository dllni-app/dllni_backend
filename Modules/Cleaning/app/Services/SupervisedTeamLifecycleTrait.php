<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;

trait SupervisedTeamLifecycleTrait
{
    public function assertCustomer(User $user, CleaningBooking $booking): void
    {
        abort_unless($booking->isSupervisedTeam() && (int) $booking->customer_id === (int) $user->id, 404);
    }

    public function state(CleaningBooking $booking): string
    {
        return (string) ($booking->supervised_state ?: 'staffing');
    }

    public function ready(CleaningBooking $booking): bool
    {
        return $booking->workerAssignments()->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->whereNotNull('arrived_at')->count() >= max(1, (int) $booking->number_of_workers);
    }

    public function initialize(CleaningBooking $booking): CleaningBooking
    {
        return DB::transaction(function () use ($booking): CleaningBooking {
            $locked = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($locked->isSupervisedTeam()) {
                return $locked;
            }
            abort_unless(in_array($locked->booking_kind, ['standard', 'special_service'], true) && $locked->workerAssignments()->doesntExist()
                && $locked->work_started_at === null && $locked->status === CleaningBookingStatus::Pending, 409);
            $workers = max(2, (int) $locked->number_of_workers);
            $base = max(1, (int) ceil(((float) $locked->estimated_hours) * 60));
            $duration = (int) ceil($base / $workers);
            $ends = Carbon::parse($locked->scheduled_date->format('Y-m-d').' '.$locked->scheduled_time, config('app.timezone'))
                ->addMinutes($duration);
            $locked->forceFill(['booking_kind' => self::KIND, 'supervised_state' => 'staffing',
                'supervised_base_minutes' => $base, 'supervised_duration_minutes' => $duration,
                'supervised_scheduled_end_at' => $ends, 'supervised_original_end_at' => $ends])->save();
            $this->activity($locked->id, (int) $locked->customer_id, 'team_created', ['durationMinutes' => $duration]);
            return $locked;
        });
    }

    public function requestVerification(User $user, int $bookingId): array
    {
        return DB::transaction(function () use ($user, $bookingId): array {
            $booking = $this->assignedQuery($user)->lockForUpdate()->findOrFail($bookingId);
            abort_unless(in_array($this->state($booking), ['staffing', 'verification_pending'], true), 409);
            abort_unless($this->ready($booking), 409, 'حضور الفريق غير مكتمل.');
            $old = DB::table('cleaning_supervisor_verifications')->where('cleaning_booking_id', $bookingId)->first();
            if ($old && ! $old->consumed_at && Carbon::parse($old->expires_at)->isFuture() && (int) $old->attempts < 5) {
                return ['state' => 'verification_pending', 'expiresAt' => $old->expires_at];
            }
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiry = now()->addMinutes(10);
            DB::table('cleaning_supervisor_verifications')->updateOrInsert(['cleaning_booking_id' => $bookingId], [
                'supervisor_user_id' => $user->id,
                'code_hash' => $this->verificationHash($bookingId, $code),
                'encrypted_code' => Crypt::encryptString($code), 'attempts' => 0,
                'expires_at' => $expiry, 'consumed_at' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $booking->forceFill(['supervised_state' => 'verification_pending'])->save();
            $this->activity($bookingId, $user->id, 'verification_requested');
            return ['state' => 'verification_pending', 'expiresAt' => $expiry->toIso8601String()];
        });
    }

    public function customerCode(User $user, CleaningBooking $booking): array
    {
        $this->assertCustomer($user, $booking);
        $session = DB::table('cleaning_supervisor_verifications')->where('cleaning_booking_id', $booking->id)->first();
        abort_unless($session && ! $session->consumed_at && (int) $session->attempts < 5
            && Carbon::parse($session->expires_at)->isFuture(), 409);
        return ['code' => Crypt::decryptString($session->encrypted_code), 'expiresAt' => $session->expires_at];
    }

    public function confirmVerification(User $user, int $bookingId, string $code): array
    {
        $bad = DB::transaction(function () use ($user, $bookingId, $code): bool {
            $booking = $this->assignedQuery($user)->lockForUpdate()->findOrFail($bookingId);
            abort_unless($this->state($booking) === 'verification_pending', 409);
            $session = DB::table('cleaning_supervisor_verifications')->where('cleaning_booking_id', $bookingId)
                ->lockForUpdate()->first();
            abort_unless($session && ! $session->consumed_at && Carbon::parse($session->expires_at)->isFuture(), 409);
            abort_if((int) $session->attempts >= 5, 429);
            if (! hash_equals($session->code_hash, $this->verificationHash($bookingId, $code))) {
                DB::table('cleaning_supervisor_verifications')->where('id', $session->id)
                    ->update(['attempts' => $session->attempts + 1, 'updated_at' => now()]);
                return true;
            }
            abort_unless($this->ready($booking), 409);
            DB::table('cleaning_supervisor_verifications')->where('id', $session->id)
                ->update(['consumed_at' => now(), 'updated_at' => now()]);
            $booking->forceFill(['supervised_state' => 'verified', 'supervised_verified_at' => now()])->save();
            $this->activity($bookingId, $user->id, 'verified');
            return false;
        });
        if ($bad) {
            throw ValidationException::withMessages(['code' => ['رمز التحقق غير صحيح.']]);
        }
        return $this->summary($this->assignedBooking($user, $bookingId));
    }

    public function transition(User $user, int $bookingId, string $action): array
    {
        $updated = DB::transaction(function () use ($user, $bookingId, $action): CleaningBooking {
            $b = $this->assignedQuery($user)->lockForUpdate()->findOrFail($bookingId);
            $state = $this->state($b);
            if ($action === 'start') {
                abort_unless(in_array($state, ['verified', 'in_progress'], true), 409);
                if ($state === 'verified') {
                    abort_unless($this->ready($b), 409);
                    $b->forceFill(['supervised_state' => 'in_progress',
                        'status' => CleaningBookingStatus::InProgress, 'work_started_at' => now()])->save();
                    $this->activity($b->id, $user->id, 'work_started');
                }
            } elseif ($action === 'handover') {
                abort_unless(in_array($state, ['in_progress', 'handover_pending'], true), 409);
                if ($state === 'in_progress') {
                    DB::table('cleaning_supervisor_handovers')->updateOrInsert(
                        ['cleaning_booking_id' => $b->id],
                        ['supervisor_user_id' => $user->id, 'status' => 'pending',
                            'objection' => null, 'responded_at' => null, 'updated_at' => now(), 'created_at' => now()]
                    );
                    $b->forceFill(['supervised_state' => 'handover_pending',
                        'status' => CleaningBookingStatus::AwaitingCustomerCompletion, 'work_finished_at' => now()])->save();
                    $this->activity($b->id, $user->id, 'handover_requested');
                }
            } else {
                abort(422);
            }
            return $b;
        });
        return $this->summary($updated->fresh());
    }

    private function verificationHash(int $bookingId, string $code): string
    {
        return hash_hmac('sha256', $bookingId.':'.$code, (string) config('app.key'));
    }
}
