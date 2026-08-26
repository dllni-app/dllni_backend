@php
    $widgetId = $trackingConfig ? 'cleaning-booking-tracking-'.$trackingConfig['bookingId'] : 'cleaning-booking-tracking-empty';
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">تتبع فريق التنظيف</x-slot>
        <x-slot name="description">الموقع الحالي وحالة كل عامل في الطلب، باستخدام نفس بيانات التتبع المحفوظة لتطبيقات العميل والعامل.</x-slot>

        @if (! $trackingConfig)
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                لا يمكن تحميل بيانات التتبع لهذا الحجز.
            </div>
        @else
            <div id="{{ $widgetId }}" class="cleaning-booking-tracking" dir="rtl">
                <div class="cleaning-booking-tracking__summary">
                    <div class="cleaning-booking-tracking__summary-card">
                        <span class="cleaning-booking-tracking__summary-label">رقم الحجز</span>
                        <strong data-role="booking-number">{{ $trackingConfig['bookingNumber'] }}</strong>
                    </div>
                    <div class="cleaning-booking-tracking__summary-card">
                        <span class="cleaning-booking-tracking__summary-label">حالة الطلب</span>
                        <strong data-role="booking-status">{{ $trackingConfig['bookingStatusLabel'] }}</strong>
                    </div>
                    <div class="cleaning-booking-tracking__summary-card">
                        <span class="cleaning-booking-tracking__summary-label">العاملون المقبولون</span>
                        <strong data-role="accepted-workers">{{ $trackingConfig['acceptedWorkers'] }} / {{ $trackingConfig['requiredWorkers'] }}</strong>
                    </div>
                    <div class="cleaning-booking-tracking__summary-card">
                        <span class="cleaning-booking-tracking__summary-label">العاملون المتبقون</span>
                        <strong data-role="remaining-workers">{{ $trackingConfig['remainingWorkers'] }}</strong>
                    </div>
                </div>

                <div class="cleaning-booking-tracking__grid">
                    <div class="cleaning-booking-tracking__map-card">
                        <div class="cleaning-booking-tracking__map-header">
                            <div>
                                <div class="cleaning-booking-tracking__title">الخريطة المباشرة</div>
                                <div class="cleaning-booking-tracking__muted" data-role="map-subtitle">يعرض آخر موقع محفوظ للعاملين ومسارات المتجهين إلى العميل.</div>
                            </div>
                            <div class="cleaning-booking-tracking__live-indicator">
                                <span></span>
                                تحديث تلقائي
                            </div>
                        </div>

                        <div class="cleaning-booking-tracking__map-shell">
                            <div data-role="map" wire:ignore class="cleaning-booking-tracking__map"></div>
                            <div data-role="map-empty" class="cleaning-booking-tracking__map-empty" hidden>
                                <x-filament::icon icon="heroicon-o-map-pin" class="h-8 w-8" />
                                <strong>الخريطة غير متاحة حالياً</strong>
                                <span data-role="map-empty-message">لا توجد إحداثيات كافية لعرض التتبع.</span>
                            </div>
                            <div data-role="map-loading" class="cleaning-booking-tracking__map-loading">
                                <x-filament::loading-indicator class="h-7 w-7" />
                                <span>تحميل الخريطة...</span>
                            </div>
                        </div>
                    </div>

                    <div class="cleaning-booking-tracking__workers-card">
                        <div class="cleaning-booking-tracking__workers-header">
                            <div>
                                <div class="cleaning-booking-tracking__title">حالة العاملين</div>
                                <div class="cleaning-booking-tracking__muted" data-role="last-refresh">يتم جلب أحدث البيانات...</div>
                            </div>
                            <button type="button" data-role="refresh" class="cleaning-booking-tracking__refresh-button">
                                <x-filament::icon icon="heroicon-o-arrow-path" class="h-4 w-4" />
                                <span>تحديث الآن</span>
                            </button>
                        </div>

                        <div data-role="workers" class="cleaning-booking-tracking__workers-list">
                            <div class="cleaning-booking-tracking__empty-workers">
                                <x-filament::loading-indicator class="h-6 w-6" />
                                <span>تحميل العاملين...</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div data-role="error" class="cleaning-booking-tracking__error" hidden></div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

