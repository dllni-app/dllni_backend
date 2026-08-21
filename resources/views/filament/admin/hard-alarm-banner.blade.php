<div
    x-data="{
        alarmActive: false,
        alarmTitle: '',
        alarmBody: '',
        noticeActive: false,
        noticeTitle: '',
        noticeBody: '',
        soundBlocked: false,
    }"
    @admin-alert-sound.window="
        if ($event.detail.type === 'hard_alarm') {
            alarmActive = true;
            noticeActive = false;
            alarmTitle = $event.detail.title || 'تنبيه عاجل';
            alarmBody = $event.detail.body || '';
        } else if ($event.detail.showBanner) {
            noticeActive = true;
            noticeTitle = $event.detail.title || 'إشعار جديد';
            noticeBody = $event.detail.body || '';
            setTimeout(() => noticeActive = false, 8000);
        }
    "
    @admin-alert-sound-blocked.window="soundBlocked = true"
    @admin-alert-sound-enabled.window="soundBlocked = false"
>
    <div
        x-show="alarmActive"
        x-cloak
        style="position:fixed;inset-inline:0;top:0;z-index:9999;background:#dc2626;color:#fff;padding:.85rem 1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;animation:admin-hard-alarm-pulse 1s infinite;box-shadow:0 2px 12px rgba(0,0,0,.35);"
    >
        <div style="display:flex;align-items:center;gap:.75rem;">
            <span style="font-size:1.5rem;line-height:1;">🚨</span>
            <div>
                <div style="font-weight:700;" x-text="alarmTitle"></div>
                <div style="font-size:.85rem;opacity:.9;" x-text="alarmBody"></div>
            </div>
        </div>

        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
            <button
                x-show="soundBlocked"
                x-cloak
                type="button"
                @click="$dispatch('admin-alert-enable-sound')"
                style="background:#fee2e2;color:#991b1b;border:0;border-radius:.375rem;padding:.4rem .9rem;font-weight:700;cursor:pointer;"
            >
                🔊 تشغيل صوت التنبيه
            </button>

            <button
                type="button"
                @click="alarmActive = false; $dispatch('admin-alert-stop')"
                style="background:#fff;color:#dc2626;border:0;border-radius:.375rem;padding:.4rem .9rem;font-weight:600;cursor:pointer;"
            >
                {{ __('cleaning_admin.hard_alarm.acknowledge') }}
            </button>
        </div>
    </div>

    <div
        x-show="noticeActive && ! alarmActive"
        x-cloak
        style="position:fixed;inset-inline:0;top:0;z-index:9998;background:#b45309;color:#fff;padding:.75rem 1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;box-shadow:0 2px 10px rgba(0,0,0,.25);"
    >
        <div style="display:flex;align-items:center;gap:.75rem;">
            <span style="font-size:1.25rem;line-height:1;">⚠️</span>
            <div>
                <div style="font-weight:700;" x-text="noticeTitle"></div>
                <div style="font-size:.85rem;opacity:.92;" x-text="noticeBody"></div>
            </div>
        </div>

        <button
            type="button"
            @click="noticeActive = false"
            style="background:#fff;color:#92400e;border:0;border-radius:.375rem;padding:.35rem .75rem;font-weight:600;cursor:pointer;"
        >
            إغلاق
        </button>
    </div>

    <button
        x-show="soundBlocked && ! alarmActive"
        x-cloak
        type="button"
        @click="$dispatch('admin-alert-enable-sound')"
        style="position:fixed;inset-inline-start:1rem;bottom:1rem;z-index:9997;background:#111827;color:#fff;border:0;border-radius:.5rem;padding:.55rem .9rem;font-weight:700;cursor:pointer;box-shadow:0 2px 10px rgba(0,0,0,.3);"
    >
        🔊 تفعيل صوت التنبيهات
    </button>
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
        let soundUnlocked = false;
        let alarmAutoStopTimer = null;
        let fallbackAlarmTimer = null;
        let fallbackAlarmStopTimer = null;
        let lastSoundType = null;
        let audioContext = null;

        const audioElement = (id) => document.getElementById(id);

        const ensureAudioContext = async () => {
            const Context = window.AudioContext || window.webkitAudioContext;
            if (! Context) {
                return false;
            }

            audioContext = audioContext || new Context();

            if (audioContext.state === 'suspended') {
                await audioContext.resume();
            }

            return audioContext.state === 'running';
        };

        const primeElement = async (audio) => {
            if (! audio) {
                return;
            }

            const previousVolume = audio.volume;
            audio.volume = 0;

            try {
                audio.currentTime = 0;
                await audio.play();
                audio.pause();
                audio.currentTime = 0;
            } finally {
                audio.volume = previousVolume;
            }
        };

        const detachUnlockListeners = () => {
            document.removeEventListener('pointerdown', unlockSound, true);
            document.removeEventListener('keydown', unlockSound, true);
            document.removeEventListener('touchstart', unlockSound, true);
        };

        const unlockSound = async () => {
            if (soundUnlocked) {
                return true;
            }

            try {
                await ensureAudioContext();
                await Promise.all([
                    primeElement(audioElement('admin-alert-chime-audio')),
                    primeElement(audioElement('admin-alert-alarm-audio')),
                ]);

                soundUnlocked = true;
                detachUnlockListeners();
                window.dispatchEvent(new CustomEvent('admin-alert-sound-enabled'));

                return true;
            } catch (error) {
                return false;
            }
        };

        document.addEventListener('pointerdown', unlockSound, true);
        document.addEventListener('keydown', unlockSound, true);
        document.addEventListener('touchstart', unlockSound, true);

        const stopFallbackAlarm = () => {
            clearInterval(fallbackAlarmTimer);
            clearTimeout(fallbackAlarmStopTimer);
            fallbackAlarmTimer = null;
            fallbackAlarmStopTimer = null;
        };

        const playTone = (frequency, duration, delay = 0) => {
            if (! audioContext || audioContext.state !== 'running') {
                return false;
            }

            const oscillator = audioContext.createOscillator();
            const gain = audioContext.createGain();
            const startAt = audioContext.currentTime + delay;
            const endAt = startAt + duration;

            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(frequency, startAt);
            gain.gain.setValueAtTime(0.0001, startAt);
            gain.gain.exponentialRampToValueAtTime(0.18, startAt + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, endAt);

            oscillator.connect(gain);
            gain.connect(audioContext.destination);
            oscillator.start(startAt);
            oscillator.stop(endAt + 0.02);

            return true;
        };

        const playChimeFallback = () => {
            const first = playTone(880, 0.16);
            playTone(1174, 0.18, 0.17);
            return first;
        };

        const playHardAlarmFallback = () => {
            stopFallbackAlarm();

            const burst = () => {
                playTone(880, 0.18);
                playTone(660, 0.18, 0.2);
            };

            if (! audioContext || audioContext.state !== 'running') {
                return false;
            }

            burst();
            fallbackAlarmTimer = setInterval(burst, 450);
            fallbackAlarmStopTimer = setTimeout(stopFallbackAlarm, 5000);

            return true;
        };

        const stopAlarmSound = () => {
            const alarmAudio = audioElement('admin-alert-alarm-audio');
            if (alarmAudio) {
                alarmAudio.pause();
                alarmAudio.currentTime = 0;
            }

            clearTimeout(alarmAutoStopTimer);
            alarmAutoStopTimer = null;
            stopFallbackAlarm();
        };

        const playMedia = async (audio) => {
            if (! audio) {
                return false;
            }

            try {
                audio.currentTime = 0;
                await audio.play();
                return true;
            } catch (error) {
                return false;
            }
        };

        const reportBlockedSound = () => {
            window.dispatchEvent(new CustomEvent('admin-alert-sound-blocked'));
        };

        const playHardAlarm = async () => {
            stopAlarmSound();

            if (await playMedia(audioElement('admin-alert-alarm-audio'))) {
                alarmAutoStopTimer = setTimeout(stopAlarmSound, 5000);
                return;
            }

            try {
                await ensureAudioContext();
            } catch (error) {
                // The explicit enable-sound button below will retry from a user gesture.
            }

            if (! playHardAlarmFallback()) {
                reportBlockedSound();
            }
        };

        const playChime = async () => {
            if (await playMedia(audioElement('admin-alert-chime-audio'))) {
                return;
            }

            try {
                await ensureAudioContext();
            } catch (error) {
                // The explicit enable-sound button below will retry from a user gesture.
            }

            if (! playChimeFallback()) {
                reportBlockedSound();
            }
        };

        window.addEventListener('admin-alert-sound', (event) => {
            lastSoundType = event.detail.type;

            if (event.detail.type === 'hard_alarm') {
                playHardAlarm();
                return;
            }

            playChime();
        });

        window.addEventListener('admin-alert-enable-sound', async () => {
            const unlocked = await unlockSound();
            if (! unlocked) {
                reportBlockedSound();
                return;
            }

            if (lastSoundType === 'hard_alarm') {
                playHardAlarm();
            } else if (lastSoundType) {
                playChime();
            }
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
                            showBanner: Boolean(data.showBanner),
                            notificationId: data.notificationId || null,
                            notificationType: data.notificationType || null,
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
