<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\User\Http\Requests\UserCleaningOrdersIndexRequest;
use Modules\User\Http\Resources\UserCleaningBookingResource;

final class UserCleaningOrdersController
{
    public function __invoke(UserCleaningOrdersIndexRequest $request): AnonymousResourceCollection
    {
        $orders = CleaningBooking::getQuery()
            ->where('customer_id', $request->user()->id)
            ->when($request->boolean('recurringOnly'), static function (Builder $query): void {
                // Server-side pagination must not omit a plan simply because
                // earlier pages contain unrelated one-off appointments.
                $query->where(static function (Builder $query): void {
                    $query->whereExists(static function (QueryBuilder $sub): void {
                        $sub->selectRaw('1')
                            ->from('cleaning_quote_bookings as linked')
                            ->join('cleaning_quote_bookings as sibling', 'sibling.quote_id', '=', 'linked.quote_id')
                            ->whereColumn('linked.cleaning_booking_id', 'cleaning_bookings.id')
                            ->whereColumn('sibling.cleaning_booking_id', '!=', 'linked.cleaning_booking_id');
                    })->orWhere(static function (Builder $v3): void {
                        $v3->where('contract_version', 'v3')
                            ->whereExists(static function (QueryBuilder $sub): void {
                                $sub->selectRaw('1')->from('cleaning_booking_sessions')
                                    ->whereColumn('cleaning_booking_sessions.cleaning_booking_id', 'cleaning_bookings.id')
                                    ->where('sequence', '>', 1);
                            });
                    })->orWhere('property_details->schedule->mode', 'recurring');
                });
            })
            ->with([
                'worker.user',
                'preferredWorker.user',
                'rooms',
                'workerAssignments.worker.user',
                'timeWarnings',
                'disputes',
                'addons',
                'billingPolicy',
                'materials.material',
                'materials.materialType',
                'materials.unit',
                'specialServices.specialService',
            ])
            ->paginate((int) $request->validated('perPage', 20));

        return UserCleaningBookingResource::collection($orders);
    }
}
