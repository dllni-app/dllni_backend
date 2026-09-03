from pathlib import Path


def replace(path: str, old: str, new: str) -> None:
    p = Path(path)
    text = p.read_text()
    if old not in text:
        raise SystemExit(f'pattern not found in {path}: {old[:160]!r}')
    p.write_text(text.replace(old, new, 1))


# Parent booking exposes execution sessions.
path = 'Modules/Cleaning/app/Models/CleaningBooking.php'
replace(
    path,
    """    public function rooms(): HasMany\n    {\n        return $this->hasMany(CleaningBookingRoom::class, 'cleaning_booking_id');\n    }\n\n""",
    """    public function rooms(): HasMany\n    {\n        return $this->hasMany(CleaningBookingRoom::class, 'cleaning_booking_id');\n    }\n\n    public function sessions(): HasMany\n    {\n        return $this->hasMany(CleaningBookingSession::class, 'cleaning_booking_id')\n            ->orderBy('sequence');\n    }\n\n""",
)

# Session completion has a customer-specific confirmation timestamp, distinct from work_finished_at.
path = 'Modules/Cleaning/app/Models/CleaningBookingSession.php'
replace(
    path,
    """        'work_started_at',\n        'work_finished_at',\n        'skipped_at',\n""",
    """        'work_started_at',\n        'work_finished_at',\n        'customer_completed_at',\n        'skipped_at',\n""",
)
replace(
    path,
    """            'work_started_at' => 'datetime',\n            'work_finished_at' => 'datetime',\n            'skipped_at' => 'datetime',\n""",
    """            'work_started_at' => 'datetime',\n            'work_finished_at' => 'datetime',\n            'customer_completed_at' => 'datetime',\n            'skipped_at' => 'datetime',\n""",
)

migration = Path('Modules/Cleaning/database/migrations/2026_09_03_090500_add_customer_completed_at_to_cleaning_booking_sessions_table.php')
migration.write_text("""<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cleaning_booking_sessions', 'customer_completed_at')) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
                $table->timestamp('customer_completed_at')->nullable()->after('work_finished_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cleaning_booking_sessions', 'customer_completed_at')) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
                $table->dropColumn('customer_completed_at');
            });
        }
    }
};
""")

# Register the read-only Filament relation manager.
path = 'app/Filament/Resources/CleaningBookings/CleaningBookingResource.php'
replace(
    path,
    "use App\\Filament\\Resources\\CleaningBookings\\Pages\\ViewCleaningBooking;\n",
    "use App\\Filament\\Resources\\CleaningBookings\\Pages\\ViewCleaningBooking;\nuse App\\Filament\\Resources\\CleaningBookings\\RelationManagers\\SessionsRelationManager;\n",
)
replace(
    path,
    """    public static function getRelations(): array\n    {\n        return [];\n    }\n""",
    """    public static function getRelations(): array\n    {\n        return [\n            SessionsRelationManager::class,\n        ];\n    }\n""",
)

