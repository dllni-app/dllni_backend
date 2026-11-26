<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningBookingTemplate;
use Modules\Cleaning\Models\CleaningHomeType;
use Modules\Cleaning\Models\CleaningV3CatalogItem;
use Modules\Cleaning\Services\SupervisedTeamService;
use Modules\User\Models\UserAddress;

final class CleaningBookingTemplateController
{
    private const HEADERS = ['Cache-Control' => 'private, no-store'];

    public function index(Request $request): JsonResponse
    {
        $items = $this->owned($request)->orderByDesc('updated_at')->orderByDesc('id')->get();
        return response()->json(['data' => $items->map(fn ($item) => $this->summary($item))], 200, self::HEADERS);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->detail($this->owned($request)->findOrFail($id))], 200, self::HEADERS);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $hash = hash('sha256', json_encode($this->canonical([$data['name'], $data['inputs']]), JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($request, $data, $hash): JsonResponse {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $existing = $this->owned($request)->where('creation_key', $data['creationKey'])->first();
            if ($existing !== null) {
                if (! hash_equals($existing->creation_hash, $hash)) {
                    return $this->conflict('مفتاح الحفظ مستخدم لبيانات مختلفة.');
                }
                return response()->json(['data' => $this->detail($existing)], 200, self::HEADERS);
            }
            $this->assertAddressOwner($request, $data['inputs']);
            $item = CleaningBookingTemplate::query()->create([
                'customer_id' => $request->user()->id,
                'name' => $data['name'],
                'schema_version' => 1,
                'inputs' => $data['inputs'],
                'revision' => 1,
                'creation_key' => $data['creationKey'],
                'creation_hash' => $hash,
            ]);
            return response()->json(['data' => $this->detail($item)], 201, self::HEADERS);
        });
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id): JsonResponse {
            $item = $this->owned($request)->lockForUpdate()->findOrFail($id);
            $data = $this->validated($request, false);
            if ($item->revision !== (int) $data['revision']) {
                return $this->conflict('تم تعديل النموذج من جهاز آخر. أعد تحميله قبل الحفظ.');
            }
            if (isset($data['inputs'])) {
                if (($data['inputs']['addressId'] ?? null) !== ($item->inputs['addressId'] ?? null)) {
                    $this->assertAddressOwner($request, $data['inputs']);
                }
                $item->inputs = $data['inputs'];
            }
            if (isset($data['name'])) {
                $item->name = $data['name'];
            }
            $item->revision++;
            $item->save();
            return response()->json(['data' => $this->detail($item)], 200, self::HEADERS);
        });
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id): JsonResponse {
            $item = $this->owned($request)->lockForUpdate()->findOrFail($id);
            $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
            if ($item->revision !== (int) $data['revision']) {
                return $this->conflict('تم تعديل النموذج من جهاز آخر. أعد تحميله قبل الحذف.');
            }
            $item->delete();
            return response()->json(['data' => null], 200, self::HEADERS);
        });
    }

    private function owned(Request $request)
    {
        $user = $request->user();
        abort_unless($user->is_active && $user->module_type === null
            && ! $user->hasRole(SupervisedTeamService::ROLE), 403);
        return CleaningBookingTemplate::query()->where('customer_id', $user->id);
    }

    private function summary(CleaningBookingTemplate $item): array
    {
        $key = $item->inputs['propertyKey'];
        $property = null;
        if ($item->inputs['source'] === 'v2') {
            $matches = CleaningHomeType::withTrashed()->where('section', 'property')
                ->where('booking_value', $key)->get();
            if ($matches->count() === 1) $property = $matches->first();
        } else {
            // Never infer equivalence from names or a reused catalog key.
            $ids = CleaningV3CatalogItem::query()->where('item_key', $key)
                ->where('source_type', 'property')
                ->whereHas('version', fn ($q) => $q->whereIn('status', ['published', 'retired']))
                ->distinct()->pluck('source_id');
            if ($ids->count() === 1) {
                $property = CleaningHomeType::withTrashed()->where('section', 'property')->find($ids->first());
            }
        }
        return [
            'propertySourceId' => $property?->id,
            'propertyBookingValue' => $property?->booking_value,
            'propertyLabel' => $property?->title,
            'id' => $item->id, 'name' => $item->name, 'revision' => $item->revision,
            'schemaVersion' => $item->schema_version,
            'propertyKey' => $item->inputs['propertyKey'],
            'roomCount' => isset($item->inputs['rooms']) ? count($item->inputs['rooms']) : null,
            'updatedAt' => $item->updated_at?->toISOString(),
        ];
    }

    private function detail(CleaningBookingTemplate $item): array
    {
        return [...$this->summary($item), 'inputs' => $item->inputs];
    }

    private function conflict(string $message): JsonResponse
    {
        return response()->json(['code' => 'TEMPLATE_REVISION_CONFLICT', 'message' => $message], 409, self::HEADERS);
    }

    private function assertAddressOwner(Request $request, array $inputs): void
    {
        if (isset($inputs['addressId']) && ! UserAddress::query()->where('user_id', $request->user()->id)
            ->whereKey($inputs['addressId'])->exists()) {
            throw ValidationException::withMessages(['inputs.addressId' => 'العنوان المحدد غير متاح في حسابك.']);
        }
    }

    private function canonical(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }
        return $data;
    }

    private function validated(Request $request, bool $creating): array
    {
        $allowed = $creating ? ['name', 'inputs', 'creationKey'] : ['name', 'inputs', 'revision'];
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages(['inputs' => 'بيانات غير مدعومة في نموذج التنظيف.']);
        }
        $rules = [
            'name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:100'],
            'inputs' => [$creating ? 'required' : 'sometimes', 'required', 'array:source,propertyKey,rooms,answers,fieldSignatures,cleaningMode,deepScopes,materialChoice,missingMaterialIds,services,workerCount,genderPreference,workerPreferenceMode,workerIds,addressId,addressRevision,notes,recurring,recurrencePattern,recurrenceWeekdays,recurrenceIntervalWeeks,occurrences,visitPreferences'],
            'inputs.source' => ['required_with:inputs', Rule::in(['v2', 'v3'])],
            'inputs.propertyKey' => ['required_with:inputs', 'string', 'max:120'],
            'inputs.rooms' => ['sometimes', 'array', 'max:50'],
            'inputs.rooms.*' => ['array:key,type,index,size,cleaningType,manualHours'],
            'inputs.rooms.*.key' => ['required', 'string', 'max:160', 'distinct'],
            'inputs.rooms.*.type' => ['required', 'string', 'max:120'],
            'inputs.rooms.*.index' => ['required', 'integer', 'min:1', 'max:50'],
            'inputs.rooms.*.size' => ['sometimes', Rule::in(['small', 'medium', 'large'])],
            'inputs.rooms.*.cleaningType' => ['sometimes', Rule::in(['regular_cleaning', 'deep_cleaning'])],
            'inputs.rooms.*.manualHours' => ['nullable', 'numeric', 'min:0.25', 'max:24'],
            'inputs.answers' => ['sometimes', 'array', 'max:200'],
            'inputs.fieldSignatures' => ['sometimes', 'array', 'max:200'],
            'inputs.fieldSignatures.*' => ['string', 'max:1000'],
            'inputs.cleaningMode' => ['sometimes', Rule::in(['regular', 'deep'])],
            'inputs.deepScopes' => ['sometimes', 'array', 'max:200'],
            'inputs.deepScopes.*' => ['array', 'max:50'],
            'inputs.deepScopes.*.*' => ['string', 'max:120'],
            'inputs.materialChoice' => ['sometimes', Rule::in(['customer_all', 'customer_partial', 'platform_all'])],
            'inputs.missingMaterialIds' => ['sometimes', 'array', 'max:100'],
            'inputs.missingMaterialIds.*' => ['integer', 'min:1', 'distinct'],
            'inputs.services' => ['sometimes', 'array', 'max:100'],
            'inputs.services.*' => ['array:serviceKey,specialServiceId,targetRoomKeys,quantity,dirtinessLevel'],
            'inputs.services.*.serviceKey' => ['required', 'string', 'max:160'],
            'inputs.services.*.specialServiceId' => ['required', 'integer', 'min:1'],
            'inputs.services.*.targetRoomKeys' => ['required', 'array', 'max:50'],
            'inputs.services.*.targetRoomKeys.*' => ['string', 'max:160'],
            'inputs.services.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'inputs.services.*.dirtinessLevel' => ['nullable', 'string', 'max:120'],
            'inputs.workerCount' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'inputs.genderPreference' => ['sometimes', Rule::in(['any', 'male', 'female'])],
            'inputs.workerPreferenceMode' => ['sometimes', Rule::in(['prefer', 'exclude', 'none'])],
            'inputs.workerIds' => ['sometimes', 'array', 'max:100'],
            'inputs.workerIds.*' => ['integer', 'min:1', 'distinct'],
            'inputs.addressId' => ['sometimes', 'integer', 'min:1'],
            'inputs.addressRevision' => ['sometimes', 'string', 'max:128'],
            'inputs.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'inputs.recurring' => ['sometimes', 'boolean'],
            'inputs.recurrencePattern' => ['sometimes', 'string', 'max:80'],
            'inputs.recurrenceWeekdays' => ['sometimes', 'array', 'max:7'],
            'inputs.recurrenceWeekdays.*' => ['integer', 'min:1', 'max:7', 'distinct'],
            'inputs.recurrenceIntervalWeeks' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'inputs.occurrences' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'inputs.visitPreferences' => ['sometimes', 'array', 'max:100'],
            'inputs.visitPreferences.*' => ['array:index,durationMinutes,workerCount'],
            'inputs.visitPreferences.*.index' => ['required', 'integer', 'min:1', 'max:100', 'distinct'],
            'inputs.visitPreferences.*.durationMinutes' => ['sometimes', 'integer', 'min:1', 'max:4320'],
            'inputs.visitPreferences.*.workerCount' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
        $rules[$creating ? 'creationKey' : 'revision'] = $creating ? ['required', 'uuid'] : ['required', 'integer', 'min:1'];
        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($validator) use ($request, $creating): void {
            $inputs = $request->input('inputs');
            if (! $creating && ! $request->hasAny(['name', 'inputs'])) {
                $validator->errors()->add('inputs', 'أدخل البيانات المراد تعديلها.');
            }
            if (! is_array($inputs)) return;
            $property = $inputs['propertyKey'] ?? null;
            if (is_string($property)) {
                $knownOccasion = \Modules\Cleaning\Models\CleaningHomeType::withTrashed()
                    ->where('section', 'occasion')
                    ->where(fn ($q) => $q->where('booking_value', $property)->orWhere('code', $property))
                    ->exists();
                $knownNonProperty = \Modules\Cleaning\Models\CleaningV3CatalogItem::query()
                    ->where('item_key', $property)->where('source_type', '!=', 'property')->exists()
                    && ! \Modules\Cleaning\Models\CleaningV3CatalogItem::query()
                        ->where('item_key', $property)->where('source_type', 'property')->exists();
                if ($knownOccasion || $knownNonProperty || in_array($property, ['event_assistance', 'hourly_worker', 'hourly', 'event'], true)) {
                    $validator->errors()->add('inputs.propertyKey', 'Cleaning templates support properties only.');
                }
            }
            if (strlen(json_encode($inputs, JSON_INVALID_UTF8_SUBSTITUTE) ?: '') > 65536) {
                $validator->errors()->add('inputs', 'حجم النموذج أكبر من الحد المسموح.');
            }
            foreach ((is_array($inputs['answers'] ?? null) ? $inputs['answers'] : []) as $key => $value) {
                if (! is_string($key) || strlen($key) > 160 || ! (is_bool($value) || is_int($value)
                    || is_float($value) || (is_string($value) && mb_strlen($value) <= 1000)
                    || (is_array($value) && array_is_list($value) && count($value) <= 100
                        && count(array_filter($value, fn ($v) => is_string($v) && mb_strlen($v) <= 120)) === count($value)))) {
                    $validator->errors()->add('inputs.answers', 'إجابة ديناميكية غير مدعومة.');
                    break;
                }
            }
        });
        return $validator->validate();
    }
}
