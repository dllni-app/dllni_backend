<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningCustomerIncident;

final class CleaningCustomerIncidentService
{
    public const CATEGORIES = [
        'mistreatment' => 'سوء التعامل من قبل العميل',
        'scope_violation' => 'عدم الالتزام بالأعمال المتفق عليها',
        'payment_problem' => 'مشكلة في تسديد المبلغ المستحق',
        'unsafe_environment' => 'بيئة عمل غير آمنة',
        'harassment_threat' => 'التحرش أو التهديد',
    ];

    public static function validate(Request $request): ?array
    {
        if (! $request->has('customerIncident')) {
            return null;
        }

        $data = $request->validate([
            'customerIncident' => ['required', 'array:categories,description'],
            'customerIncident.categories' => ['required', 'array', 'min:1', 'max:5'],
            'customerIncident.categories.*' => ['required', 'string', 'distinct', Rule::in(array_keys(self::CATEGORIES))],
            'customerIncident.description' => ['required', 'string', 'max:2000'],
        ], [
            'customerIncident.categories.required' => 'اختر مشكلة واحدة على الأقل.',
            'customerIncident.description.required' => 'يرجى شرح المشكلة.',
            'customerIncident.description.max' => 'يجب ألا يتجاوز الشرح 2000 حرف.',
        ])['customerIncident'];

        $data['description'] = trim($data['description']);
        if ($data['description'] === '') {
            throw ValidationException::withMessages(['customerIncident.description' => 'يرجى شرح المشكلة.']);
        }

        return $data;
    }

    // Invoke only inside the authenticated completion transaction, after its
    // existing assignment and lifecycle checks. Never include this in API resources.
    public static function record(CleaningBooking $booking, int $visitId, int $reporterId, string $role, ?array $report): void
    {
        if ($report === null) {
            return;
        }

        CleaningCustomerIncident::query()->firstOrCreate([
            'booking_id' => $booking->id,
            'visit_key' => $visitId,
        ], [
            'customer_id' => $booking->customer_id,
            'reporter_user_id' => $reporterId,
            'reporter_role' => $role,
            'categories' => $report['categories'],
            'description' => $report['description'],
        ]);
    }
}
