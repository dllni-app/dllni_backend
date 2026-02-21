<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Supermarket\Data\SmOrderDisputeMessageData;
use Modules\Supermarket\Http\Requests\SmOrderDisputeMessageRequest;
use Modules\Supermarket\Http\Requests\SmOrderDisputeMessageRequests\SmOrderDisputeMessageFilterRequest;
use Modules\Supermarket\Http\Resources\SmOrderDisputeMessageResource;
use Modules\Supermarket\Models\SmOrderDisputeMessage;
use Modules\Supermarket\Services\SmOrderDisputeMessageService;

final class SmOrderDisputeMessageController
{
    public function __construct(
        private SmOrderDisputeMessageService $service
    ) {}

    public function index(SmOrderDisputeMessageFilterRequest $request): AnonymousResourceCollection
    {
        $messages = SmOrderDisputeMessage::getQuery()->paginate($request->get('perPage', 20));

        return SmOrderDisputeMessageResource::collection($messages);
    }

    public function store(SmOrderDisputeMessageRequest $request): SmOrderDisputeMessageResource
    {
        $message = $this->service->store(SmOrderDisputeMessageData::from($request->validated()));

        return SmOrderDisputeMessageResource::make($message->load(['dispute', 'user']));
    }

    public function show(SmOrderDisputeMessage $smOrderDisputeMessage): SmOrderDisputeMessageResource
    {
        return SmOrderDisputeMessageResource::make($smOrderDisputeMessage->load(['dispute', 'user']));
    }

    public function update(SmOrderDisputeMessageRequest $request, SmOrderDisputeMessage $smOrderDisputeMessage): SmOrderDisputeMessageResource
    {
        $message = $this->service->update(SmOrderDisputeMessageData::from($request->validated()), $smOrderDisputeMessage);

        return SmOrderDisputeMessageResource::make($message->load(['dispute', 'user']));
    }

    public function destroy(SmOrderDisputeMessage $smOrderDisputeMessage): Response
    {
        $smOrderDisputeMessage->delete();

        return response()->noContent();
    }
}
