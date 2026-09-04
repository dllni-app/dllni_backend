from pathlib import Path


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{label}: expected one match, found {count}")
    return text.replace(old, new, 1)


cancellation = Path("Modules/Cleaning/app/Services/CleaningBookingSessionCancellationService.php")
text = cancellation.read_text()
old = '''    private function assertCustomerCanSkipRecurring(CleaningBookingSession $session): void
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
new = '''    private function assertCustomerCanSkipRecurring(CleaningBookingSession $session): void
    {
        if ((string) $session->session_type !== 'recurring_cleaning') {
            throw new InvalidArgumentException('Only recurring cleaning sessions can be skipped.');
        }
        if ($session->isTerminal()) {
            throw new InvalidArgumentException('Session is already closed.');
        }
        if (! in_array($this->statusValue($session), [
            CleaningBookingSessionStatus::Scheduled->value,
            CleaningBookingSessionStatus::WorkerAssigned->value,
        ], true)) {
            throw new InvalidArgumentException('Only a recurring session waiting to start can be skipped.');
        }
        $startsAt = $session->startsAt();
        if ($startsAt === null || ! $startsAt->isFuture()) {
            throw new InvalidArgumentException('Only a future recurring session can be skipped.');
        }
        if ($session->started_travel_at !== null || $session->work_started_at !== null) {
            throw new InvalidArgumentException('A recurring session cannot be skipped after worker travel starts.');
        }
    }
'''
text = replace_once(text, old, new, "skip assertion")
cancellation.write_text(text)

presenter = Path("Modules/Cleaning/app/Services/CleaningBookingSchedulePresenter.php")
text = presenter.read_text()
old = '''        $canConfirmCompletion = $isCustomerView
            && $status === CleaningBookingSessionStatus::AwaitingCustomerCompletion->value;
        $canSendSos = ! $session->isTerminal()
            && ($isCustomerView || $hasMyActiveAssignment);

        return [
'''
new = '''        $canConfirmCompletion = $isCustomerView
            && $status === CleaningBookingSessionStatus::AwaitingCustomerCompletion->value;
        $hasStartedTravelAssignment = $session->workerAssignments->contains(
            static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->isActive()
                && $assignment->started_travel_at !== null,
        );
        $canSkip = $isCustomerView
            && (string) $session->session_type === 'recurring_cleaning'
            && in_array($status, [
                CleaningBookingSessionStatus::Scheduled->value,
                CleaningBookingSessionStatus::WorkerAssigned->value,
            ], true)
            && ! $session->isTerminal()
            && $startsAt !== null
            && $startsAt->gt($now)
            && $session->started_travel_at === null
            && $session->work_started_at === null
            && ! $hasStartedTravelAssignment;
        $canSendSos = ! $session->isTerminal()
            && ($isCustomerView || $hasMyActiveAssignment);

        return [
'''
text = replace_once(text, old, new, "presenter canSkip calculation")
old = '''            'canExtend' => false,
            'canCancel' => $canCancel,
            'canReschedule' => $isCustomerView && $canReschedule,
'''
new = '''            'canExtend' => false,
            'canCancel' => $canCancel,
            'canSkip' => $canSkip,
            'canReschedule' => $isCustomerView && $canReschedule,
'''
text = replace_once(text, old, new, "presenter canSkip payload")
presenter.write_text(text)

test = Path("tests/Feature/Cleaning/RecurringCleaningWorkerContinuityTest.php")
text = test.read_text()
old = '''    Sanctum::actingAs($customer);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$firstSession->id}/skip",
'''
new = '''    Sanctum::actingAs($customer);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.canSkip', true)
        ->assertJsonPath('data.schedule.sessions.1.canSkip', true);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$firstSession->id}/skip",
'''
text = replace_once(text, old, new, "successful skip preflight")
old = '''        ->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::Skipped->value)
        ->assertJsonPath('data.schedule.sessions.1.status', CleaningBookingSessionStatus::WorkerAssigned->value);
'''
new = '''        ->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::Skipped->value)
        ->assertJsonPath('data.schedule.sessions.0.canSkip', false)
        ->assertJsonPath('data.schedule.sessions.1.status', CleaningBookingSessionStatus::WorkerAssigned->value)
        ->assertJsonPath('data.schedule.sessions.1.canSkip', true);
'''
text = replace_once(text, old, new, "successful skip response")
marker = '''it('does not expose recurring skip for an ordinary cleaning session', function (): void {
'''
cases = '''it('rejects skipping a recurring visit whose scheduled start is not in the future', function (): void {
    [$customer, , $worker, $booking] = makeRecurringWorkerContinuityScenario();
    $session = makeRecurringWorkerContinuitySession($booking, 1);
    $assignment = makeRecurringWorkerContinuityAssignment($session, $worker);
    $session->forceFill([
        'scheduled_date' => now()->subDay()->toDateString(),
        'scheduled_time' => now()->subHour()->format('H:i'),
    ])->save();

    Sanctum::actingAs($customer);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.canSkip', false);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/skip",
        ['reason' => 'محاولة تخطي زيارة انتهى موعدها'],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($session->fresh()->status)->toBe(CleaningBookingSessionStatus::WorkerAssigned)
        ->and($assignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart);
});

it('does not advertise skip after an assigned worker starts travel', function (): void {
    [$customer, , $worker, $booking] = makeRecurringWorkerContinuityScenario();
    $session = makeRecurringWorkerContinuitySession($booking, 1);
    $assignment = makeRecurringWorkerContinuityAssignment($session, $worker);
    $assignment->forceFill(['started_travel_at' => now()])->save();

    Sanctum::actingAs($customer);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.canSkip', false);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/skip",
        ['reason' => 'محاولة تخطي زيارة بعد بدء الطريق'],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($session->fresh()->status)->toBe(CleaningBookingSessionStatus::WorkerAssigned)
        ->and($assignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart);
});

'''
text = replace_once(text, marker, cases + marker, "skip negative cases")
old = '''    Sanctum::actingAs($customer);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/skip",
        ['reason' => 'محاولة تخطي جلسة غير دورية'],
'''
new = '''    Sanctum::actingAs($customer);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.canSkip', false);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/skip",
        ['reason' => 'محاولة تخطي جلسة غير دورية'],
'''
text = replace_once(text, old, new, "ordinary canSkip false")
test.write_text(text)
