<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;
use App\Models\CancellationPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CancellationPolicyController
{
    public function show(Request $request): JsonResponse
    {
        $module = $request->string('module')->trim()->lower()->toString();

        if ($module === '') {
            return response()->json([
                'message' => 'The module field is required.',
                'errors' => [
                    'module' => ['The module field is required.'],
                ],
            ], 422);
        }

        $policy = CancellationPolicy::query()
            ->where('module', $module)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->first();

        if ($policy === null) {
            return response()->json([
                'message' => 'Cancellation policy not found for the specified module.',
            ], 404);
        }

        return response()->json([
            'data' => [
                'id' => $policy->id,
                'module' => $policy->module,
                'name' => $policy->name,
                'description' => $policy->description,
                'rules' => $policy->rules,
                'isActive' => $policy->is_active,
                'isDefault' => $policy->is_default,
            ],
        ]);
    }
}

