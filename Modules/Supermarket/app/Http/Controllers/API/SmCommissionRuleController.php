<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Supermarket\Data\SmCommissionRuleData;
use Modules\Supermarket\Http\Requests\SmCommissionRuleRequest;
use Modules\Supermarket\Http\Requests\SmCommissionRuleRequests\SmCommissionRuleFilterRequest;
use Modules\Supermarket\Http\Resources\SmCommissionRuleResource;
use Modules\Supermarket\Models\SmCommissionRule;
use Modules\Supermarket\Services\SmCommissionRuleService;

final class SmCommissionRuleController
{
    public function __construct(
        private SmCommissionRuleService $service
    ) {}

    public function index(SmCommissionRuleFilterRequest $request): AnonymousResourceCollection
    {
        $rules = SmCommissionRule::getQuery()->paginate($request->get('perPage', 20));

        return SmCommissionRuleResource::collection($rules);
    }

    public function store(SmCommissionRuleRequest $request): SmCommissionRuleResource
    {
        $rule = $this->service->store(SmCommissionRuleData::from($request->validated()));

        return SmCommissionRuleResource::make($rule->load('store'));
    }

    public function show(SmCommissionRule $smCommissionRule): SmCommissionRuleResource
    {
        return SmCommissionRuleResource::make($smCommissionRule->load('store'));
    }

    public function update(SmCommissionRuleRequest $request, SmCommissionRule $smCommissionRule): SmCommissionRuleResource
    {
        $rule = $this->service->update(SmCommissionRuleData::from($request->validated()), $smCommissionRule);

        return SmCommissionRuleResource::make($rule->load('store'));
    }

    public function destroy(SmCommissionRule $smCommissionRule): Response
    {
        $smCommissionRule->delete();

        return response()->noContent();
    }
}
