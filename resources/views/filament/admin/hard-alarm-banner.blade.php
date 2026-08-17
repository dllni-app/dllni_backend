<div
    x-data="{ alarmActive: false, alarmTitle: '', alarmBody: '' }"
    x-show="alarmActive"
    x-cloak
    @admin-alert-sound.window="
        if ($event.detail.type === 'hard_alarm') {
            alarmActive = true;
            alarmTitle = $event.detail.title || 'تنبيه عاجل';
            alarmBody = $event.detail.body || '';
        }
    "
    style="position:fixed;inset-inline:0;top:0;z-index:9999;"
>
    <div style="background:#dc2626;color:#fff;padding:.85rem 1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;animation:admin-hard-alarm-pulse 1s infinite;box-shadow:0 2px 12px rgba(0,0,0,.35);">
        <div style="display:flex;align-items:center;gap:.75rem;">
            <span style="font-size:1.5rem;line-height:1;">🚨</span>
            <div>
                <div style="font-weight:700;" x-text="alarmTitle"></div>
                <div style="font-size:.85rem;opacity:.9;" x-text="alarmBody"></div>
            </div>
        </div>
        <button
            type="button"
            @click="alarmActive = false; $dispatch('admin-alert-stop')"
            style="background:#fff;color:#dc2626;border:0;border-radius:.375rem;padding:.4rem .9rem;font-weight:600;cursor:pointer;"
        >
            {{ __('cleaning_admin.hard_alarm.acknowledge') }}
        </button>
    </div>
</div>

<audio id="admin-alert-chime-audio" src="{{ asset('sounds/notification-chime.wav') }}" preload="auto"></audio>
<audio id="admin-alert-alarm-audio" src="{{ asset('sounds/hard-alarm.wav') }}" preload="auto" loop></audio>

<style>
    @keyframes admin-hard-alarm-pulse {
        0%, 100% { filter: brightness(1); }
        50% { filter: brightness(1.4); }
    }
</style>

<script>
    (function () {
        if (window.__adminAlertSoundPollerBooted) {
            return;
        }
        window.__adminAlertSoundPollerBooted = true;

        const checkUrl = @js(route('admin.alert-sound-check'));
        let since = new Date().toISOString();
        let pollInFlight = false;

        const chimeAudio = document.getElementById('admin-alert-chime-audio');
        const alarmAudio = document.getElementById('admin-alert-alarm-audio');

        const unlock = () => {
            chimeAudio.load();
            alarmAudio.load();
            document.removeEventListener('click', unlock);
        };
        document.addEventListener('click', unlock);

        let alarmAutoStopTimer = null;

        const stopAlarmSound = () => {
            alarmAudio.pause();
            alarmAudio.currentTime = 0;
            clearTimeout(alarmAutoStopTimer);
            alarmAutoStopTimer = null;
        };

        window.addEventListener('admin-alert-sound', (event) => {
            if (event.detail.type === 'hard_alarm') {
                alarmAudio.currentTime = 0;
                alarmAudio.play().catch(() => {});
                clearTimeout(alarmAutoStopTimer);
                alarmAutoStopTimer = setTimeout(stopAlarmSound, 5000);
                return;
            }

            chimeAudio.currentTime = 0;
            chimeAudio.play().catch(() => {});
        });

        window.addEventListener('admin-alert-stop', stopAlarmSound);

        const poll = () => {
            if (pollInFlight) {
                return;
            }
            pollInFlight = true;

            const requestedAt = new Date().toISOString();
            const url = new URL(checkUrl, window.location.origin);
            url.searchParams.set('since', since);

            fetch(url.toString(), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((data) => {
                    since = requestedAt;

                    if (! data || ! data.soundType) {
                        return;
                    }

                    window.dispatchEvent(new CustomEvent('admin-alert-sound', {
                        detail: {
                            type: data.soundType,
                            title: data.title || '',
                            body: data.body || '',
                        },
                    }));
                })
                .catch(() => {})
                .finally(() => {
                    pollInFlight = false;
                });
        };

        setInterval(poll, 5000);
    })();
</script>
