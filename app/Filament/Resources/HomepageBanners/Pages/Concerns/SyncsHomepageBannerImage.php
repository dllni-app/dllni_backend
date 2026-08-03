<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageBanners\Pages\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Modules\User\Models\MarketingOffer;

trait SyncsHomepageBannerImage
{
    protected function syncHomepageBannerImageFromForm(): void
    {
        $image = $this->homepageBannerImageFromForm();

        if ($image === null) {
            return;
        }

        if ($image instanceof UploadedFile) {
            $this->record
                ->addMedia($image)
                ->toMediaCollection(MarketingOffer::IMAGE_COLLECTION);

            return;
        }

        if (is_object($image) && method_exists($image, 'getRealPath')) {
            $realPath = $image->getRealPath();

            if (! is_string($realPath) || $realPath === '') {
                return;
            }

            $mediaAdder = $this->record->addMedia($realPath);

            if (method_exists($image, 'getClientOriginalName')) {
                $originalName = $image->getClientOriginalName();

                if (is_string($originalName) && $originalName !== '') {
                    $mediaAdder->usingFileName($originalName);
                }
            }

            $mediaAdder->toMediaCollection(MarketingOffer::IMAGE_COLLECTION);

            return;
        }

        if (is_string($image) && $image !== '') {
            $this->record
                ->addMediaFromDisk($image, 'public')
                ->toMediaCollection(MarketingOffer::IMAGE_COLLECTION);
        }
    }

    private function homepageBannerImageFromForm(): mixed
    {
        $image = $this->data['image_upload'] ?? null;

        if (is_array($image)) {
            return Arr::first($image);
        }

        return $image;
    }
}
