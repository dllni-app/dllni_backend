<?php

declare(strict_types=1);

namespace App\Filament\Resources\MasterProducts\Pages\Concerns;

use App\Models\MasterProduct;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

trait SyncsMasterProductImage
{
    protected function syncMasterProductImageFromForm(): void
    {
        $image = $this->masterProductImageFromForm();

        if ($image === null) {
            return;
        }

        $this->record->clearMediaCollection(MasterProduct::IMAGE_COLLECTION);

        if ($image instanceof UploadedFile) {
            $this->record->addMedia($image)->toMediaCollection(MasterProduct::IMAGE_COLLECTION);

            return;
        }

        if (is_object($image) && method_exists($image, 'getRealPath')) {
            $realPath = $image->getRealPath();
            if (! is_string($realPath) || $realPath === '') {
                return;
            }

            $mediaAdder = $this->record->addMedia($realPath);
            if (method_exists($image, 'getClientOriginalName')) {
                $name = $image->getClientOriginalName();
                if (is_string($name) && $name !== '') {
                    $mediaAdder->usingFileName($name);
                }
            }

            $mediaAdder->toMediaCollection(MasterProduct::IMAGE_COLLECTION);

            return;
        }

        if (is_string($image) && $image !== '') {
            $this->record->addMediaFromDisk($image, 'public')->toMediaCollection(MasterProduct::IMAGE_COLLECTION);
        }
    }

    private function masterProductImageFromForm(): mixed
    {
        $image = $this->data['image_upload'] ?? null;

        return is_array($image) ? Arr::first($image) : $image;
    }
}
