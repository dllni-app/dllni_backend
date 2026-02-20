<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Cleaning\Data\CleaningBillingPolicyData;
use Modules\Cleaning\Http\Requests\CleaningBillingPolicyRequest;
use Modules\Cleaning\Http\Requests\CleaningBillingPolicyRequests\CleaningBillingPolicyFilterRequest;
use Modules\Cleaning\Http\Resources\CleaningBillingPolicyResource;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Services\CleaningBillingPolicyService;
use Throwable;

final class CleaningBillingPolicyController
{
    public function __construct(
        private readonly CleaningBillingPolicyService $cleaningBillingPolicyService
    ) {}

    public function index(CleaningBillingPolicyFilterRequest $request): AnonymousResourceCollection
    {
        $policies = CleaningBillingPolicy::getQuery()
            ->paginate($request->get('perPage', 20));

        return CleaningBillingPolicyResource::collection($policies);
    }

    /** @throws Throwable */
    public function store(CleaningBillingPolicyRequest $request): CleaningBillingPolicyResource
    {
        $policy = $this->cleaningBillingPolicyService->store(
            CleaningBillingPolicyData::from($request->validated())
        );

        return CleaningBillingPolicyResource::make($policy);
    }

    public function show(CleaningBillingPolicy $cleaning_billing_policy): CleaningBillingPolicyResource
    {
        return CleaningBillingPolicyResource::make($cleaning_billing_policy);
    }

    /** @throws Throwable */
    public function update(CleaningBillingPolicyRequest $request, CleaningBillingPolicy $cleaning_billing_policy): CleaningBillingPolicyResource
    {
        $updated = $this->cleaningBillingPolicyService->update(
            CleaningBillingPolicyData::from($request->validated()),
            $cleaning_billing_policy
        );

        return CleaningBillingPolicyResource::make($updated);
    }

    public function destroy(CleaningBillingPolicy $cleaning_billing_policy): Response
    {
        $cleaning_billing_policy->delete();

        return response()->noContent();
    }
}
