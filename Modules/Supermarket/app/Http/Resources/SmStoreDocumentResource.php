<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SmStoreDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'storeId' => $this->store_id,
            'documentType' => $this->document_type?->value,
            'filePath' => $this->file_path,
            'verificationStatus' => $this->verification_status,
            'rejectionReason' => $this->rejection_reason,
            'verifiedByUserId' => $this->verified_by_user_id,
            'verifiedByUser' => UserResource::make($this->whenLoaded('verifiedByUser')),
            'verifiedAt' => $this->verified_at?->toDateTimeString(),
            'expiresAt' => $this->expires_at?->toDateTimeString(),
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
