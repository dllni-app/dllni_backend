<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\User;
use App\Support\SyrianPhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\SupervisedTeamService;

final class SupervisorAppController
{
    public function __construct(private readonly SupervisedTeamService $teams) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:32'], 'password' => ['required', 'string']]);
        // Match the canonical number and legacy formats already supported by customer login.
        $user = User::query()->whereIn('phone', SyrianPhoneNumber::aliases($data['phone']))->first();
        if (! $user || ! Hash::check($data['password'], $user->password) || ! $user->is_active
            || ! $user->hasRole(SupervisedTeamService::ROLE)) {
            return $this->error('INVALID_CREDENTIALS', 'بيانات الدخول غير صحيحة أو الحساب غير مخول.', 422);
        }

        return response()->json(['data' => [
            'token' => $user->createToken('supervisor-app', ['supervisor'])->plainTextToken,
            'user' => ['id' => $user->id, 'name' => $user->name, 'phone' => $user->phone],
        ]]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $this->teams->assertSupervisor($request->user());

        return response()->json(['data' => [
            'id' => $user->id, 'name' => $user->name, 'phone' => $user->phone,
            'role' => 'supervisor', 'serverNow' => now()->toIso8601String(),
            'timezone' => config('app.timezone'),
        ]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->teams->assertSupervisor($request->user());
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['data' => ['loggedOut' => true]]);
    }

    public function home(Request $request): JsonResponse
    {
        $query = $this->teams->assignedQuery($request->user());
        $today = now()->toDateString();
        $next = (clone $query)->whereDate('scheduled_date', '>=', $today)
            ->whereNotIn('status', [CleaningBookingStatus::Completed, CleaningBookingStatus::Cancelled])
            ->orderBy('scheduled_date')->orderBy('scheduled_time')->first();

        return response()->json(['data' => [
            'todayTotal' => (clone $query)->whereDate('scheduled_date', $today)->count(),
            'inProgress' => (clone $query)->where('status', CleaningBookingStatus::InProgress)->count(),
            'awaitingHandover' => (clone $query)->where('status', CleaningBookingStatus::AwaitingCustomerCompletion)
                ->where('supervised_state', 'handover_pending')->count(),
            'nextBooking' => $next ? $this->teams->summary($next) : null,
            'serverNow' => now()->toIso8601String(), 'timezone' => config('app.timezone'),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:pending,worker_assigned,awaiting_start_verification,awaiting_worker_start_confirmation,in_progress,awaiting_customer_completion,time_extension_requested,partially_completed,under_dispute,completed,cancelled'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'search' => ['sometimes', 'string', 'max:50'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $query = $this->teams->assignedQuery($request->user());
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (isset($data['date'])) {
            $query->whereDate('scheduled_date', $data['date']);
        }
        if (isset($data['search'])) {
            $query->where('booking_number', 'like', '%'.addcslashes($data['search'], '%_\\').'%');
        }
        $page = $query->orderBy('scheduled_date')->orderBy('scheduled_time')->orderBy('id')
            ->paginate((int) ($data['perPage'] ?? 15));

        return response()->json([
            'data' => $page->getCollection()->map(fn (CleaningBooking $booking): array => $this->teams->summary($booking))->all(),
            'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function schedule(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $bookings = $this->teams->assignedQuery($request->user())->whereDate('scheduled_date', $data['date'])
            ->orderBy('scheduled_time')->orderBy('id')->get();

        return response()->json(['data' => $bookings->map(fn (CleaningBooking $booking): array => $this->teams->summary($booking))->all()]);
    }

    public function show(Request $request, int $booking): JsonResponse
    {
        return response()->json(['data' => $this->teams->summary($this->teams->assignedBooking($request->user(), $booking))]);
    }

    public function attendance(Request $request, int $booking): JsonResponse
    {
        return response()->json(['data' => $this->teams->attendance($this->teams->assignedBooking($request->user(), $booking))]);
    }

    public function timeline(Request $request, int $booking): JsonResponse
    {
        $this->teams->assignedBooking($request->user(), $booking);
        $events = DB::table('cleaning_supervisor_activity')->where('cleaning_booking_id', $booking)
            ->orderByDesc('id')->limit(100)->get(['id', 'action', 'metadata', 'created_at']);

        return response()->json(['data' => $events]);
    }

    public function incidents(Request $request, int $booking): JsonResponse
    {
        $this->teams->assignedBooking($request->user(), $booking);
        $page = DB::table('cleaning_supervisor_incidents')->where('cleaning_booking_id', $booking)
            ->orderByDesc('id')->paginate(20, ['id', 'type', 'description', 'worker_assignment_id', 'status', 'created_at']);

        return response()->json(['data' => $page->items(), 'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function createIncident(Request $request, int $booking): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:late,absent,withdrawn,quality,damage,other'],
            'description' => ['required', 'string', 'min:5', 'max:4000'],
            'workerAssignmentId' => ['nullable', 'integer'],
        ]);
        $id = DB::transaction(function () use ($request, $booking, $data): int {
            $order = $this->teams->assignedBooking($request->user(), $booking, true);
            $this->assertOpen($order);
            $this->assertWorkerAssignment($order, $data['workerAssignmentId'] ?? null);
            $id = DB::table('cleaning_supervisor_incidents')->insertGetId([
                'cleaning_booking_id' => $booking, 'supervisor_user_id' => $request->user()->id,
                'worker_assignment_id' => $data['workerAssignmentId'] ?? null,
                'type' => $data['type'], 'description' => $data['description'],
                'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->teams->activity($booking, $request->user()->id, 'incident_created', ['incidentId' => $id]);

            return $id;
        });

        return response()->json(['data' => ['id' => $id, 'status' => 'open']], 201);
    }

    public function replacementRequest(Request $request, int $booking): JsonResponse
    {
        $data = $request->validate(['workerAssignmentId' => ['required', 'integer'], 'reason' => ['required', 'string', 'min:5', 'max:1500']]);
        $result = DB::transaction(function () use ($request, $booking, $data): array {
            $order = $this->teams->assignedBooking($request->user(), $booking, true);
            $this->assertOpen($order);
            $this->assertWorkerAssignment($order, $data['workerAssignmentId']);
            $pending = DB::table('cleaning_supervisor_replacements')->where('cleaning_booking_id', $booking)
                ->where('worker_assignment_id', $data['workerAssignmentId'])->where('status', 'pending')->first();
            if ($pending) {
                return ['id' => $pending->id, 'status' => 'pending'];
            }
            $id = DB::table('cleaning_supervisor_replacements')->insertGetId([
                'cleaning_booking_id' => $booking, 'supervisor_user_id' => $request->user()->id,
                'worker_assignment_id' => $data['workerAssignmentId'], 'reason' => $data['reason'],
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->teams->activity($booking, $request->user()->id, 'replacement_requested', ['requestId' => $id]);

            return ['id' => $id, 'status' => 'pending'];
        });

        return response()->json(['data' => $result], 201);
    }

    public function unavailable(Request $request, int $booking): JsonResponse
    {
        $this->teams->assignedBooking($request->user(), $booking);

        return $this->error('FEATURE_NOT_CONFIGURED', 'هذه الخطوة غير مفعلة بعد.', 409);
    }

    private function assertOpen(CleaningBooking $booking): void
    {
        abort_if(in_array($booking->status, [CleaningBookingStatus::Completed, CleaningBookingStatus::Cancelled], true), 409);
    }

    private function assertWorkerAssignment(CleaningBooking $booking, ?int $assignmentId): void
    {
        abort_if($assignmentId !== null && ! $booking->workerAssignments()->whereKey($assignmentId)->exists(), 422);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message], $status);
    }
}
