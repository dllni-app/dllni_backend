<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'event' => $this->event,
            'logName' => $this->log_name,
            'causer' => $this->causer ? [
                'id' => $this->causer->id,
                'name' => $this->causer->name,
                'avatarUrl' => $this->causer->avatar_url ?? null,
            ] : null,
            'subjectType' => $this->subject_type,
            'subjectId' => $this->subject_id,
            'properties' => $this->properties,
            'createdAt' => $this->created_at?->toDateTimeString(),
        ];
    }
}
