<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\HasMedia;

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
                'avatarUrl' => $this->causerAvatarUrl(),
            ] : null,
            'subjectType' => $this->subject_type,
            'subjectId' => $this->subject_id,
            'properties' => $this->properties,
            'createdAt' => $this->created_at?->toDateTimeString(),
        ];
    }

    private function causerAvatarUrl(): ?string
    {
        $causer = $this->causer;

        if ($causer instanceof HasMedia) {
            $mediaUrl = $causer->getFirstMediaUrl('primary-image');

            if ($mediaUrl !== '') {
                return $mediaUrl;
            }
        }

        if ($causer === null || ! method_exists($causer, 'getAttributes')) {
            return null;
        }

        $attributes = $causer->getAttributes();
        $avatarUrl = array_key_exists('avatar_url', $attributes)
            ? $causer->getAttribute('avatar_url')
            : null;

        return is_string($avatarUrl) && trim($avatarUrl) !== ''
            ? $avatarUrl
            : null;
    }
}
