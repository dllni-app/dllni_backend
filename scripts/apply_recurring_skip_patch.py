from pathlib import Path


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{label}: expected one match, found {count}")
    return text.replace(old, new, 1)


cancellation = Path("Modules/Cleaning/app/Services/CleaningBookingSessionCancellationService.php")
text = cancellation.read_text()
method = r'''    public function skipRecurringByCustomer(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        int $customerId,
        string $reason,
    ): CleaningBookingSession {
        if ((int) $booking->customer_id !== $customerId) {
            abort(403, 'Booking belongs to another customer.');
        }

        $normalizedReason = $this->requiredReason($reason);
        $affectedWorkerIds = [];
        $fromStatus = '';

        $updated = DB::transaction(function () use (
            $booking,
            $session,
            $normalizedReason,
            &$affectedWorkerIds,
            &$fromStatus,
        ): CleaningBookingSession {
            $locked = $this->lockSession($booking, $session);
            $this->assertCustomerCanSkipRecurring($locked);
            $fromStatus = $this->statusValue($locked);
            $skippedAt = now();

            $assignments = CleaningBookingSessionWorkerAssignment::query()
                ->where('cleaning_booking_session_id', $locked->id)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                ->lockForUpdate()
                ->get();

            if ($assignments->contains(
                static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->started_travel_at !== null,
            )) {
                throw new InvalidArgumentException('A recurring session cannot be skipped after worker travel starts.');
            }

            $affectedWorkerIds = $assignments
                ->pluck('worker_id')
                ->map(static fn (mixed $workerId): int => (int) $workerId)
                ->filter(static fn (int $workerId): bool => $workerId > 0)
                ->unique()
                ->values()
                ->all();

            foreach ($assignments as $assignment) {
                $assignment->forceFill([
                    'status' => CleaningBookingWorkerAssignmentStatus::Cancelled,
                    'released_at' => $skippedAt,
                    'released_reason' => 'Customer skipped recurring session: '.$normalizedReason,
                ])->save();
            }

            $locked->forceFill([
                'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
                'status' => CleaningBookingSessionStatus::Skipped,
                'cancellation_fee' => 0,
                'skipped_at' => $skippedAt,
                'skip_source' => 'customer',
                'skip_reason' => $normalizedReason,
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'cancelled_by_role' => null,
                'version' => max(1, (int) $locked->version) + 1,
            ])->save();

            $this->syncParentFinancials($booking);

            return $locked->fresh(['workerAssignments.worker.user']) ?? $locked;
        });

        $this->parentState->refresh($booking);

        foreach ($affectedWorkerIds as $workerId) {
            $this->notifications->notifyWorkerById(
                booking: $booking->fresh() ?? $booking,
                workerId: $workerId,
                canonicalType: 'cleaning.booking.updated',
                action: 'customer_skipped_recurring_session',
                actorRole: 'customer',
                fromStatus: $fromStatus !== '' ? $fromStatus : null,
                occurredAt: $updated->skipped_at?->toIso8601String(),
                extraData: array_merge($this->sessionContext($updated), [
                    'skipReason' => $normalizedReason,
                    'skip_reason' => $normalizedReason,
                ]),
            );
        }

        return $updated->fresh(['workerAssignments.worker.user']) ?? $updated;
    }

'''
text = replace_once(text, "    public function cancelByWorker(\n", method + "    public function cancelByWorker(\n", "cancelByWorker")

assertion = r'''    private function assertCustomerCanSkipRecurring(CleaningBookingSession $session): void
    {
        if ((string) $session->session_type !== 'recurring_cleaning') {
            throw new InvalidArgumentException('Only recurring cleaning sessions can be skipped.');
        }
        if ($session->isTerminal()) {
            throw new InvalidArgumentException('Session is already closed.');
        }
        if ($session->started_travel_at !== null || $session->work_started_at !== null) {
            throw new InvalidArgumentException('A recurring session cannot be skipped after worker travel starts.');
        }
    }

'''
text = replace_once(text, "    private function assertWorkerCanWithdraw(CleaningBookingSession $session): void\n", assertion + "    private function assertWorkerCanWithdraw(CleaningBookingSession $session): void\n", "worker assertion")
cancellation.write_text(text)

