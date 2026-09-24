import Pusher from 'pusher-js';
import Echo from 'laravel-echo';

window.Pusher = Pusher;

/**
 * Create or retrieve the Laravel Echo client instance.
 * Supports Pusher and Soketi / local WebSocket servers.
 */
export function createEchoInstance(token = localStorage.getItem('admin_token')) {
    const key = import.meta.env.VITE_PUSHER_APP_KEY;
    if (!key) {
        return null;
    }

    const cluster = import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1';
    const host = import.meta.env.VITE_PUSHER_HOST;
    const port = import.meta.env.VITE_PUSHER_PORT;
    const scheme = import.meta.env.VITE_PUSHER_SCHEME || 'https';
    const isTls = scheme === 'https';

    const config = {
        broadcaster: 'pusher',
        key,
        cluster,
        forceTLS: isTls,
        authorizer: (channel) => {
            return {
                authorize: (socketId, callback) => {
                    const currentToken = localStorage.getItem('admin_token') || token;
                    window.axios
                        .post(
                            '/broadcasting/auth',
                            {
                                socket_id: socketId,
                                channel_name: channel.name,
                            },
                            {
                                headers: {
                                    'Content-Type': 'application/json',
                                    ...(currentToken ? { Authorization: `Bearer ${currentToken}` } : {}),
                                },
                            }
                        )
                        .then((response) => {
                            callback(false, response.data);
                        })
                        .catch((error) => {
                            callback(true, error);
                        });
                },
            };
        },
    };

    if (host && host !== '127.0.0.1' && host !== 'localhost') {
        config.wsHost = host;
        config.httpHost = host;
    } else if (host) {
        config.wsHost = host;
    }

    if (port) {
        config.wsPort = Number(port);
        config.wssPort = Number(port);
    }

    if (!isTls) {
        config.disableStats = true;
        config.enabledTransports = ['ws'];
    }

    return new Echo(config);
}

try {
    if (!window.Echo && import.meta.env.VITE_PUSHER_APP_KEY) {
        window.Echo = createEchoInstance();
    }
} catch (error) {
    console.warn('[Echo] Could not initialize Echo:', error);
}

export default window.Echo;