@once
    <style>
        .cleaning-booking-tracking {
            display: grid;
            gap: 1rem;
        }

        .cleaning-booking-tracking__summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: .75rem;
        }

        .cleaning-booking-tracking__summary-card,
        .cleaning-booking-tracking__map-card,
        .cleaning-booking-tracking__workers-card {
            border: 1px solid rgb(229 231 235);
            background: rgb(255 255 255);
            border-radius: .9rem;
        }

        .dark .cleaning-booking-tracking__summary-card,
        .dark .cleaning-booking-tracking__map-card,
        .dark .cleaning-booking-tracking__workers-card {
            border-color: rgb(55 65 81);
            background: rgb(17 24 39 / .55);
        }

        .cleaning-booking-tracking__summary-card {
            display: grid;
            gap: .25rem;
            padding: .85rem 1rem;
            min-width: 0;
        }

        .cleaning-booking-tracking__summary-card strong {
            color: rgb(17 24 39);
            font-size: .95rem;
            overflow-wrap: anywhere;
        }

        .dark .cleaning-booking-tracking__summary-card strong {
            color: rgb(249 250 251);
        }

        .cleaning-booking-tracking__summary-label,
        .cleaning-booking-tracking__muted {
            color: rgb(107 114 128);
            font-size: .75rem;
        }

        .dark .cleaning-booking-tracking__summary-label,
        .dark .cleaning-booking-tracking__muted {
            color: rgb(156 163 175);
        }

        .cleaning-booking-tracking__grid {
            display: grid;
            grid-template-columns: minmax(0, 1.45fr) minmax(320px, .85fr);
            gap: 1rem;
            align-items: stretch;
        }

        .cleaning-booking-tracking__map-card,
        .cleaning-booking-tracking__workers-card {
            padding: 1rem;
            min-width: 0;
        }

        .cleaning-booking-tracking__map-header,
        .cleaning-booking-tracking__workers-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: .75rem;
            margin-bottom: .8rem;
        }

        .cleaning-booking-tracking__title {
            color: rgb(17 24 39);
            font-size: .9rem;
            font-weight: 700;
        }

        .dark .cleaning-booking-tracking__title {
            color: rgb(249 250 251);
        }

        .cleaning-booking-tracking__live-indicator {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            flex: 0 0 auto;
            border-radius: 999px;
            background: rgb(236 253 245);
            color: rgb(4 120 87);
            padding: .35rem .6rem;
            font-size: .7rem;
            font-weight: 700;
        }

        .dark .cleaning-booking-tracking__live-indicator {
            background: rgb(16 185 129 / .12);
            color: rgb(110 231 183);
        }

        .cleaning-booking-tracking__live-indicator span {
            width: .45rem;
            height: .45rem;
            border-radius: 999px;
            background: currentColor;
            box-shadow: 0 0 0 3px rgb(16 185 129 / .12);
        }

        .cleaning-booking-tracking__map-shell {
            position: relative;
            overflow: hidden;
            min-height: 360px;
            border-radius: .8rem;
            background: rgb(243 244 246);
        }

        .dark .cleaning-booking-tracking__map-shell {
            background: rgb(31 41 55);
        }

        .cleaning-booking-tracking__map {
            width: 100%;
            height: 360px;
            z-index: 1;
        }

        .cleaning-booking-tracking__map-empty,
        .cleaning-booking-tracking__map-loading {
            position: absolute;
            inset: 0;
            z-index: 3;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: .5rem;
            padding: 1.5rem;
            text-align: center;
            color: rgb(107 114 128);
            background: rgb(249 250 251 / .96);
        }

        .dark .cleaning-booking-tracking__map-empty,
        .dark .cleaning-booking-tracking__map-loading {
            color: rgb(156 163 175);
            background: rgb(17 24 39 / .96);
        }

        .cleaning-booking-tracking__map-empty[hidden],
        .cleaning-booking-tracking__map-loading[hidden] {
            display: none;
        }

        .cleaning-booking-tracking__workers-card {
            display: flex;
            flex-direction: column;
        }

        .cleaning-booking-tracking__refresh-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .35rem;
            min-height: 2rem;
            border: 1px solid rgb(209 213 219);
            border-radius: .55rem;
            padding: .35rem .55rem;
            color: rgb(55 65 81);
            background: rgb(255 255 255);
            font-size: .72rem;
            font-weight: 700;
            cursor: pointer;
        }

        .dark .cleaning-booking-tracking__refresh-button {
            border-color: rgb(75 85 99);
            color: rgb(229 231 235);
            background: rgb(31 41 55);
        }

        .cleaning-booking-tracking__refresh-button:disabled {
            opacity: .55;
            cursor: wait;
        }

        .cleaning-booking-tracking__workers-list {
            display: grid;
            gap: .65rem;
            max-height: 360px;
            overflow: auto;
            padding-inline-end: .15rem;
        }

        .cleaning-booking-tracking__worker {
            display: grid;
            gap: .65rem;
            border: 1px solid rgb(229 231 235);
            border-radius: .75rem;
            padding: .8rem;
            background: rgb(249 250 251);
        }

        .dark .cleaning-booking-tracking__worker {
            border-color: rgb(55 65 81);
            background: rgb(31 41 55 / .7);
        }

        .cleaning-booking-tracking__worker-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: .75rem;
        }

        .cleaning-booking-tracking__worker-name {
            color: rgb(17 24 39);
            font-size: .85rem;
            font-weight: 800;
            overflow-wrap: anywhere;
        }

        .dark .cleaning-booking-tracking__worker-name {
            color: rgb(249 250 251);
        }

        .cleaning-booking-tracking__worker-phone,
        .cleaning-booking-tracking__worker-meta {
            color: rgb(107 114 128);
            font-size: .72rem;
        }

        .dark .cleaning-booking-tracking__worker-phone,
        .dark .cleaning-booking-tracking__worker-meta {
            color: rgb(156 163 175);
        }

        .cleaning-booking-tracking__badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: .28rem .55rem;
            font-size: .68rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .cleaning-booking-tracking__badge[data-tone="success"] { background: rgb(220 252 231); color: rgb(21 128 61); }
        .cleaning-booking-tracking__badge[data-tone="danger"] { background: rgb(254 226 226); color: rgb(185 28 28); }
        .cleaning-booking-tracking__badge[data-tone="warning"] { background: rgb(254 243 199); color: rgb(180 83 9); }
        .cleaning-booking-tracking__badge[data-tone="primary"] { background: rgb(224 231 255); color: rgb(67 56 202); }
        .cleaning-booking-tracking__badge[data-tone="info"] { background: rgb(224 242 254); color: rgb(3 105 161); }
        .cleaning-booking-tracking__badge[data-tone="gray"] { background: rgb(243 244 246); color: rgb(75 85 99); }

        .dark .cleaning-booking-tracking__badge[data-tone="success"] { background: rgb(34 197 94 / .14); color: rgb(134 239 172); }
        .dark .cleaning-booking-tracking__badge[data-tone="danger"] { background: rgb(239 68 68 / .14); color: rgb(252 165 165); }
        .dark .cleaning-booking-tracking__badge[data-tone="warning"] { background: rgb(245 158 11 / .14); color: rgb(253 230 138); }
        .dark .cleaning-booking-tracking__badge[data-tone="primary"] { background: rgb(99 102 241 / .14); color: rgb(165 180 252); }
        .dark .cleaning-booking-tracking__badge[data-tone="info"] { background: rgb(14 165 233 / .14); color: rgb(125 211 252); }
        .dark .cleaning-booking-tracking__badge[data-tone="gray"] { background: rgb(75 85 99 / .35); color: rgb(209 213 219); }

        .cleaning-booking-tracking__worker-location {
            display: flex;
            align-items: center;
            gap: .45rem;
            border-radius: .55rem;
            background: rgb(255 255 255);
            padding: .5rem .6rem;
            color: rgb(55 65 81);
            font-size: .72rem;
        }

        .dark .cleaning-booking-tracking__worker-location {
            background: rgb(17 24 39 / .7);
            color: rgb(209 213 219);
        }

        .cleaning-booking-tracking__empty-workers {
            min-height: 10rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: .55rem;
            color: rgb(107 114 128);
            font-size: .78rem;
            text-align: center;
        }

        .cleaning-booking-tracking__error {
            border: 1px solid rgb(254 202 202);
            background: rgb(254 242 242);
            color: rgb(185 28 28);
            border-radius: .7rem;
            padding: .7rem .85rem;
            font-size: .75rem;
        }

        .dark .cleaning-booking-tracking__error {
            border-color: rgb(127 29 29);
            background: rgb(127 29 29 / .18);
            color: rgb(252 165 165);
        }

        .cleaning-tracking-marker {
            display: grid;
            place-items: center;
            width: 34px;
            height: 34px;
            border: 3px solid white;
            border-radius: 999px;
            box-shadow: 0 4px 12px rgb(0 0 0 / .25);
            font-size: 13px;
            font-weight: 900;
        }

        .cleaning-tracking-marker--destination {
            background: #1e3a8a;
            color: white;
        }

        .cleaning-tracking-marker--worker {
            background: #0891b2;
            color: white;
        }

        @media (max-width: 1100px) {
            .cleaning-booking-tracking__summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .cleaning-booking-tracking__grid {
                grid-template-columns: minmax(0, 1fr);
            }

            .cleaning-booking-tracking__workers-list {
                max-height: none;
            }
        }

        @media (max-width: 640px) {
            .cleaning-booking-tracking__summary {
                grid-template-columns: minmax(0, 1fr);
            }

            .cleaning-booking-tracking__map-header,
            .cleaning-booking-tracking__workers-header,
            .cleaning-booking-tracking__worker-head {
                align-items: stretch;
                flex-direction: column;
            }

            .cleaning-booking-tracking__refresh-button {
                width: 100%;
            }

            .cleaning-booking-tracking__map,
            .cleaning-booking-tracking__map-shell {
                height: 300px;
                min-height: 300px;
            }
        }
    </style>
