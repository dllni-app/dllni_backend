<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Services\FemaleWorkerSafetyPolicyService;

final class UserCleaningFemaleWorkerSafetyPolicyController
{
    public function __invoke(FemaleWorkerSafetyPolicyService $policy): JsonResponse
    {
        return response()->json([
            'data' => $policy->policyPayload(),
        ]);
    }
}
