<template>
    <div class="mx-page">
        <div class="mx-shell" :class="{ 'has-active': !!active }">
            <!-- ============================================================ list -->
            <aside class="mx-list">
                <header class="mx-list-head">
                    <h5 class="mb-0 fw-bold">{{ t('messenger.title') }}</h5>
                    <button type="button" class="mx-icon-btn ms-auto" :title="t('messenger.new_chat')" @click="openNewChat"><i class="ri-chat-new-line"></i></button>
                </header>

                <div class="mx-search">
                    <i class="ri-search-line"></i>
                    <input v-model="search" type="search" :placeholder="t('messenger.search')">
                </div>

                <div class="mx-filters">
                    <button v-for="f in filters" :key="f" type="button" :class="{ on: filter === f }" @click="filter = f">
                        {{ t(`messenger.filters.${f}`) }}
                        <span v-if="f === 'unread' && unreadTotal" class="mx-count">{{ unreadTotal }}</span>
                    </button>
                </div>

                <button v-if="requestsCount && filter !== 'requests'" type="button" class="mx-requests" @click="filter = 'requests'">
                    <i class="ri-mail-unread-line"></i> {{ t('messenger.requests', { count: requestsCount }) }}
                </button>

                <div class="mx-list-body">
                    <div v-if="loadingList" class="mx-center"><span class="spinner-border spinner-border-sm text-primary"></span></div>
                    <div v-else-if="!visibleConversations.length" class="mx-empty">
                        <i class="ri-chat-smile-2-line"></i>
                        <p>{{ t('messenger.no_chats') }}</p>
                        <button type="button" class="btn btn-primary btn-sm" @click="openNewChat">{{ t('messenger.new_chat') }}</button>
                    </div>
                    <TransitionGroup v-else name="mx-rows" tag="div">
                        <button
                            v-for="c in visibleConversations" :key="c.id" type="button"
                            class="mx-conv" :class="{ active: active?.id === c.id }"
                            @click="open(c)"
                        >
                            <Avatar :src="c.avatar" :name="c.title" :seed="c.peer?.key || c.id" :online="c.peer && presence[c.peer.key]?.online" />
                            <span class="mx-conv-main">
                                <span class="mx-conv-top">
                                    <span class="mx-conv-title">{{ c.title || '—' }}</span>
                                    <span class="mx-conv-time" :class="{ unread: c.unread_count }">{{ listTime(c.last_message_at) }}</span>
                                </span>
                                <span class="mx-conv-bottom">
                                    <span v-if="typingIn(c.id)" class="mx-typing-text">{{ typingIn(c.id) }}</span>
                                    <span v-else class="mx-conv-preview">
                                        <i v-if="c.last_message?.is_mine && !c.last_message?.is_deleted" class="mx-tick" :class="tickOf(c.last_message.status)"></i>
                                        {{ preview(c) }}
                                    </span>
                                    <i v-if="c.is_muted" class="ri-volume-mute-line text-muted ms-1"></i>
                                    <i v-if="c.is_pinned" class="ri-pushpin-2-fill text-muted ms-1"></i>
                                    <span v-if="c.has_unread_mention" class="mx-badge mention">@</span>
                                    <span v-if="c.unread_count || c.marked_unread" class="mx-badge" :class="{ muted: c.is_muted }">{{ c.unread_count || '' }}</span>
                                </span>
                            </span>
                        </button>
                    </TransitionGroup>
                </div>
            </aside>

            <!-- ============================================================ conversation -->
            <section class="mx-chat">
                <div v-if="!active" class="mx-welcome">
                    <div class="mx-welcome-art"><i class="ri-chat-heart-line"></i></div>
                    <h4 class="fw-bold">{{ t('messenger.welcome_title') }}</h4>
                    <p class="text-muted">{{ t('messenger.welcome_text') }}</p>
                    <span class="mx-lock"><i class="ri-lock-line"></i> {{ t('messenger.private_note') }}</span>
                </div>

                <template v-else>
                    <header class="mx-chat-head">
                        <button type="button" class="mx-icon-btn d-lg-none" @click="close"><i class="ri-arrow-left-line rtl-flip"></i></button>
                        <Avatar :src="active.avatar" :name="active.title" :seed="active.peer?.key || active.id" size="40" />
                        <div class="mx-chat-who">
                            <div class="fw-bold text-truncate">{{ active.title }}</div>
                            <Transition name="mx-fade" mode="out-in">
                                <div :key="subtitle" class="mx-chat-sub" :class="{ typing: !!typingIn(active.id) }">{{ subtitle }}</div>
                            </Transition>
                        </div>
                        <div class="mx-search mini ms-auto" :class="{ open: searching }">
                            <button type="button" class="mx-icon-btn" @click="toggleSearch"><i :class="searching ? 'ri-close-line' : 'ri-search-line'"></i></button>
                            <input v-if="searching" ref="searchInput" v-model="searchQuery" type="search" :placeholder="t('messenger.search_in_chat')" @keydown.enter="runSearch">
                        </div>
                    </header>

                    <!-- search results -->
                    <Transition name="mx-slide">
                        <div v-if="searching && searchResults" class="mx-search-results">
                            <div v-if="!searchResults.length" class="text-muted fs-13 p-3">{{ t('messenger.no_results') }}</div>
                            <button v-for="m in searchResults" :key="m.id" type="button" @click="jumpTo(m.id)">
                                <span class="fw-semibold">{{ m.is_mine ? t('messenger.you') : m.sender?.name }}</span>
                                <span class="text-truncate">{{ m.body }}</span>
                                <span class="text-muted fs-11 ms-auto">{{ listTime(m.created_at) }}</span>
                            </button>
                        </div>
                    </Transition>

                    <!-- request banner -->
                    <div v-if="active.is_request" class="mx-request-bar">
                        <span>{{ t('messenger.request_text', { name: active.title }) }}</span>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-light" @click="rejectRequest">{{ t('messenger.reject') }}</button>
                            <button type="button" class="btn btn-sm btn-primary" @click="acceptRequest">{{ t('messenger.accept') }}</button>
                        </div>
                    </div>

                    <div ref="scroller" class="mx-messages" :style="wallpaperStyle" @scroll.passive="onScroll">
                        <div v-if="loadingOlder" class="mx-center py-2"><span class="spinner-border spinner-border-sm text-primary"></span></div>
                        <div v-if="loadingMessages" class="mx-center h-100"><span class="spinner-border text-primary"></span></div>
                        <template v-else>
                            <div v-if="!hasMoreBefore && !messages.length" class="mx-day"><span>{{ t('messenger.say_hi') }}</span></div>
                            <template v-for="line in lines" :key="line.key">
                                <div v-if="line.day" class="mx-day"><span>{{ line.day }}</span></div>
                                <div v-else :id="`m-${line.m.id}`" :class="{ 'mx-flash': highlight === line.m.id }">
                                    <MessengerBubble
                                        :message="line.m" :first="line.first" :is-group="active.type === 'group'" :theme="theme"
                                        @reply="startReply" @react="react" @edit="startEdit" @delete="remove" @retry="retry" @jump="jumpTo" @view="openViewer"
                                        @vote="vote" @open="openViewOnce" @stop-live="stopLive"
                                    />
                                </div>
                            </template>
                            <Transition name="mx-fade">
                                <div v-if="typingIn(active.id)" class="mx-row"><div class="mx-bubble mx-typing"><span></span><span></span><span></span></div></div>
                            </Transition>
                        </template>
                    </div>

                    <Transition name="mx-pop">
                        <button v-if="showJump" type="button" class="mx-jump" @click="scrollToBottom(true)">
                            <i class="ri-arrow-down-s-line"></i>
                            <span v-if="newWhileAway" class="mx-badge">{{ newWhileAway }}</span>
                        </button>
                    </Transition>

                    <!-- composer -->
                    <footer v-if="active.can_send" class="mx-composer" @dragover.prevent @drop.prevent="onDrop">
                        <Transition name="mx-slide">
                            <div v-if="replyTo || editing" class="mx-compose-context">
                                <i :class="editing ? 'ri-pencil-line' : 'ri-reply-line'"></i>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-semibold fs-12 text-primary">{{ editing ? t('messenger.editing') : (replyTo.is_mine ? t('messenger.you') : replyTo.sender?.name) }}</div>
                                    <div class="text-truncate fs-13">{{ (editing || replyTo).body || t(`messenger.types.${(editing || replyTo).type}`) }}</div>
                                </div>
                                <button type="button" class="mx-icon-btn" @click="cancelContext"><i class="ri-close-line"></i></button>
                            </div>
                        </Transition>

                        <Transition name="mx-slide">
                            <div v-if="pendingFiles.length" class="mx-pending-files">
                                <div v-for="(f, i) in pendingFiles" :key="i" class="mx-pending-file">
                                    <img v-if="f.preview" :src="f.preview" alt="">
                                    <span v-else><i class="ri-file-3-line"></i> {{ f.file.name }}</span>
                                    <button type="button" @click="dropFile(i)"><i class="ri-close-line"></i></button>
                                </div>
                            </div>
                        </Transition>

                        <div class="mx-compose-row">
                            <div class="position-relative">
                                <button type="button" class="mx-icon-btn" @click="emojiOpen = !emojiOpen"><i class="ri-emotion-line"></i></button>
                                <Transition name="mx-pop">
                                    <div v-if="emojiOpen" class="mx-emoji-panel">
                                        <button v-for="e in emojis" :key="e" type="button" @click="insertEmoji(e)">{{ e }}</button>
                                    </div>
                                </Transition>
                            </div>
                            <button v-if="!editing" type="button" class="mx-icon-btn" :title="t('messenger.attach')" @click="fileInput.click()"><i class="ri-attachment-2"></i></button>
                            <input ref="fileInput" type="file" multiple hidden @change="pickFiles">
                            <textarea
                                ref="textarea" v-model="draft" rows="1" dir="auto"
                                :placeholder="t('messenger.type_message')"
                                @input="onType" @keydown.enter.exact.prevent="send" @keydown.esc="cancelContext" @paste="onPaste"
                            ></textarea>
                            <button type="button" class="mx-send" :disabled="!canSend" @click="send">
                                <i :class="editing ? 'ri-check-line' : 'ri-send-plane-2-fill'" class="rtl-flip"></i>
                            </button>
                        </div>
                    </footer>
                    <footer v-else class="mx-composer mx-cannot">{{ cannotSendText }}</footer>
                </template>
            </section>
        </div>

        <!-- ============================================================ new chat -->
        <Transition name="mx-fade">
            <div v-if="newChatOpen" class="mx-modal" @click.self="newChatOpen = false">
                <div class="mx-modal-card">
                    <header class="d-flex align-items-center mb-3">
                        <h6 class="mb-0 fw-bold">{{ t('messenger.new_chat') }}</h6>
                        <button type="button" class="mx-icon-btn ms-auto" @click="newChatOpen = false"><i class="ri-close-line"></i></button>
                    </header>
                    <form class="mx-phone" @submit.prevent="lookupPhone">
                        <input v-model="phone" type="tel" dir="ltr" class="form-control" :placeholder="t('messenger.phone_placeholder')">
                        <button type="submit" class="btn btn-primary" :disabled="!phone.trim() || lookingUp">
                            <span v-if="lookingUp" class="spinner-border spinner-border-sm"></span><i v-else class="ri-search-line"></i>
                        </button>
                    </form>
                    <div v-if="lookupError" class="text-danger fs-12 mt-1">{{ lookupError }}</div>

                    <div class="mx-search mt-3">
                        <i class="ri-search-line"></i>
                        <input v-model="contactSearch" type="search" :placeholder="t('messenger.search_contacts')">
                    </div>
                    <div class="mx-contacts">
                        <div v-if="loadingContacts" class="mx-center py-4"><span class="spinner-border spinner-border-sm text-primary"></span></div>
                        <div v-else-if="!visibleContacts.length" class="text-muted text-center fs-13 py-4">{{ t('messenger.no_contacts') }}</div>
                        <button v-for="ct in visibleContacts" :key="ct.id" type="button" class="mx-conv" @click="startWith(ct.profile)">
                            <Avatar :src="ct.profile?.avatar" :name="ct.name" :seed="ct.profile?.key || ct.phone" />
                            <span class="mx-conv-main">
                                <span class="mx-conv-title">{{ ct.name }}</span>
                                <span class="mx-conv-preview" dir="ltr">{{ ct.phone }}</span>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- ============================================================ media viewer -->
        <Transition name="mx-fade">
            <div v-if="viewer" class="mx-viewer" @click.self="viewer = null">
                <button type="button" class="mx-viewer-close" @click="viewer = null"><i class="ri-close-line"></i></button>
                <button v-if="viewer.index > 0" type="button" class="mx-viewer-nav prev" @click="viewer.index--"><i class="ri-arrow-left-s-line"></i></button>
                <Transition name="mx-zoom" mode="out-in">
                    <img v-if="viewerItem && isImageAttachment(viewerItem)" :key="viewerItem.url" :src="viewerItem.url" alt="">
                    <video v-else-if="viewerItem" :key="viewerItem.url" :src="viewerItem.url" controls autoplay></video>
                </Transition>
                <button v-if="viewer.index < viewer.items.length - 1" type="button" class="mx-viewer-nav next" @click="viewer.index++"><i class="ri-arrow-right-s-line"></i></button>
            </div>
        </Transition>
    </div>
