{{-- resources/views/filament-hub/partials/command-alert-card.blade.php --}}

@once
    <style>
        .ca-card {
            direction: rtl;
            border: 1px solid rgb(229 231 235);
            border-radius: 1rem;
            background: rgb(255 255 255);
            padding: 1rem;
            box-shadow: 0 1px 2px rgb(15 23 42 / .04);
        }

        .dark .ca-card {
            background: rgb(17 24 39);
            border-color: rgb(31 41 55);
        }

        .ca-card-danger {
            border-color: rgb(254 202 202);
            background: rgb(254 242 242);
        }

        .dark .ca-card-danger {
            border-color: rgb(127 29 29);
            background: rgb(69 10 10 / .45);
        }

        .ca-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 1rem;
            align-items: center;
        }

        .ca-title-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: .5rem;
            margin-bottom: .75rem;
        }

        .ca-title {
            margin: 0;
            color: rgb(17 24 39);
            font-size: .98rem;
            font-weight: 800;
            line-height: 1.6;
        }

        .dark .ca-title {
            color: rgb(255 255 255);
        }

        .ca-chip {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            border-radius: 999px;
            background: rgb(243 244 246);
            color: rgb(55 65 81);
            font-size: .75rem;
            font-weight: 800;
            line-height: 1;
            padding: .42rem .65rem;
            white-space: nowrap;
        }

        .dark .ca-chip {
            background: rgb(31 41 55);
            color: rgb(209 213 219);
        }

        .ca-chip-primary {
            background: rgb(239 246 255);
            color: rgb(29 78 216);
        }

        .ca-chip-warning {
            background: rgb(254 243 199);
            color: rgb(180 83 9);
        }

        .ca-chip-danger {
            background: rgb(254 226 226);
            color: rgb(185 28 28);
        }

        .ca-dot {
            width: .45rem;
            height: .45rem;
            border-radius: 999px;
            background: currentColor;
        }

        .ca-meta {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: .6rem;
        }

        .ca-meta-item {
            border: 1px solid rgb(229 231 235);
            border-radius: .8rem;
            background: rgb(249 250 251);
            padding: .7rem;
        }

        .dark .ca-meta-item {
            background: rgb(3 7 18);
            border-color: rgb(31 41 55);
        }

        .ca-meta-label {
            display: block;
            color: rgb(107 114 128);
            font-size: .7rem;
            font-weight: 700;
            margin-bottom: .2rem;
        }

        .ca-meta-value {
            display: block;
            color: rgb(17 24 39);
            font-size: .82rem;
            font-weight: 800;
            line-height: 1.5;
        }

        .dark .ca-meta-value {
            color: rgb(243 244 246);
        }

        .ca-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: .5rem;
            min-width: 9rem;
        }

        .ca-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .4rem;
            min-height: 2.35rem;
            border-radius: .75rem;
            border: 1px solid rgb(209 213 219);
            background: rgb(255 255 255);
            color: rgb(55 65 81);
            cursor: pointer;
            font-size: .82rem;
            font-weight: 800;
            padding: .55rem .8rem;
            text-decoration: none;
        }

        .dark .ca-button {
            background: rgb(17 24 39);
            border-color: rgb(55 65 81);
            color: rgb(229 231 235);
        }

        .ca-button-success {
            border-color: rgb(22 163 74);
            background: rgb(22 163 74);
            color: white;
        }

        .ca-button:hover {
            filter: brightness(.98);
        }

        @media (max-width: 900px) {
            .ca-layout {
                grid-template-columns: 1fr;
            }

            .ca-meta {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .ca-actions {
                justify-content: stretch;
            }

            .ca-button {
                flex: 1 1 10rem;
            }
        }

        @media (max-width: 520px) {
            .ca-meta {
                grid-template-columns: 1fr;
            }
        }
    </style>
@endonce

@php
    $isDanger = $variant === 'danger';
    $alertTypeValue = $alert->alert_type?->value ?? (string) $alert->alert_type;
    $severityValue = $alert->severity?->value ?? (string) $alert->severity;
    $statusValue = $alert->status?->value ?? (string) $alert->status;

    $alertTitle = $alertTypeLabels[$alertTypeValue] ?? __('cleaning_admin.enums.alert_type.' . $alertTypeValue);
    if ($alertTitle === 'cleaning_admin.enums.alert_type.' . $alertTypeValue) {
        $alertTitle = \Illuminate\Support\Str::of($alertTypeValue)->replace('_', ' ')->title()->toString();
    }

    $severityLabel = method_exists($alert->severity, 'label')
        ? $alert->severity->label()
        : __('cleaning_admin.enums.alert_severity.' . $severityValue);
    if ($severityLabel === 'cleaning_admin.enums.alert_severity.' . $severityValue) {
        $severityLabel = \Illuminate\Support\Str::of($severityValue)->replace('_', ' ')->title()->toString();
    }

    $statusLabel = method_exists($alert->status, 'label')
        ? $alert->status->label()
        : __('cleaning_admin.enums.system_alert_status.' . $statusValue);
    if ($statusLabel === 'cleaning_admin.enums.system_alert_status.' . $statusValue) {
        $statusLabel = \Illuminate\Support\Str::of($statusValue)->replace('_', ' ')->title()->toString();
    }

    $bookingReference = $alert->booking
        ? __('cleaning_admin.overview.alerts.booking_ref', ['number' => $alert->booking->booking_number ?? $alert->booking_id])
        : __('cleaning_admin.overview.alerts.no_booking_ref');
@endphp

<div class="ca-card {{ $isDanger ? 'ca-card-danger' : '' }}">
    <div class="ca-layout">
        <div>
            <div class="ca-title-row">
                <span class="ca-chip {{ $isDanger ? 'ca-chip-danger' : 'ca-chip-primary' }}">
                    <span class="ca-dot"></span>
                    {{ $isDanger ? __('cleaning_admin.overview.alerts.urgent') : __('cleaning_admin.overview.alerts.needs_review') }}
                </span>
                <h3 class="ca-title">{{ $alertTitle }}</h3>
            </div>

            <div class="ca-meta">
                <div class="ca-meta-item">
                    <span class="ca-meta-label">{{ __('cleaning_admin.overview.alerts.booking') }}</span>
                    <span class="ca-meta-value">{{ $bookingReference }}</span>
                </div>

                <div class="ca-meta-item">
                    <span class="ca-meta-label">{{ __('cleaning_admin.overview.alerts.severity') }}</span>
                    <span class="ca-meta-value">{{ $severityLabel }}</span>
                </div>

                <div class="ca-meta-item">
                    <span class="ca-meta-label">{{ __('cleaning_admin.overview.alerts.status') }}</span>
                    <span class="ca-meta-value">{{ $statusLabel }}</span>
                </div>

                <div class="ca-meta-item">
                    <span class="ca-meta-label">{{ __('cleaning_admin.overview.alerts.created_at') }}</span>
                    <span class="ca-meta-value">{{ $alert->created_at?->diffForHumans() ?? '-' }}</span>
                </div>
            </div>
        </div>

        <div class="ca-actions">
            @if ($alert->booking && method_exists($alert->booking, 'customer') && $alert->booking->customer?->phone)
                <a href="tel:{{ $alert->booking->customer->phone }}" class="ca-button">
                    <x-filament::icon icon="heroicon-o-phone" style="width: 1rem; height: 1rem;" />
                    {{ __('cleaning_admin.overview.alerts.call_customer') }}
                </a>
            @endif

            @if ($alert->booking && method_exists($alert->booking, 'worker') && filled($alert->booking->worker?->user?->phone))
                <a href="tel:{{ $alert->booking->worker->user->phone }}" class="ca-button">
                    <x-filament::icon icon="heroicon-o-phone-arrow-up-right" style="width: 1rem; height: 1rem;" />
                    {{ __('cleaning_admin.overview.alerts.call_worker') }}
                </a>
            @endif

            @if ($statusValue !== 'resolved')
                <button type="button" wire:click="resolveAlert({{ $alert->id }})" wire:loading.attr="disabled" class="ca-button ca-button-success">
                    <x-filament::icon icon="heroicon-o-check-circle" style="width: 1rem; height: 1rem;" />
                    {{ __('cleaning_admin.overview.alerts.resolve') }}
                </button>
            @endif
        </div>
    </div>
</div>