@endonce

@if ($trackingConfig)
    @script
    <script>
        (() => {
            const root = document.getElementById(@js($widgetId));
            if (!root) return;

            const config = @js($trackingConfig);
            const RouteColors = ['#f59e0b', '#2563eb', '#16a34a', '#dc2626', '#7c3aed', '#0891b2'];

            const loadLeaflet = () => {
                if (window.L?.map) return Promise.resolve(window.L);
                if (window.__dllniLeafletPromise) return window.__dllniLeafletPromise;

                window.__dllniLeafletPromise = new Promise((resolve, reject) => {
                    if (!document.querySelector('link[data-dllni-leaflet]')) {
                        const link = document.createElement('link');
                        link.rel = 'stylesheet';
                        link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                        link.integrity = 'sha256-p4NxAoJBhIINfQ3ynHTYKnqi2SM2g8h3E5A6bbVYUQ4=';
                        link.crossOrigin = '';
                        link.dataset.dllniLeaflet = '1';
                        document.head.appendChild(link);
                    }

                    const existing = document.querySelector('script[data-dllni-leaflet]');
                    if (existing) {
                        existing.addEventListener('load', () => resolve(window.L), { once: true });
                        existing.addEventListener('error', reject, { once: true });
                        return;
                    }

                    const script = document.createElement('script');
                    script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                    script.integrity = 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=';
                    script.crossOrigin = '';
                    script.dataset.dllniLeaflet = '1';
                    script.onload = () => resolve(window.L);
                    script.onerror = reject;
                    document.head.appendChild(script);
                });

                return window.__dllniLeafletPromise;
            };

            const createText = (tag, className, text) => {
                const node = document.createElement(tag);
                if (className) node.className = className;
                node.textContent = text ?? '';
                return node;
            };

            const app = {
                root,
                config,
                map: null,
                destinationMarker: null,
                workerLayerGroup: null,
                routeLayerGroup: null,
                resizeObserver: null,
                pollTimer: null,
                routeAbortController: null,
                routeRevision: 0,
                workers: [],
                destination: config.destination,
                bookingStatus: config.bookingStatus,
                destroyed: false,
                refreshing: false,

                async init() {
                    root.__cleaningTracking?.destroy?.();
                    root.__cleaningTracking = this;
                    root.querySelector('[data-role="refresh"]')?.addEventListener('click', () => this.refresh());

                    await this.initializeMap();
                    await this.refresh();

                    this.pollTimer = window.setInterval(() => {
                        if (!this.root.isConnected) {
                            this.destroy();
                            return;
                        }
                        if (!this.isTerminal()) this.refresh();
                    }, Number(config.pollIntervalMs) || 15000);
                },

                async initializeMap() {
                    const mapElement = root.querySelector('[data-role="map"]');
                    const loadingElement = root.querySelector('[data-role="map-loading"]');

                    if (!this.validDestination()) {
                        this.showMapEmpty('لا توجد إحداثيات موقع العميل لهذا الطلب.');
                        if (loadingElement) loadingElement.hidden = true;
                        return;
                    }

                    try {
                        const L = await loadLeaflet();
                        if (this.destroyed || !mapElement) return;

                        const destination = [Number(this.destination.latitude), Number(this.destination.longitude)];
                        this.map = L.map(mapElement, { scrollWheelZoom: false }).setView(destination, 15);

                        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                            maxZoom: 19,
                        }).addTo(this.map);

                        const destinationIcon = L.divIcon({
                            className: '',
                            html: '<div class="cleaning-tracking-marker cleaning-tracking-marker--destination">⌂</div>',
                            iconSize: [34, 34],
                            iconAnchor: [17, 17],
                        });
                        this.destinationMarker = L.marker(destination, {
                            title: this.destination.name || 'موقع العميل',
                            icon: destinationIcon,
                        }).addTo(this.map);
                        this.destinationMarker.bindPopup(this.destination.name || 'موقع العميل');

                        this.workerLayerGroup = L.layerGroup().addTo(this.map);
                        this.routeLayerGroup = L.layerGroup().addTo(this.map);
                        this.resizeObserver = new ResizeObserver(() => this.map?.invalidateSize());
                        this.resizeObserver.observe(mapElement);
                        requestAnimationFrame(() => this.map?.invalidateSize());
                    } catch (error) {
                        console.error('Unable to load cleaning tracking map', error);
                        this.showMapEmpty('تعذر تحميل مكتبة الخريطة. بيانات حالات العاملين ما زالت متاحة.');
                    } finally {
                        if (loadingElement) loadingElement.hidden = true;
                    }
                },

                async refresh() {
                    if (this.destroyed || this.refreshing) return;
                    this.refreshing = true;
                    this.setRefreshBusy(true);
                    this.hideError();

                    try {
                        const response = await fetch(config.trackingUrl, {
                            method: 'GET',
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin',
                            cache: 'no-store',
                        });
                        if (!response.ok) throw new Error(`Tracking request failed with ${response.status}`);

                        const payload = await response.json();
                        const data = payload?.data ?? {};
                        this.workers = Array.isArray(data.workers) ? data.workers : [];
                        this.destination = data.destination ?? this.destination;
                        this.bookingStatus = data.bookingStatus ?? this.bookingStatus;

                        this.updateSummary(data);
                        this.renderWorkers();
                        await this.renderMapState();
                        this.updateLastRefresh(data.updatedAt);
                    } catch (error) {
                        console.error('Unable to refresh cleaning booking tracking', error);
                        this.showError('تعذر تحديث مواقع العاملين حالياً. سيتم الاحتفاظ بآخر بيانات تم تحميلها والمحاولة مجدداً تلقائياً.');
                    } finally {
                        this.refreshing = false;
                        this.setRefreshBusy(false);
                    }
                },

                updateSummary(data) {
                    const setText = (role, value) => {
                        const node = root.querySelector(`[data-role="${role}"]`);
                        if (node && value !== undefined && value !== null) node.textContent = String(value);
                    };
                    setText('booking-number', data.bookingNumber ?? config.bookingNumber);
                    setText('booking-status', data.bookingStatusLabel ?? config.bookingStatusLabel);
                    setText('accepted-workers', `${data.acceptedWorkers ?? config.acceptedWorkers} / ${data.requiredWorkers ?? config.requiredWorkers}`);
                    setText('remaining-workers', data.remainingWorkers ?? config.remainingWorkers);
                },

                renderWorkers() {
                    const container = root.querySelector('[data-role="workers"]');
                    if (!container) return;
                    container.replaceChildren();

                    if (this.workers.length === 0) {
                        const empty = createText('div', 'cleaning-booking-tracking__empty-workers', 'لم يقبل أي عامل هذا الطلب بعد. ستظهر حالة كل عامل هنا فور تعيينه.');
                        container.appendChild(empty);
                        return;
                    }

                    this.workers.forEach((worker, index) => {
                        const card = document.createElement('div');
                        card.className = 'cleaning-booking-tracking__worker';

                        const head = document.createElement('div');
                        head.className = 'cleaning-booking-tracking__worker-head';

                        const identity = document.createElement('div');
                        identity.appendChild(createText('div', 'cleaning-booking-tracking__worker-name', `${index + 1}. ${worker.name || `عامل #${worker.workerId}`}`));
                        identity.appendChild(createText('div', 'cleaning-booking-tracking__worker-phone', worker.phone || 'لا يوجد رقم هاتف'));

                        const badge = createText('span', 'cleaning-booking-tracking__badge', worker.statusLabel || worker.status || '-');
                        badge.dataset.tone = worker.statusTone || 'gray';
                        head.append(identity, badge);

                        const location = document.createElement('div');
                        location.className = 'cleaning-booking-tracking__worker-location';
                        const hasLocation = this.validCoords(worker.latitude, worker.longitude);
                        const locationText = hasLocation
                            ? worker.isTravelling
                                ? 'العامل في الطريق إلى موقع العميل'
                                : worker.arrivedAt
                                    ? 'وصل العامل إلى موقع العميل'
                                    : 'آخر موقع للعامل محفوظ'
                            : 'لم يرسل العامل موقعاً بعد';
                        location.appendChild(createText('span', '', hasLocation ? '●' : '○'));
                        location.appendChild(createText('span', '', locationText));

                        const metaParts = [];
                        if (worker.startedTravelAt) metaParts.push(`بدأ التنقل: ${this.formatDateTime(worker.startedTravelAt)}`);
                        if (worker.arrivedAt) metaParts.push(`وصل: ${this.formatDateTime(worker.arrivedAt)}`);
                        if (worker.locationUpdatedAt) metaParts.push(`آخر تحديث للموقع: ${this.formatDateTime(worker.locationUpdatedAt)}`);
                        const meta = createText('div', 'cleaning-booking-tracking__worker-meta', metaParts.length ? metaParts.join(' • ') : 'لا توجد بيانات زمنية إضافية.');

                        card.append(head, location, meta);
                        container.appendChild(card);
                    });
                },

                async renderMapState() {
                    if (!this.map || !this.workerLayerGroup || !this.routeLayerGroup) return;
                    const L = window.L;
                    const revision = ++this.routeRevision;
                    this.routeAbortController?.abort();
                    this.routeAbortController = new AbortController();
                    this.workerLayerGroup.clearLayers();
                    this.routeLayerGroup.clearLayers();

                    const locatedWorkers = this.workers.filter(worker => this.validCoords(worker.latitude, worker.longitude));
                    locatedWorkers.forEach((worker, index) => {
                        const coordinates = [Number(worker.latitude), Number(worker.longitude)];
                        const icon = L.divIcon({
                            className: '',
                            html: `<div class="cleaning-tracking-marker cleaning-tracking-marker--worker">${index + 1}</div>`,
                            iconSize: [34, 34],
                            iconAnchor: [17, 17],
                        });
                        const marker = L.marker(coordinates, { title: worker.name || 'عامل', icon }).addTo(this.workerLayerGroup);
                        const popup = document.createElement('div');
                        popup.appendChild(createText('strong', '', worker.name || 'عامل'));
                        popup.appendChild(document.createElement('br'));
                        popup.appendChild(createText('span', '', worker.statusLabel || worker.status || '-'));
                        marker.bindPopup(popup);
                    });

                    this.fitToMarkers(locatedWorkers);

                    const travelling = locatedWorkers.filter(worker => worker.isTravelling);
                    await Promise.all(travelling.map((worker, index) => this.drawRoute(worker, index, revision)));

                    const subtitle = root.querySelector('[data-role="map-subtitle"]');
                    if (subtitle) {
                        subtitle.textContent = locatedWorkers.length === 0
                            ? 'لا يوجد موقع محفوظ لأي عامل حتى الآن.'
                            : travelling.length > 0
                                ? `يتم تتبع ${travelling.length} عامل في الطريق، مع عرض آخر موقع محفوظ لبقية الفريق.`
                                : 'لا يوجد عامل في حالة تنقل حالياً؛ الخريطة تعرض آخر المواقع المحفوظة.';
                    }
                },

                fitToMarkers(workers) {
                    if (!this.map || !this.validDestination()) return;
                    const L = window.L;
                    const destination = [Number(this.destination.latitude), Number(this.destination.longitude)];
                    if (workers.length === 0) {
                        this.map.setView(destination, 15);
                        return;
                    }
                    const bounds = L.latLngBounds([destination]);
                    workers.forEach(worker => bounds.extend([Number(worker.latitude), Number(worker.longitude)]));
                    this.map.invalidateSize({ pan: false });
                    this.map.fitBounds(bounds, { maxZoom: 16, padding: [38, 38] });
                },

                async drawRoute(worker, index, revision) {
                    const L = window.L;
                    const color = RouteColors[index % RouteColors.length];
                    const origin = [Number(worker.latitude), Number(worker.longitude)];
                    const geometry = await this.roadGeometry(origin);
                    if (this.destroyed || revision !== this.routeRevision) return;

                    if (geometry) {
                        L.geoJSON(geometry, { style: { color, weight: 5, opacity: .9 } }).addTo(this.routeLayerGroup);
                        return;
                    }

                    L.polyline([origin, [Number(this.destination.latitude), Number(this.destination.longitude)]], {
                        color,
                        dashArray: '8 8',
                        opacity: .8,
                        weight: 4,
                    }).addTo(this.routeLayerGroup);
                },

                async roadGeometry(origin) {
                    try {
                        const endpoint = this.routeEndpoint(origin);
                        const response = await fetch(endpoint, {
                            headers: { Accept: 'application/json' },
                            signal: this.routeAbortController.signal,
                        });
                        if (!response.ok) return null;
                        const payload = await response.json();
                        const geometry = payload?.routes?.[0]?.geometry;
                        return geometry?.type === 'LineString' ? geometry : null;
                    } catch (_) {
                        return null;
                    }
                },

                routeEndpoint(origin) {
                    const base = new URL(config.routingServiceUrl);
                    if (!['http:', 'https:'].includes(base.protocol)) {
                        throw new TypeError('Routing service must use HTTP or HTTPS.');
                    }
                    const destination = [Number(this.destination.latitude), Number(this.destination.longitude)];
                    const coords = `${origin[1]},${origin[0]};${destination[1]},${destination[0]}`;
                    const url = new URL(`route/v1/driving/${coords}`, `${base.toString().replace(/\/$/, '')}/`);
                    url.searchParams.set('overview', 'full');
                    url.searchParams.set('geometries', 'geojson');
                    return url;
                },

                updateLastRefresh(value) {
                    const node = root.querySelector('[data-role="last-refresh"]');
                    if (!node) return;
                    node.textContent = value ? `آخر تحديث: ${this.formatDateTime(value)}` : 'تم تحديث بيانات العاملين.';
                },

                formatDateTime(value) {
                    const date = new Date(value);
                    if (Number.isNaN(date.getTime())) return String(value ?? '-');
                    return new Intl.DateTimeFormat('ar', {
                        year: 'numeric', month: '2-digit', day: '2-digit',
                        hour: '2-digit', minute: '2-digit', numberingSystem: 'latn',
                    }).format(date);
                },

                validCoords(latitude, longitude) {
                    return Number.isFinite(Number(latitude)) && Number.isFinite(Number(longitude));
                },

                validDestination() {
                    return this.destination && this.validCoords(this.destination.latitude, this.destination.longitude);
                },

                isTerminal() {
                    return ['completed', 'cancelled'].includes(String(this.bookingStatus || '').toLowerCase());
                },

                showMapEmpty(message) {
                    const empty = root.querySelector('[data-role="map-empty"]');
                    const messageNode = root.querySelector('[data-role="map-empty-message"]');
                    if (messageNode) messageNode.textContent = message;
                    if (empty) empty.hidden = false;
                },

                setRefreshBusy(busy) {
                    const button = root.querySelector('[data-role="refresh"]');
                    if (button) button.disabled = busy;
                },

                showError(message) {
                    const node = root.querySelector('[data-role="error"]');
                    if (!node) return;
                    node.textContent = message;
                    node.hidden = false;
                },

                hideError() {
                    const node = root.querySelector('[data-role="error"]');
                    if (node) node.hidden = true;
                },

                destroy() {
                    if (this.destroyed) return;
                    this.destroyed = true;
                    if (this.pollTimer) clearInterval(this.pollTimer);
                    this.routeRevision++;
                    this.routeAbortController?.abort();
                    this.resizeObserver?.disconnect();
                    this.map?.remove();
                    this.map = null;
                    if (root.__cleaningTracking === this) delete root.__cleaningTracking;
                },
            };

            app.init();
        })();
    </script>
    @endscript
@endif
