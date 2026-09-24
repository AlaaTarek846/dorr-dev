import { onUnmounted, watch } from 'vue';
import { getEcho } from '../echo';

/**
 * v2.0 requirements doc S15.3. Subscribes to the private
 * `ai-conversation.{id}` channel for the currently open conversation and
 * calls `onMessage(message)` whenever AiMessageBroadcast fires on the
 * backend - the final assistant reply pushed the moment it is persisted,
 * for any OTHER open tab/session on the same conversation (the tab that
 * actually sent the message already has the reply from its own HTTP
 * response; this composable is for everyone else watching).
 *
 * Silently does nothing (no error, no crash) when Echo is not configured
 * (no VITE_REVERB_APP_KEY) - the chat screen remains fully usable
 * without real-time push, exactly mirroring the backend's default-off
 * ai.chat.broadcast_enabled flag.
 *
 * @param {import('vue').Ref<number|string|null>} conversationIdRef
 * @param {(message: object) => void} onMessage
 * @param {string} tokenStorageKey - 'user_token' | 'provider_token' | 'admin_token'
 */
export function useAiConversationChannel(conversationIdRef, onMessage, tokenStorageKey = 'user_token') {
    let currentChannelName = null;

    function unsubscribeCurrent() {
        if (!currentChannelName) {
            return;
        }

        const echo = getEcho(tokenStorageKey);
        echo?.leave(currentChannelName);
        currentChannelName = null;
    }

    function subscribe(conversationId) {
        unsubscribeCurrent();

        if (!conversationId) {
            return;
        }

        const echo = getEcho(tokenStorageKey);

        if (!echo) {
            return;
        }

        currentChannelName = `ai-conversation.${conversationId}`;

        echo.private(currentChannelName).listen('.message.ready', (event) => {
            onMessage(event.message);
        });
    }

    watch(conversationIdRef, (newId) => subscribe(newId), { immediate: true });

    onUnmounted(() => unsubscribeCurrent());
}
