<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Services\CleaningV3LiveExtensionService;

final class CleaningV3LiveExtensionController
{
    public function current(Request $request, int $booking, int $session, CleaningV3LiveExtensionService $service): JsonResponse
    {
        return $this->respond(fn () => $service->current($booking, $session, $request->user()));
    }

    public function context(Request $request, int $booking, int $session, CleaningV3LiveExtensionService $service): JsonResponse
    {
        return $this->respond(fn () => $service->context($booking, $session, $request->user()));
    }

    public function quote(Request $request, int $booking, int $session, CleaningV3LiveExtensionService $service): JsonResponse
    {
        $data = $request->validate([
            'additionalMinutes' => ['required', 'integer', 'min:30', 'max:420', 'multiple_of:30'],
            'expectedPriceRevision' => ['required', 'integer', 'min:1', 'max:2147483646'],
            'expectedClockRevision' => ['required', 'integer', 'min:1', 'max:4294967294'],
            'commandKey' => ['required', 'string', 'max:96', 'regex:/^[a-zA-Z0-9_.:-]+$/D'],
        ]);
        return $this->respond(fn () => $service->quote($booking, $session, $request->user(),
            (int) $data['additionalMinutes'], (int) $data['expectedPriceRevision'], (int) $data['expectedClockRevision'], $data['commandKey']));
    }

    public function confirm(Request $request, int $booking, int $session, string $extension, CleaningV3LiveExtensionService $service): JsonResponse
    {
        $data = $request->validate([
            'signature' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'acceptedTotalSyp' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})\.[0-9]{2}$/D'],
            'accepted' => ['required', 'accepted'],
        ]);
        return $this->respond(fn () => $service->confirm($booking, $session, $extension, $request->user(),
            $data['signature'], $data['acceptedTotalSyp']));
    }

    public function decide(Request $request, int $booking, int $session, string $extension, CleaningV3LiveExtensionService $service): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject']]);
        return $this->respond(fn () => $service->decide($booking, $session, $extension, $request->user(), $data['decision']));
    }

    public function fundingRequirement(int $booking, int $session, string $extension, CleaningV3LiveExtensionService $service): JsonResponse
    {
        return $this->respond(fn () => $service->fundingRequirement($booking, $session, $extension));
    }

    public function apply(Request $request, int $booking, int $session, string $extension, CleaningV3LiveExtensionService $service): JsonResponse
    {
        $data = $request->validate(['proof' => ['required', 'array:payload,signature']]);
        return $this->respond(fn () => $service->apply($booking, $session, $extension, $data['proof']));
    }

    private function respond(callable $callback): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $callback()], 200, ['Cache-Control' => 'private, no-store']);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'code' => $e->getMessage(),
                'message' => 'تعذر تمديد الزيارة. تحقق من الوقت والسعر وموافقة الفريق وتغطية التمويل.'], 409, ['Cache-Control' => 'private, no-store']);
        }
    }
}
