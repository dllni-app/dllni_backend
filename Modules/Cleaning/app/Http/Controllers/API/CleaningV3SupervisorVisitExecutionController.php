<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Services\CleaningV3SupervisorVisitExecutionService;

final class CleaningV3SupervisorVisitExecutionController
{
    public function __invoke(Request $request, int $booking, int $session, string $action, CleaningV3SupervisorVisitExecutionService $service): JsonResponse
    {
        $actor = $request->user();
        try {
            $data = match ($action) {
                'state' => $service->state($booking, $session, $actor),
                'customer-code' => $service->issue($booking, $session, $actor),
                'verify' => $service->verify($booking, $session, $actor, $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/D']])['code']),
                'start' => $service->start($booking, $session, $actor),
                'finish' => $service->finish($booking, $session, $actor, \Modules\Cleaning\Services\CleaningCustomerIncidentService::validate($request)),
                default => abort(404),
            };
            return response()->json(['success' => true, 'data' => $data])->header('Cache-Control', 'private, no-store');
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'code' => $e->getMessage(),
                'message' => 'تعذر تنفيذ الإجراء. تحقق من حالة الزيارة وحضور الفريق ورمز التحقق.'], 409)->header('Cache-Control', 'private, no-store');
        }
    }
}