controller = Path("Modules/Cleaning/app/Http/Controllers/API/CleaningBookingSessionLifecycleController.php")
text = controller.read_text()
controller_method = r'''    public function skip(
        Request $request,
        CleaningBooking $cleaning_booking,
        CleaningBookingSession $cleaning_booking_session,
    ): JsonResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $user = $request->user();

        if ($user === null || (int) $cleaning_booking->customer_id !== (int) $user->id) {
            abort(403, 'Only the booking customer can skip a recurring session.');
        }

        try {
            $session = $this->cancellation->skipRecurringByCustomer(
                $cleaning_booking,
                $cleaning_booking_session,
                (int) $user->id,
                (string) $validated['reason'],
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return $this->payload($cleaning_booking, $session);
    }

'''
text = replace_once(text, "    public function sos(\n", controller_method + "    public function sos(\n", "controller sos")
controller.write_text(text)

routes = Path("Modules/Cleaning/routes/sessions.php")
text = routes.read_text()
route = r'''        Route::post(
            'cleaning-bookings/{cleaning_booking}/sessions/{cleaning_booking_session}/skip',
            [CleaningBookingSessionLifecycleController::class, 'skip'],
        )->name('cleaning-bookings.sessions.skip');

'''
route_marker = "        Route::post(\n            'cleaning-bookings/{cleaning_booking}/sessions/{cleaning_booking_session}/sos',\n"
text = replace_once(text, route_marker, route + route_marker, "route sos")
routes.write_text(text)

test = Path("tests/Feature/Cleaning/RecurringCleaningWorkerContinuityTest.php")
text = test.read_text()
cases = r'''it('lets the customer skip one future recurring visit without cancelling the series', function (): void {
    [$customer, , $worker, $booking] = makeRecurringWorkerContinuityScenario();
    $firstSession = makeRecurringWorkerContinuitySession($booking, 1);
    $secondSession = makeRecurringWorkerContinuitySession($booking, 2);
    $firstAssignment = makeRecurringWorkerContinuityAssignment($firstSession, $worker);
    $secondAssignment = makeRecurringWorkerContinuityAssignment($secondSession, $worker);

    Sanctum::actingAs($customer);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$firstSession->id}/skip",
        ['reason' => 'لن نحتاج زيارة التنظيف في هذا الموعد'],
    )
        ->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::Skipped->value)
        ->assertJsonPath('data.schedule.sessions.1.status', CleaningBookingSessionStatus::WorkerAssigned->value);

    expect($firstSession->fresh()->status)->toBe(CleaningBookingSessionStatus::Skipped)
        ->and($firstSession->fresh()->skip_source)->toBe('customer')
        ->and($firstSession->fresh()->skip_reason)->toBe('لن نحتاج زيارة التنظيف في هذا الموعد')
        ->and($firstSession->fresh()->skipped_at)->not->toBeNull()
        ->and((float) $firstSession->fresh()->cancellation_fee)->toBe(0.0)
        ->and($firstAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::Cancelled)
        ->and($firstAssignment->fresh()->released_reason)->toContain('Customer skipped recurring session')
        ->and($secondAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart)
        ->and($secondSession->fresh()->status)->toBe(CleaningBookingSessionStatus::WorkerAssigned)
        ->and($booking->fresh()->status)->not->toBe(CleaningBookingStatus::Cancelled)
        ->and((float) $booking->fresh()->total_hours)->toBe(2.0)
        ->and((float) $booking->fresh()->total_price)->toBe(3300.0);

    $this->assertDatabaseMissing('cleaning_booking_session_financial_penalties', [
        'cleaning_booking_session_id' => $firstSession->id,
    ]);
});

it('does not expose recurring skip for an ordinary cleaning session', function (): void {
    [$customer, , $worker, $booking] = makeRecurringWorkerContinuityScenario();
    $session = makeRecurringWorkerContinuitySession($booking, 1, 'regular_cleaning');
    $assignment = makeRecurringWorkerContinuityAssignment($session, $worker);

    Sanctum::actingAs($customer);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/skip",
        ['reason' => 'محاولة تخطي جلسة غير دورية'],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($session->fresh()->status)->toBe(CleaningBookingSessionStatus::WorkerAssigned)
        ->and($assignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart);
});

'''
helper_marker = "/** @return array{0:User,1:User,2:Worker,3:CleaningBooking} */\nfunction makeRecurringWorkerContinuityScenario(): array\n"
text = replace_once(text, helper_marker, cases + helper_marker, "recurring helper")
test.write_text(text)