</template>

<script setup>
import { computed, defineComponent, h, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import userAxios from '../../../../../../api/userAxios';
import MessengerBubble from '../../../../../../components/messenger/MessengerBubble.vue';
import '../../../../../../components/messenger/messenger.css';
import useChatRealtime from '../../../../../../composables/useChatRealtime';
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';
import { useUserAuthStore } from '../../../../../../stores/userAuth';

const API = '/api/user/v1/chat';
const { t, locale } = useI18n();
const { showError } = useToast();
const auth = useUserAuthStore();
const realtime = useChatRealtime();

// ------------------------------------------------------------------ avatar
const gradients = [['#FB7185', '#E11D48'], ['#A78BFA', '#7C3AED'], ['#22D3EE', '#0891B2'], ['#34D399', '#059669'], ['#FBBF24', '#D97706'], ['#60A5FA', '#2563EB']];
const Avatar = defineComponent({
    props: { src: String, name: String, seed: String, online: Boolean, size: { type: [String, Number], default: 48 } },
    setup(props) {
        return () => {
            let hash = 0;
            for (const ch of props.seed || props.name || '') hash = (hash * 31 + ch.charCodeAt(0)) | 0;
            const [a, b] = gradients[Math.abs(hash) % gradients.length];
            const initials = (props.name || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
            const px = `${props.size}px`;

            return h('span', { class: 'mx-avatar', style: { width: px, height: px, minWidth: px, background: `linear-gradient(135deg, ${a}, ${b})` } }, [
                props.src ? h('img', { src: props.src, alt: '' }) : h('span', initials),
                props.online ? h('i', { class: 'mx-online' }) : null,
            ]);
        };
    },
});

// ------------------------------------------------------------------ state
const me = ref(null);
const myKey = computed(() => (me.value ? `user:${me.value.id}` : ''));

const conversations = ref([]);
const requestsCount = ref(0);
const loadingList = ref(true);
const search = ref('');
const filter = ref('all');
const filters = ['all', 'unread', 'groups', 'channels', 'archived'];

const active = ref(null);
const messages = ref([]);
const loadingMessages = ref(false);
const loadingOlder = ref(false);
const hasMoreBefore = ref(false);
const scroller = ref(null);
const showJump = ref(false);
const newWhileAway = ref(0);
const highlight = ref(null);

const draft = ref('');
const textarea = ref(null);
const fileInput = ref(null);
const pendingFiles = ref([]);
const replyTo = ref(null);
const editing = ref(null);
const emojiOpen = ref(false);
const emojis = ['😀', '😂', '😍', '🥰', '😎', '🤔', '😢', '😡', '👍', '👏', '🙏', '💪', '❤️', '🔥', '🎉', '✅', '👀', '😅', '🤝', '🌹', '☕', '💯', '😴', '🤲'];

const typing = reactive({});
const presence = reactive({});

const searching = ref(false);
const searchQuery = ref('');
const searchResults = ref(null);
const searchInput = ref(null);

const newChatOpen = ref(false);
const contacts = ref([]);
const loadingContacts = ref(false);
const contactSearch = ref('');
const phone = ref('');
const lookingUp = ref(false);
const lookupError = ref('');

const viewer = ref(null);
const viewerItem = computed(() => viewer.value?.items[viewer.value.index] ?? null);

let presenceTimer = null;
let typingSentAt = 0;
let typingStopTimer = null;
let readTimer = null;

// ------------------------------------------------------------------ derived
const visibleConversations = computed(() => {
    const needle = search.value.trim().toLowerCase();
    let list = conversations.value;

    if (filter.value === 'requests') list = list.filter((c) => c.is_request);
    else if (filter.value === 'archived') list = list.filter((c) => c.is_archived);
    else {
        list = list.filter((c) => ! c.is_archived && ! c.is_request);
        if (filter.value === 'unread') list = list.filter((c) => c.unread_count || c.marked_unread);
        if (filter.value === 'groups') list = list.filter((c) => c.type === 'group');
        if (filter.value === 'channels') list = list.filter((c) => c.type === 'channel');
    }
    if (needle) list = list.filter((c) => (c.title || '').toLowerCase().includes(needle));

    return [...list].sort((a, b) => (b.is_pinned - a.is_pinned) || String(b.last_message_at || '').localeCompare(String(a.last_message_at || '')));
});

const unreadTotal = computed(() => conversations.value.filter((c) => ! c.is_archived && ! c.is_request && (c.unread_count || c.marked_unread)).length);
const theme = computed(() => active.value?.theme?.applied ?? null);

const wallpaperStyle = computed(() => {
    const th = theme.value;
    if (! th) return {};

    return {
        backgroundColor: th.background_color || undefined,
        backgroundImage: th.wallpaper ? `url(${th.wallpaper})` : 'none',
        backgroundSize: 'cover',
        backgroundPosition: 'center',
        backgroundAttachment: 'local',
    };
});

/** Messages with a date line between days, and "first of a run" flags for group names/tails. */
const lines = computed(() => {
    const out = [];
    let lastDay = null;
    let lastSender = null;
    messages.value.forEach((m) => {
        const day = m.created_at ? new Date(m.created_at).toDateString() : lastDay;
        if (day !== lastDay) {
            out.push({ key: `d-${day}`, day: dayLabel(m.created_at) });
            lastDay = day;
            lastSender = null;
        }
        const sender = m.system ? null : (m.sender?.key ?? null);
        out.push({ key: m.id, m, first: sender !== lastSender });
        lastSender = sender;
    });

    return out;
});

const subtitle = computed(() => {
    const c = active.value;
    if (! c) return '';
    const typer = typingIn(c.id);
    if (typer) return typer;
    if (c.type === 'channel') return t('messenger.followers', { count: c.group?.members_count ?? 0 });
    if (c.type === 'group') return t('messenger.members', { count: c.group?.members_count ?? 0 });
    const p = presence[c.peer?.key] ?? c.presence;
    if (p?.online) return t('messenger.online');
    if (p?.last_seen_at) return t('messenger.last_seen', { time: listTime(p.last_seen_at, true) });

    return c.peer?.phone || '';
});

const cannotSendText = computed(() => {
    const c = active.value;
    if (c?.i_blocked) return t('messenger.you_blocked');
    if (c?.type === 'group' && ! c.is_member) return t('messenger.not_member');
    if (c?.type === 'channel') return t('messenger.channel_readonly');
    if (c?.group?.only_admins_send) return t('messenger.only_admins');

    return t('messenger.cannot_send');
});

const canSend = computed(() => (draft.value.trim().length > 0 || pendingFiles.value.length > 0));

const visibleContacts = computed(() => {
    const needle = contactSearch.value.trim().toLowerCase();

    return contacts.value.filter((c) => c.is_registered && (! needle || (c.name || '').toLowerCase().includes(needle) || (c.phone || '').includes(needle)));
});

// ------------------------------------------------------------------ formatting
function dayLabel(iso) {
    if (! iso) return '';
    const d = new Date(iso);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);
    if (d.toDateString() === today.toDateString()) return t('messenger.today');
    if (d.toDateString() === yesterday.toDateString()) return t('messenger.yesterday');

    return d.toLocaleDateString(locale.value, { weekday: 'long', day: 'numeric', month: 'long', year: d.getFullYear() === today.getFullYear() ? undefined : 'numeric' });
}

function listTime(iso, withDay = false) {
    if (! iso) return '';
    const d = new Date(iso);
    const today = new Date();
    const time = d.toLocaleTimeString(locale.value, { hour: 'numeric', minute: '2-digit' });
    if (d.toDateString() === today.toDateString()) return time;
    const date = d.toLocaleDateString(locale.value, { day: 'numeric', month: 'short' });

    return withDay ? `${date} ${time}` : date;
}

function preview(c) {
    const m = c.last_message;
    if (! m) return '';
    if (m.is_deleted) return t('messenger.deleted');
    if (m.system) return t('messenger.system_event');
    const who = c.type === 'group' && ! m.is_mine && m.sender?.name ? `${m.sender.name}: ` : '';

    return who + (m.body || t(`messenger.types.${m.type}`, m.type));
}

const tickOf = (status) => ({ sent: 'ri-check-line', delivered: 'ri-check-double-line', read: 'ri-check-double-line read' }[status] || 'ri-check-line');

function typingIn(conversationId) {
    const entry = typing[conversationId];
    if (! entry) return '';
    const c = conversations.value.find((x) => x.id === conversationId);
    const name = c?.type === 'group' ? `${entry.name || ''} ` : '';

    return entry.state === 'recording' ? `${name}${t('messenger.recording')}` : `${name}${t('messenger.typing')}`;
}

const isImageAttachment = (a) => (a.mime_type || '').startsWith('image/');

// ------------------------------------------------------------------ list
async function loadConversations() {
    try {
        const { data } = await userAxios.get(`${API}/conversations`, { params: { per_page: 50 } });
        const requests = filter.value === 'requests'
            ? (await userAxios.get(`${API}/conversations`, { params: { filter: 'requests', per_page: 50 } })).data.data ?? []
            : [];
        const archived = (await userAxios.get(`${API}/conversations`, { params: { filter: 'archived', per_page: 50 } })).data.data ?? [];
        const byId = new Map();
        [...(data.data ?? []), ...archived, ...requests].forEach((c) => byId.set(c.id, c));
        conversations.value = [...byId.values()];
        requestsCount.value = data.requests_count ?? 0;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loadingList.value = false;
    }
}

function upsert(conversation) {
    const i = conversations.value.findIndex((c) => c.id === conversation.id);
    if (i >= 0) conversations.value[i] = { ...conversations.value[i], ...conversation };
    else conversations.value.unshift(conversation);
    if (active.value?.id === conversation.id) active.value = { ...active.value, ...conversation };
}

async function refreshConversation(id) {
    try {
        const { data } = await userAxios.get(`${API}/conversations/${id}`);
        upsert(data.data);
    } catch {
        // gone (deleted / left) — drop it
        conversations.value = conversations.value.filter((c) => c.id !== id);
        if (active.value?.id === id) close();
    }
}

watch(filter, async (value) => {
    if (value === 'requests') {
        const { data } = await userAxios.get(`${API}/conversations`, { params: { filter: 'requests', per_page: 50 } });
        (data.data ?? []).forEach(upsert);
    }
});

// ------------------------------------------------------------------ conversation
async function open(c) {
    if (active.value?.id === c.id) return;
    cancelContext();
    active.value = c;
    messages.value = [];
    searching.value = false;
    searchResults.value = null;
    newWhileAway.value = 0;
    loadingMessages.value = true;
    history.replaceState(null, '', `#${c.id}`);

    try {
        const [conv, page] = await Promise.all([
            userAxios.get(`${API}/conversations/${c.id}`),
            userAxios.get(`${API}/conversations/${c.id}/messages`, { params: { limit: 40 } }),
        ]);
        if (active.value?.id !== c.id) return;
        active.value = conv.data.data;
        upsert(conv.data.data);
        messages.value = page.data.data.messages ?? [];
        hasMoreBefore.value = !! page.data.data.has_more_before;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loadingMessages.value = false;
    }

    await nextTick();
    scrollToBottom(false);
    markRead();
    textarea.value?.focus();
}

function close() {
    active.value = null;
    history.replaceState(null, '', ' ');
}

async function loadOlder() {
    if (loadingOlder.value || ! hasMoreBefore.value || ! messages.value.length || ! active.value) return;
    loadingOlder.value = true;
    const el = scroller.value;
    const before = el.scrollHeight;
    try {
        const { data } = await userAxios.get(`${API}/conversations/${active.value.id}/messages`, { params: { before: messages.value[0].id, limit: 40 } });
        messages.value = [...(data.data.messages ?? []), ...messages.value];
        hasMoreBefore.value = !! data.data.has_more_before;
        await nextTick();
        el.scrollTop = el.scrollHeight - before + el.scrollTop;
    } finally {
        loadingOlder.value = false;
    }
}

function onScroll() {
    const el = scroller.value;
    if (! el) return;
    if (el.scrollTop < 120) loadOlder();
    const away = el.scrollHeight - el.scrollTop - el.clientHeight > 240;
    showJump.value = away;
    if (! away) newWhileAway.value = 0;
}

function scrollToBottom(smooth = true) {
    const el = scroller.value;
    if (! el) return;
    el.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
    newWhileAway.value = 0;
}

function isNearBottom() {
    const el = scroller.value;

    return ! el || el.scrollHeight - el.scrollTop - el.clientHeight < 160;
}

async function jumpTo(id) {
    if (! id || ! active.value) return;
    searching.value = false;
    if (! messages.value.some((m) => m.id === id)) {
        const { data } = await userAxios.get(`${API}/conversations/${active.value.id}/messages`, { params: { around: id, limit: 50 } });
        messages.value = data.data.messages ?? [];
        hasMoreBefore.value = !! data.data.has_more_before;
        await nextTick();
    }
    document.getElementById(`m-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    highlight.value = id;
    setTimeout(() => { if (highlight.value === id) highlight.value = null; }, 1800);
}

function markRead() {
    clearTimeout(readTimer);
    readTimer = setTimeout(async () => {
        const c = active.value;
        if (! c || c.is_request || document.hidden) return;
        try {
            await userAxios.post(`${API}/conversations/${c.id}/read`, {});
            upsert({ id: c.id, unread_count: 0, marked_unread: false, has_unread_mention: false });
        } catch { /* next time */ }
    }, 300);
}

async function acceptRequest() {
    const { data } = await userAxios.post(`${API}/conversations/${active.value.id}/accept`);
    upsert(data.data);
    requestsCount.value = Math.max(0, requestsCount.value - 1);
}

async function rejectRequest() {
    await userAxios.post(`${API}/conversations/${active.value.id}/reject`, {});
    conversations.value = conversations.value.filter((c) => c.id !== active.value.id);
    requestsCount.value = Math.max(0, requestsCount.value - 1);
    close();
}

// ------------------------------------------------------------------ search
async function toggleSearch() {
    searching.value = ! searching.value;
    searchResults.value = null;
    searchQuery.value = '';
    if (searching.value) {
        await nextTick();
        searchInput.value?.focus();
    }
}

async function runSearch() {
    const q = searchQuery.value.trim();
    if (q.length < 2) return;
    const { data } = await userAxios.get(`${API}/conversations/${active.value.id}/messages/search`, { params: { q } });
    searchResults.value = data.data?.messages ?? data.data ?? [];
}

// ------------------------------------------------------------------ composing
function autoGrow() {
    const el = textarea.value;
    if (! el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
}

function onType() {
    autoGrow();
    const c = active.value;
    if (! c || editing.value) return;
    const now = Date.now();
    if (now - typingSentAt > 4000) {
        typingSentAt = now;
        userAxios.post(`${API}/conversations/${c.id}/typing`, { state: 'typing' }).catch(() => {});
    }
    clearTimeout(typingStopTimer);
    typingStopTimer = setTimeout(stopTyping, 5000);
}

function stopTyping() {
    if (! typingSentAt || ! active.value) return;
    typingSentAt = 0;
    userAxios.post(`${API}/conversations/${active.value.id}/typing`, { state: 'stopped' }).catch(() => {});
}

function insertEmoji(e) {
    draft.value += e;
    emojiOpen.value = false;
    textarea.value?.focus();
}

function startReply(m) {
    editing.value = null;
    replyTo.value = m;
    textarea.value?.focus();
}

function startEdit(m) {
    replyTo.value = null;
    editing.value = m;
    draft.value = m.body || '';
    nextTick(() => { autoGrow(); textarea.value?.focus(); });
}

function cancelContext() {
    if (editing.value) draft.value = '';
    replyTo.value = null;
    editing.value = null;
    emojiOpen.value = false;
}

function addFiles(list) {
    Array.from(list || []).slice(0, 30).forEach((file) => {
        pendingFiles.value.push({ file, preview: file.type.startsWith('image/') ? URL.createObjectURL(file) : null });
    });
}

function pickFiles(event) {
    addFiles(event.target.files);
    event.target.value = '';
}

function onDrop(event) {
    addFiles(event.dataTransfer?.files);
}

function onPaste(event) {
    const files = Array.from(event.clipboardData?.files || []);
    if (files.length) {
        event.preventDefault();
        addFiles(files);
    }
}

function dropFile(i) {
    const [f] = pendingFiles.value.splice(i, 1);
    if (f?.preview) URL.revokeObjectURL(f.preview);
}

const kindOf = (file) => {
    if (file.type.startsWith('image/')) return 'image';
    if (file.type.startsWith('video/')) return 'video';
    if (file.type.startsWith('audio/')) return 'audio';

    return 'document';
};

async function send() {
    if (! canSend.value || ! active.value) return;
    const c = active.value;
    const body = draft.value.trim();

    if (editing.value) {
        const target = editing.value;
        cancelContext();
        draft.value = '';
        try {
            const { data } = await userAxios.patch(`${API}/messages/${target.id}`, { body });
            replaceMessage(data.data);
        } catch (error) {
            showError(extractApiErrorMessage(error));
        }

        return;
    }

    const reply = replyTo.value;
    const files = pendingFiles.value.splice(0);
    draft.value = '';
    replyTo.value = null;
    nextTick(autoGrow);
    stopTyping();

    // Pictures go together as one album; any other file is its own message. The text rides on the
    // first message as its caption.
    const groups = [];
    const images = files.filter((f) => kindOf(f.file) === 'image');
    if (images.length) groups.push({ type: 'image', files: images });
    files.filter((f) => kindOf(f.file) !== 'image').forEach((f) => groups.push({ type: kindOf(f.file), files: [f] }));
    if (! groups.length) groups.push({ type: 'text', files: [] });

    for (const [i, g] of groups.entries()) {
        await sendOne(c, g.type, i === 0 ? body : '', g.files, i === 0 ? reply : null);
    }
}

async function sendOne(c, type, body, files, reply) {
    const uuid = crypto.randomUUID();
    const local = {
        id: uuid, conversation_id: c.id, type, body: body || null, meta: null,
        attachments: files.map((f, i) => ({ id: `${uuid}-${i}`, url: f.preview || '', name: f.file.name, mime_type: f.file.type, size: f.file.size })),
        sender: { key: myKey.value, is_me: true }, is_mine: true, status: 'sent',
        reply_to: reply ? { id: reply.id, type: reply.type, body: reply.body, sender: reply.sender } : null,
        reactions: { summary: [], mine: null, total: 0 }, created_at: new Date().toISOString(),
        _local: 'pending', _fresh: true, _files: files, _reply: reply, _progress: files.length ? 0 : null,
    };
    messages.value.push(local);
    await nextTick();
    scrollToBottom();

    const form = new FormData();
    form.append('type', type);
    form.append('uuid', uuid);
    if (body) form.append('body', body);
    if (reply) form.append('reply_to', reply.id);
    files.forEach((f) => form.append('files[]', f.file));

    try {
        const { data } = await userAxios.post(`${API}/conversations/${c.id}/messages`, form, {
            onUploadProgress: (e) => {
                if (files.length && e.total) {
                    const row = messages.value.find((m) => m.id === uuid);
                    if (row) row._progress = Math.round((e.loaded / e.total) * 100);
                }
            },
        });
        files.forEach((f) => f.preview && URL.revokeObjectURL(f.preview));
        replaceMessage({ ...data.data, _fresh: true });
        upsert({ id: c.id, last_message: data.data, last_message_at: data.data.created_at, is_request: false });
    } catch (error) {
        const row = messages.value.find((m) => m.id === uuid);
        if (row) { row._local = 'failed'; row._progress = null; }
        showError(extractApiErrorMessage(error));
    }
}

function retry(m) {
    messages.value = messages.value.filter((x) => x.id !== m.id);
    sendOne(active.value, m.type, m.body, m._files || [], m._reply);
}

function replaceMessage(message) {
    const i = messages.value.findIndex((m) => m.id === message.id);
    if (i >= 0) messages.value[i] = { ...message, _fresh: messages.value[i]._fresh };
}

async function react(m, emoji) {
    if (emoji === null && m.reactions?.mine == null) return;
    try {
        await userAxios.put(`${API}/messages/${m.id}/reaction`, { emoji });
        const summary = [...(m.reactions?.summary ?? [])];
        const bump = (e, d) => {
            const row = summary.find((r) => r.emoji === e);
            if (row) row.count += d; else if (d > 0) summary.push({ emoji: e, count: 1 });
        };
        if (m.reactions?.mine) bump(m.reactions.mine, -1);
        if (emoji) bump(emoji, 1);
        const clean = summary.filter((r) => r.count > 0).sort((a, b) => b.count - a.count);
        m.reactions = { summary: clean, mine: emoji, total: clean.reduce((n, r) => n + r.count, 0) };
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function remove(m) {
    if (! window.confirm(t('messenger.confirm_delete'))) return;
    try {
        const { data } = await userAxios.delete(`${API}/messages/${m.id}`);
        replaceMessage(data.data);
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

function openViewer({ message, index }) {
    const items = (message.attachments || []).filter((a) => a.url);
    viewer.value = { items, index };
}

/** Tick an option (single choice replaces, multiple toggles); the server's totals win. */
async function vote(m, optionId) {
    const poll = m.poll;
    if (! poll) return;
    const picked = poll.my_votes.includes(optionId)
        ? poll.my_votes.filter((id) => id !== optionId)
        : (poll.multiple ? [...poll.my_votes, optionId] : [optionId]);
    try {
        const { data } = await userAxios.put(`${API}/messages/${m.id}/vote`, { options: picked });
        m.poll = data.data.poll;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

/** A view-once photo / video / voice note: its files come back this one time only. */
async function openViewOnce(m) {
    try {
        const { data } = await userAxios.post(`${API}/messages/${m.id}/open`);
        m.view_once_opened = true;
        const items = (data.data.attachments || []).filter((a) => a.url);
        if (items.length) viewer.value = { items, index: 0, once: true };
    } catch (error) {
        if (error?.response?.status === 410) m.view_once_opened = true;
        showError(extractApiErrorMessage(error));
    }
}

async function stopLive(m) {
    try {
        const { data } = await userAxios.post(`${API}/messages/${m.id}/live-location/stop`);
        replaceMessage({ ...data.data, is_mine: true, status: m.status });
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

// ------------------------------------------------------------------ new chat
async function openNewChat() {
    newChatOpen.value = true;
    lookupError.value = '';
    if (contacts.value.length) return;
    loadingContacts.value = true;
    try {
        const { data } = await userAxios.get(`${API}/contacts`, { params: { registered: 1 } });
        contacts.value = data.data ?? [];
    } finally {
        loadingContacts.value = false;
    }
}

async function lookupPhone() {
    lookingUp.value = true;
    lookupError.value = '';
    try {
        const { data } = await userAxios.post(`${API}/contacts/lookup`, { phone: phone.value.trim() });
        await startWith(data.data);
    } catch (error) {
        lookupError.value = extractApiErrorMessage(error);
    } finally {
        lookingUp.value = false;
    }
}

async function startWith(profile) {
    if (! profile) return;
    try {
        const { data } = await userAxios.post(`${API}/conversations/direct`, { participant_type: profile.type || 'user', participant_id: profile.id });
        newChatOpen.value = false;
        phone.value = '';
        upsert(data.data);
        open(data.data);
    } catch (error) {
        lookupError.value = extractApiErrorMessage(error);
    }
}

// ------------------------------------------------------------------ realtime
function onEvent(name, p) {
    const cid = p.conversation_id;

    switch (name) {
    case 'chat.message.sent': {
        const m = { ...p.message, is_mine: p.message?.sender?.key === myKey.value, _fresh: true };
        if (m.is_mine && m.status == null) m.status = 'sent';
        const known = conversations.value.find((c) => c.id === cid);
        if (! known) { refreshConversation(cid); break; }
        const isActive = active.value?.id === cid;
        upsert({
            id: cid,
            last_message: { ...m, body: m.body },
            last_message_at: m.created_at,
            unread_count: isActive || m.is_mine ? known.unread_count : (known.unread_count || 0) + 1,
        });
        delete typing[cid];
        if (isActive && ! messages.value.some((x) => x.id === m.id)) {
            const near = isNearBottom();
            messages.value.push(m);
            nextTick(() => (near || m.is_mine ? scrollToBottom() : newWhileAway.value++));
            if (! m.is_mine) markRead();
        }
        break;
    }
    case 'chat.message.updated':
    case 'chat.message.deleted': {
        // Money cards are drawn per viewer (my share…): read my own version of this one.
        if (name === 'chat.message.updated' && p.message?.payment && active.value?.id === cid) {
            userAxios.get(`${API}/conversations/${cid}/messages`, { params: { around: p.message.id, limit: 1 } })
                .then(({ data }) => { const fresh = (data.data.messages || []).find((x) => x.id === p.message.id); if (fresh) replaceMessage(fresh); })
                .catch(() => {});
            break;
        }
        if (active.value?.id === cid) {
            const old = messages.value.find((x) => x.id === p.message?.id);
            if (old) replaceMessage({ ...p.message, is_mine: old.is_mine, status: old.status });
        }
        const conv = conversations.value.find((c) => c.id === cid);
        if (conv?.last_message?.id === p.message?.id) refreshConversation(cid);
        break;
    }
    case 'chat.receipt': {
        if (active.value?.id !== cid || p.participant === myKey.value) break;
        const apply = (upTo, status) => {
            const idx = messages.value.findIndex((m) => m.id === upTo);
            if (idx < 0) return;
            for (let i = 0; i <= idx; i++) {
                const m = messages.value[i];
                if (m.is_mine && m.status !== 'read' && (status === 'read' || m.status === 'sent')) m.status = status;
            }
        };
        if (active.value.type !== 'group') {
            if (p.delivered_up_to) apply(p.delivered_up_to, 'delivered');
            if (p.read_up_to) apply(p.read_up_to, 'read');
        }
        break;
    }
    case 'chat.reaction': {
        if (active.value?.id !== cid) break;
        const m = messages.value.find((x) => x.id === p.message_id);
        if (m) {
            const summary = p.summary ?? [];
            m.reactions = { summary, mine: p.participant === myKey.value ? p.emoji : m.reactions?.mine, total: summary.reduce((n, r) => n + r.count, 0) };
        }
        break;
    }
    case 'chat.typing': {
        if (p.participant === myKey.value) break;
        if (p.state === 'stopped') { delete typing[cid]; break; }
        typing[cid] = { state: p.state, name: messages.value.find((m) => m.sender?.key === p.participant)?.sender?.name || '', at: Date.now() };
        setTimeout(() => { if (typing[cid] && Date.now() - typing[cid].at > 5500) delete typing[cid]; }, 6000);
        break;
    }
    case 'chat.presence':
        presence[p.participant] = { online: p.online, last_seen_at: p.last_seen_at };
        break;
    case 'chat.poll.updated': {
        const m = active.value?.id === cid && messages.value.find((x) => x.id === p.message_id);
        if (m?.poll) {
            m.poll = {
                ...m.poll,
                options: m.poll.options.map((o) => ({ ...o, votes: p.counts?.[o.id] ?? 0 })),
                my_votes: p.participant === myKey.value ? (p.option_ids ?? []) : m.poll.my_votes,
                voters: p.voters ?? m.poll.voters,
            };
        }
        break;
    }
    case 'chat.view_once.opened': {
        const m = active.value?.id === cid && messages.value.find((x) => x.id === p.message_id);
        if (m && (m.is_mine || p.participant === myKey.value)) m.view_once_opened = true;
        break;
    }
    case 'chat.location.moved': {
        const m = active.value?.id === cid && messages.value.find((x) => x.id === p.message_id);
        if (m) {
            m.meta = { ...(m.meta || {}), latitude: p.latitude, longitude: p.longitude };
            if (m.live_location) m.live_location = { ...m.live_location, updated_at: p.updated_at };
        }
        break;
    }
    case 'chat.group.join_requests':
    case 'chat.group.join_decided':
        if (cid) refreshConversation(cid);
        break;
    case 'chat.conversation.updated':
    case 'chat.pins.updated':
        if (cid) refreshConversation(cid);
        break;
    default:
        break;
    }
}

function setPresence(online) {
    userAxios.post(`${API}/presence`, { online }).catch(() => {});
}

function onVisibility() {
    setPresence(! document.hidden);
    if (! document.hidden) markRead();
}

// ------------------------------------------------------------------ lifecycle
onMounted(async () => {
    me.value = auth.user;
    if (! me.value?.id) {
        try {
            const { data } = await userAxios.get('/api/user/v1/me');
            me.value = data.data?.user ?? data.data;
        } catch { /* the page still works without realtime */ }
    }

    await loadConversations();
    if (me.value?.id) realtime.connect(me.value.id, onEvent);

    // Deep link: /user/messages#<conversation uuid>
    const hash = window.location.hash.slice(1);
    const linked = hash && conversations.value.find((c) => c.id === hash);
    if (linked) open(linked);

    userAxios.post(`${API}/conversations/delivered`).catch(() => {});
    setPresence(true);
    presenceTimer = setInterval(() => setPresence(! document.hidden), 90_000);
    document.addEventListener('visibilitychange', onVisibility);
});

onBeforeUnmount(() => {
    realtime.disconnect();
    clearInterval(presenceTimer);
    clearTimeout(typingStopTimer);
    clearTimeout(readTimer);
    document.removeEventListener('visibilitychange', onVisibility);
    setPresence(false);
    pendingFiles.value.forEach((f) => f.preview && URL.revokeObjectURL(f.preview));
});
</script>
