import Pusher from 'pusher-js';
import userAxios from '../api/userAxios';

/**
 * The web chat's realtime link: one Pusher connection on the user's private channel
 * (`private-Modules.User.Models.User.{id}`), configured from `GET chat/realtime-config` — the same
 * source the app uses, so a move to a self-hosted server needs no rebuild.
 *
 * `connect(userId, onEvent)` subscribes and forwards every `chat.*` event as `(name, payload)`;
 * `disconnect()` closes it. Nothing happens when realtime is off on the server.
 */
export default function useChatRealtime() {
    let pusher = null;
    let channel = null;

    async function connect(userId, onEvent) {
        disconnect();

        let config;
        try {
            const { data } = await userAxios.get('/api/user/v1/chat/realtime-config');
            config = data.data;
        } catch {
            return false;
        }

        if (! config?.enabled || ! config.key) {
            return false;
        }

        const options = {
            cluster: config.cluster || 'mt1',
            forceTLS: config.use_tls !== false,
            channelAuthorization: {
                customHandler: ({ socketId, channelName }, callback) => {
                    userAxios.post(config.auth_path || '/broadcasting/auth', { socket_id: socketId, channel_name: channelName })
                        .then(({ data }) => callback(null, data))
                        .catch((error) => callback(error, null));
                },
            },
        };

        if (config.host) {
            options.wsHost = config.host;
            options.httpHost = config.host;
            if (config.port) {
                options.wsPort = Number(config.port);
                options.wssPort = Number(config.port);
            }
            options.enabledTransports = ['ws', 'wss'];
        }

        pusher = new Pusher(config.key, options);
        channel = pusher.subscribe(`private-Modules.User.Models.User.${userId}`);
        channel.bind_global((name, payload) => {
            if (typeof name === 'string' && name.startsWith('chat.')) {
                onEvent(name, payload ?? {});
            }
        });

        return true;
    }

    function disconnect() {
        if (channel) {
            channel.unbind_all();
            channel = null;
        }
        if (pusher) {
            pusher.disconnect();
            pusher = null;
        }
    }

    return { connect, disconnect };
}
