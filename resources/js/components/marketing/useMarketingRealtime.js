import Pusher from 'pusher-js';
import { getEcho } from '../../echo';

// Job moves (research, plans, calendar, studio), budget alerts, platform connections and campaign moves.
const EVENTS = ['marketing.job.changed', 'marketing.budget.alert', 'marketing.integration.changed', 'marketing.campaign.changed', 'marketing.alert.fired'];

/** The project uuid of a push; a budget alert carries it nested (notification payload), so look a few levels down. */
function projectOf(payload, depth = 0) {
    if (! payload || typeof payload !== 'object' || depth > 4) {
        return null;
    }
    if (typeof payload.project_id === 'string') {
        return payload.project_id;
    }
    for (const value of Object.values(payload)) {
        const found = projectOf(value, depth + 1);
        if (found) {
            return found;
        }
    }

    return null;
}

/**
 * Listens for `marketing.job.changed` on the account's own private channel
 * (`Modules.User.Models.User.{id}` / `Modules.Provider.Models.Provider.{id}`) and calls `onChange(projectId)` once per push.
 * A reconnect also calls `onChange(null)`: the open page reloads once as the catch-up. No timers, no polling.
 *
 * Connection settings come from the server (`realtimeConfigPath`, the chat's realtime-config) when the SPA has one;
 * otherwise the shared Echo/Reverb client (VITE_REVERB_*) is used. With neither, the pages still work — they just load
 * on open and on the person's own actions.
 */
export default function useMarketingRealtime(ctx) {
    let pusher = null;
    let channel = null;
    let echoChannelName = null;
    let wasConnected = false;
    let watched = null;
    let watcher = null;

    function watchConnection(connection, onChange) {
        if (! connection) {
            return;
        }
        wasConnected = connection.state === 'connected';
        watcher = ({ current }) => {
            if (current === 'connected') {
                if (wasConnected) {
                    onChange(null);
                }
                wasConnected = true;
            }
        };
        watched = connection;
        connection.bind('state_change', watcher);
    }

    async function connectWithConfig(channelName, onChange) {
        let config;
        try {
            const { data } = await ctx.http.get(ctx.realtimeConfigPath);
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
                customHandler: ({ socketId, channelName: name }, callback) => {
                    ctx.http.post(config.auth_path || '/broadcasting/auth', { socket_id: socketId, channel_name: name })
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
        watchConnection(pusher.connection, onChange);
        channel = pusher.subscribe(`private-${channelName}`);
        EVENTS.forEach((name) => channel.bind(name, (payload) => onChange(projectOf(payload))));

        return true;
    }

    function connectWithEcho(channelName, onChange) {
        const echo = getEcho(ctx.tokenKey);
        if (! echo) {
            return false;
        }

        echoChannelName = channelName;
        const priv = echo.private(channelName);
        EVENTS.forEach((name) => priv.listen(`.${name}`, (payload) => onChange(projectOf(payload))));
        watchConnection(echo.connector?.pusher?.connection, onChange);

        return true;
    }

    async function connect(accountId, onChange) {
        disconnect();
        if (! accountId) {
            return false;
        }

        const channelName = ctx.channelFor(accountId);
        if (ctx.realtimeConfigPath && await connectWithConfig(channelName, onChange)) {
            return true;
        }

        return connectWithEcho(channelName, onChange);
    }

    function disconnect() {
        if (watched && watcher) {
            watched.unbind('state_change', watcher);
        }
        watched = null;
        watcher = null;
        if (channel) {
            channel.unbind_all();
            channel = null;
        }
        if (pusher) {
            pusher.disconnect();
            pusher = null;
        }
        if (echoChannelName) {
            getEcho(ctx.tokenKey)?.leave(echoChannelName);
            echoChannelName = null;
        }
        wasConnected = false;
    }

    return { connect, disconnect };
}
