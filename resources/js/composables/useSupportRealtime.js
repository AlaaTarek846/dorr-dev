import { onBeforeUnmount, onMounted } from 'vue';
import { useAuthStore } from '../stores/auth';

const EVENTS = ['support.ticket.created', 'support.message', 'support.ticket.updated'];

/**
 * Live support: subscribes to the admin's own private channel (`App.Models.Admin.{id}`, the one the
 * notification bell already uses) and hands every support event to `handler(name, payload)`.
 *
 * `payload.ticket` is the ticket as the dashboard lists it; `payload.message` is the new message
 * (only on `support.message`). Does nothing when real-time is not configured (no Echo).
 */
export default function useSupportRealtime(handler) {
    const authStore = useAuthStore();
    let channel = null;
    let callbacks = [];

    function start() {
        const adminId = authStore.admin?.id;

        if (! adminId || ! window.Echo || channel) {
            return;
        }

        try {
            channel = window.Echo.private(`App.Models.Admin.${adminId}`);
            callbacks = EVENTS.map((name) => {
                const callback = (payload) => handler(name, payload);

                channel.listen(`.${name}`, callback);

                return [name, callback];
            });
        } catch (error) {
            console.warn('[support] real-time subscription failed:', error);
        }
    }

    function stop() {
        if (! channel) {
            return;
        }

        callbacks.forEach(([name, callback]) => {
            try {
                // Only this page's listeners: the notification bell shares the channel.
                channel.stopListening(`.${name}`, callback);
            } catch {
                // already gone
            }
        });
        channel = null;
        callbacks = [];
    }

    onMounted(start);
    onBeforeUnmount(stop);

    return { start, stop };
}
