import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

let echoInstance = null;
let echoAttemptedTokenKey = null;

/**
 * v2.0 requirements doc S15.3. Lazily creates a single shared Echo
 * instance pointed at the Reverb server, authenticated with whichever
 * bearer token the caller's own axios instance uses (user_token /
 * provider_token / admin_token), so the same private-channel
 * authorization the backend enforces (routes/channels.php) is honored
 * here too - Echo sends this token to /broadcasting/auth on every
 * subscribe.
 *
 * Returns null (never throws) when VITE_REVERB_APP_KEY is not
 * configured, so any screen that calls this stays fully usable with
 * real-time updates simply not turned on - exactly mirroring the
 * backend's ai.chat.broadcast_enabled default-off behavior.
 */
export function getEcho(tokenStorageKey = 'user_token') {
    if (echoInstance && echoAttemptedTokenKey === tokenStorageKey) {
        return echoInstance;
    }

    const key = import.meta.env.VITE_REVERB_APP_KEY;

    if (!key) {
        return null;
    }

    echoInstance?.disconnect();

    echoInstance = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                Authorization: `Bearer ${window.localStorage.getItem(tokenStorageKey) ?? ''}`,
            },
        },
    });

    echoAttemptedTokenKey = tokenStorageKey;

    return echoInstance;
}

export function disconnectEcho() {
    echoInstance?.disconnect();
    echoInstance = null;
    echoAttemptedTokenKey = null;
}