# Add event progress and next appointment to the parent list and eager-load sessions.
path = 'app/Filament/Resources/CleaningBookings/Tables/CleaningBookingsTable.php'
replace(
    path,
    "use Modules\\Cleaning\\Enums\\CleaningBookingStatus;\n",
    "use Modules\\Cleaning\\Enums\\CleaningBookingSessionStatus;\nuse Modules\\Cleaning\\Enums\\CleaningBookingStatus;\n",
)
needle = """                TextColumn::make('booking_kind')\n                    ->label(self::headerLabel('نوع الحجز', 'يميز بين تنظيف عادي، تنظيف عميق، وطلب مساعدة مناسبة.'))\n                    ->getStateUsing(fn (CleaningBooking $record): string => $record->dashboardKindLabel())\n                    ->badge()\n                    ->color(fn (CleaningBooking $record): string => $record->dashboardKindColor()),\n"""
addition = needle + """                TextColumn::make('event_progress')\n                    ->label(self::headerLabel('تقدم المناسبة', 'عدد أيام المناسبة المكتملة من إجمالي أيام التنفيذ.'))\n                    ->getStateUsing(function (CleaningBooking $record): string {\n                        if (! $record->isEventAssistanceBooking() || $record->sessions->isEmpty()) {\n                            return '-';\n                        }\n\n                        $completed = $record->sessions->filter(\n                            fn ($session): bool => ($session->status?->value ?? (string) $session->status)\n                                === CleaningBookingSessionStatus::Completed->value,\n                        )->count();\n\n                        return $completed.' / '.$record->sessions->count();\n                    })\n                    ->badge()\n                    ->color('info')\n                    ->toggleable(),\n                TextColumn::make('event_next_appointment')\n                    ->label(self::headerLabel('موعد المناسبة القادم', 'أقرب يوم تنفيذ غير منتهٍ ضمن نفس رقم الحجز.'))\n                    ->getStateUsing(function (CleaningBooking $record): string {\n                        if (! $record->isEventAssistanceBooking()) {\n                            return '-';\n                        }\n\n                        $next = $record->sessions->first(function ($session): bool {\n                            $status = $session->status?->value ?? (string) $session->status;\n\n                            return ! in_array($status, CleaningBookingSessionStatus::terminalValues(), true);\n                        });\n\n                        if ($next === null) {\n                            return '-';\n                        }\n\n                        return $next->scheduled_date?->format('Y-m-d').' '.self::time($next->scheduled_time);\n                    })\n                    ->toggleable(),\n"""
replace(path, needle, addition)
replace(
    path,
    """                    'workerAssignments.worker.user',\n                ])\n""",
    """                    'workerAssignments.worker.user',\n                    'sessions',\n                ])\n""",
)

# Record the actual customer completion timestamp and notify about day/final-event completion.
path = 'Modules/Cleaning/app/Services/CleaningBookingSessionLifecycleService.php'
replace(
    path,
    """            $locked->forceFill([\n                'status' => CleaningBookingSessionStatus::Completed,\n                'work_finished_at' => $locked->work_finished_at ?? $completedAt,\n            ])->save();\n\n            $this->syncParentStatus($booking);\n\n            return $this->freshSession($locked);\n""",
    """            $locked->forceFill([\n                'status' => CleaningBookingSessionStatus::Completed,\n                'work_finished_at' => $locked->work_finished_at ?? $completedAt,\n                'customer_completed_at' => $locked->customer_completed_at ?? $completedAt,\n            ])->save();\n\n            $this->syncParentStatus($booking);\n\n            $bookingId = (int) $booking->id;\n            $sessionId = (int) $locked->id;\n            DB::afterCommit(static function () use ($bookingId, $sessionId): void {\n                $freshBooking = CleaningBooking::query()->with('customer')->find($bookingId);\n                $freshSession = CleaningBookingSession::query()->find($sessionId);\n\n                if ($freshBooking instanceof CleaningBooking && $freshSession instanceof CleaningBookingSession) {\n                    app(CleaningEventSessionNotificationService::class)\n                        ->notifyCompleted($freshBooking, $freshSession);\n                }\n            });\n\n            return $this->freshSession($locked);\n""",
)

# Existing scheduled notification runner now also processes child event-day reminders.
path = 'Modules/Cleaning/app/Services/CleaningBookingActionNotificationService.php'
replace(
    path,
    """        private readonly CleaningRepeatedActionNotificationRuleEngine $repeatedRuleEngine,\n        private readonly CleaningLifecycleNotificationService $lifecycleNotifications,\n""",
    """        private readonly CleaningRepeatedActionNotificationRuleEngine $repeatedRuleEngine,\n        private readonly CleaningLifecycleNotificationService $lifecycleNotifications,\n        private readonly CleaningEventSessionNotificationService $eventSessionNotifications,\n""",
)
replace(
    path,
    """        $sent = 0;\n\n        CleaningBooking::query()\n""",
    """        $sent = $this->eventSessionNotifications->dispatchDueReminders($now);\n\n        CleaningBooking::query()\n""",
)
