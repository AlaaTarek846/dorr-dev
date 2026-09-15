import { computed, onBeforeUnmount, ref, watch } from 'vue';

const DEFAULT_COOLDOWN_SECONDS = 60;

function storageKey(token) {
    return `user_otp_resend_until:${token}`;
}

export function useResendCooldown(flowToken, cooldownSeconds = DEFAULT_COOLDOWN_SECONDS) {
    const remainingSeconds = ref(0);
    let intervalId = null;

    const canResend = computed(() => remainingSeconds.value <= 0);

    function clearTimer() {
        if (intervalId) {
            clearInterval(intervalId);
            intervalId = null;
        }
    }

    function syncRemaining() {
        const token = flowToken.value;

        if (! token) {
            remainingSeconds.value = 0;
            return;
        }

        const availableAt = Number(sessionStorage.getItem(storageKey(token)) || 0);
        remainingSeconds.value = Math.max(0, Math.ceil((availableAt - Date.now()) / 1000));
    }

    function startCooldown(seconds = cooldownSeconds) {
        const token = flowToken.value;

        if (! token) {
            return;
        }

        const availableAt = Date.now() + (seconds * 1000);
        sessionStorage.setItem(storageKey(token), String(availableAt));
        syncRemaining();
        ensureTimer();
    }

    function ensureTimer() {
        clearTimer();

        if (remainingSeconds.value <= 0) {
            return;
        }

        intervalId = setInterval(() => {
            syncRemaining();

            if (remainingSeconds.value <= 0) {
                clearTimer();
            }
        }, 1000);
    }

    function initializeCooldown() {
        const token = flowToken.value;

        if (! token) {
            return;
        }

        const stored = Number(sessionStorage.getItem(storageKey(token)) || 0);

        if (! stored || stored <= Date.now()) {
            startCooldown();
            return;
        }

        syncRemaining();
        ensureTimer();
    }

    watch(flowToken, (token) => {
        clearTimer();

        if (token) {
            initializeCooldown();
        } else {
            remainingSeconds.value = 0;
        }
    }, { immediate: true });

    onBeforeUnmount(clearTimer);

    return {
        remainingSeconds,
        canResend,
        startCooldown,
    };
}
