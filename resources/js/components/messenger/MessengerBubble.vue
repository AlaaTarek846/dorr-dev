<template>
    <div v-if="message.system" class="mx-system">
        <span>{{ message.system.text }}</span>
    </div>

    <div v-else class="mx-row" :class="{ mine: message.is_mine, first: first, fresh: message._fresh }">
        <div class="mx-bubble" :class="{ card: isCard, bare: message.type === 'sticker' && !message.is_deleted, deleted: message.is_deleted, pending: message._local === 'pending', failed: message._local === 'failed' }" :style="bubbleStyle">
            <div v-if="showSender" class="mx-sender" :style="{ color: nameColor }">{{ message.sender?.name }}</div>

            <div v-if="message.is_forwarded" class="mx-forwarded"><i class="ri-share-forward-line"></i> {{ t(message.forwarded_many_times ? 'messenger.forwarded_many' : 'messenger.forwarded') }}</div>

            <button v-if="message.reply_to" type="button" class="mx-quote" @click="$emit('jump', message.reply_to.id)">
                <span class="mx-quote-name">{{ message.reply_to.is_deleted ? t('messenger.deleted') : (message.reply_to.sender?.is_me ? t('messenger.you') : message.reply_to.sender?.name) }}</span>
                <span class="mx-quote-body">{{ message.reply_to.body || typeLabel(message.reply_to.type) }}</span>
            </button>

            <!-- deleted -->
            <div v-if="message.is_deleted" class="mx-text fst-italic opacity-75"><i class="ri-forbid-line me-1"></i>{{ t(message.is_mine ? 'messenger.you_deleted' : 'messenger.deleted') }}</div>

            <!-- view once: never a preview, opened (once) on demand -->
            <button v-else-if="message.view_once" type="button" class="mx-once" :disabled="message.is_mine || message.view_once_opened || !!message._local" @click="$emit('open', message)">
                <span class="mx-once-badge" :class="{ opened: message.view_once_opened }">
                    <i v-if="message.view_once_opened" class="ri-eye-line"></i><template v-else>1</template>
                </span>
                <span>
                    <span class="fw-bold d-block">{{ message.view_once_opened ? t('messenger.once_opened') : t(`messenger.once_${message.type}`, t('messenger.once_image')) }}</span>
                    <span v-if="!message.is_mine && !message.view_once_opened" class="fs-11 opacity-75">{{ t('messenger.once_tap') }}</span>
                </span>
            </button>

            <template v-else>
                <!-- link card -->
                <a v-if="message.link_preview" :href="message.link_preview.url" target="_blank" rel="noopener noreferrer" class="mx-link">
                    <img v-if="message.link_preview.image" :src="message.link_preview.image" alt="" loading="lazy">
                    <span class="mx-link-body">
                        <span class="mx-link-site"><i class="ri-global-line"></i> {{ message.link_preview.site_name }}</span>
                        <span v-if="message.link_preview.title" class="mx-link-title">{{ message.link_preview.title }}</span>
                        <span v-if="message.link_preview.description" class="mx-link-desc">{{ message.link_preview.description }}</span>
                    </span>
                </a>

                <!-- sticker / GIF -->
                <img v-if="message.type === 'sticker' && message.meta?.url" :src="message.meta.url" :alt="message.meta.emoji || message.meta.title || ''" class="mx-sticker" loading="lazy">
                <div v-if="message.type === 'gif' && message.meta?.url" class="mx-gif">
                    <img :src="message.meta.webp || message.meta.url" :alt="message.meta.title || 'GIF'" loading="lazy">
                    <span>GIF</span>
                </div>

                <!-- money request / bill split (paid from the app, with the wallet PIN) -->
                <div v-if="message.payment" class="mx-money" :class="message.payment.kind">
                    <div class="mx-money-head">
                        <i :class="message.payment.kind === 'split' ? 'ri-git-branch-line' : 'ri-hand-coin-line'"></i>
                        <span class="flex-grow-1">{{ t(message.payment.kind === 'split' ? 'messenger.split_title' : (message.payment.is_requester ? 'messenger.money_you_requested' : 'messenger.money_requested')) }}</span>
                        <span class="mx-money-status" :class="message.payment.status">{{ t(`messenger.money_status.${message.payment.status}`) }}</span>
                    </div>
                    <div class="mx-money-amount" dir="ltr">{{ money(message.payment.kind === 'split' ? message.payment.total_minor : message.payment.amount_minor, message.payment.currency_symbol || message.payment.currency, message.payment.decimal_places) }}</div>
                    <div v-if="message.body" class="mx-money-note">{{ message.body }}</div>
                    <template v-if="message.payment.kind === 'split'">
                        <div class="mx-money-bar"><span :style="{ width: `${message.payment.total_minor ? Math.round(message.payment.paid_minor / message.payment.total_minor * 100) : 0}%` }"></span></div>
                        <div v-for="(s, i) in message.payment.shares" :key="i" class="mx-money-share">
                            <span class="flex-grow-1 text-truncate">{{ s.profile?.is_me ? t('messenger.you') : s.profile?.name }}</span>
                            <span dir="ltr">{{ money(s.amount_minor, null, message.payment.decimal_places) }}</span>
                            <i :class="{ paid: 'ri-checkbox-circle-fill text-success', owner: 'ri-star-fill text-warning', declined: 'ri-close-circle-fill text-danger' }[s.status] || 'ri-time-line opacity-50'"></i>
                        </div>
                    </template>
                    <div v-if="message.payment.can_pay" class="mx-money-hint"><i class="ri-smartphone-line"></i> {{ t('messenger.money_pay_in_app') }}</div>
                </div>

                <!-- poll -->
                <div v-if="message.poll" class="mx-poll">
                    <div class="mx-poll-kind"><i class="ri-bar-chart-horizontal-line"></i> {{ t(message.poll.multiple ? 'messenger.poll_many' : 'messenger.poll_one') }}</div>
                    <div class="mx-poll-q">{{ message.poll.question }}</div>
                    <button v-for="o in message.poll.options" :key="o.id" type="button" class="mx-poll-option" :class="{ on: message.poll.my_votes.includes(o.id) }" :disabled="!!message._local" @click="$emit('vote', message, o.id)">
                        <span class="mx-poll-row">
                            <i :class="message.poll.my_votes.includes(o.id) ? 'ri-checkbox-circle-fill' : (message.poll.multiple ? 'ri-checkbox-blank-line' : 'ri-checkbox-blank-circle-line')"></i>
                            <span class="flex-grow-1 text-start">{{ o.text }}</span>
                            <span class="fs-12 opacity-75">{{ o.votes }}</span>
                        </span>
                        <span class="mx-poll-bar"><span :style="{ width: `${pollShare(o)}%` }"></span></span>
                    </button>
                    <div class="mx-poll-total">{{ message.poll.voters ? t('messenger.poll_voters', { count: message.poll.voters }) : t('messenger.poll_none') }}</div>
                </div>

                <!-- images / videos -->
                <div v-if="visual.length" class="mx-media" :class="`n${Math.min(visual.length, 4)}`">
                    <a v-for="(a, i) in visual.slice(0, 4)" :key="a.id || i" :href="a.url" target="_blank" rel="noopener" class="mx-media-item" @click.prevent="$emit('view', { message, index: i })">
                        <img v-if="isImage(a)" :src="a.url" :alt="a.name || ''" loading="lazy">
                        <template v-else>
                            <img v-if="a.thumbnail" :src="a.thumbnail" alt="" loading="lazy">
                            <video v-else :src="a.url" preload="metadata" muted></video>
                            <span class="mx-play"><i class="ri-play-fill"></i></span>
                            <span v-if="a.duration_ms" class="mx-duration">{{ duration(a.duration_ms) }}</span>
                        </template>
                        <span v-if="i === 3 && visual.length > 4" class="mx-more">+{{ visual.length - 4 }}</span>
                    </a>
                    <div v-if="message._progress != null" class="mx-progress"><span :style="{ width: `${message._progress}%` }"></span></div>
                </div>

                <!-- voice / audio -->
                <div v-for="a in audios" :key="a.id" class="mx-audio">
                    <i :class="message.type === 'voice' ? 'ri-mic-fill' : 'ri-music-2-fill'"></i>
                    <audio :src="a.url" controls preload="none"></audio>
                </div>

                <!-- documents -->
                <a v-for="a in documents" :key="a.id" :href="a.url" target="_blank" rel="noopener" class="mx-file">
                    <span class="mx-file-icon">{{ extension(a.name) }}</span>
                    <span class="mx-file-meta">
                        <span class="mx-file-name">{{ a.name }}</span>
                        <span class="mx-file-size">{{ fileSize(a.size) }}</span>
                    </span>
                    <i class="ri-download-2-line"></i>
                </a>

                <!-- location -->
                <a v-if="message.type === 'location' && message.meta" :href="`https://maps.google.com/?q=${message.meta.latitude},${message.meta.longitude}`" target="_blank" rel="noopener" class="mx-location" :class="{ live: liveActive }">
                    <i :class="liveActive ? 'ri-live-line' : 'ri-map-pin-2-fill'"></i>
                    <span>
                        <span class="d-block">{{ message.meta.name || message.meta.address || t(message.live_location ? 'messenger.live_location' : 'messenger.location') }}</span>
                        <span v-if="message.live_location" class="fs-11 opacity-75">{{ liveActive ? t('messenger.live_until', { time: liveUntil }) : t('messenger.live_ended') }}</span>
                    </span>
                </a>
                <button v-if="message.is_mine && liveActive" type="button" class="mx-live-stop" @click="$emit('stop-live', message)"><i class="ri-stop-circle-line"></i> {{ t('messenger.live_stop') }}</button>

                <!-- contact -->
                <div v-if="message.type === 'contact' && message.meta" class="mx-contact">
                    <span class="mx-avatar sm">{{ initials(message.meta.name) }}</span>
                    <div>
                        <div class="fw-semibold">{{ message.meta.name }}</div>
                        <div class="fs-12 opacity-75" dir="ltr">{{ (message.meta.phones || []).join(', ') }}</div>
                    </div>
                </div>

                <!-- wallet cards -->
                <div v-if="message.type === 'wallet_transfer' && message.meta" class="mx-wallet">
                    <div class="fs-11 opacity-75">{{ t('messenger.transfer_receipt') }}</div>
                    <div class="mx-wallet-amount">{{ money(message.meta.amount_minor, message.meta.currency_symbol || message.meta.currency, message.meta.decimal_places) }}</div>
                    <div v-if="message.meta.recipient_name" class="fs-12">{{ t('messenger.transfer_to', { name: message.meta.recipient_name }) }}</div>
                </div>
                <div v-if="message.type === 'wallet_qr' && message.meta" class="mx-wallet qr">
                    <i class="ri-qr-code-line fs-3"></i>
                    <div>
                        <div class="fw-semibold">{{ t('messenger.wallet_qr') }}</div>
                        <div class="fs-12 opacity-75">{{ message.meta.wallet_number }}</div>
                    </div>
                </div>

                <!-- call line -->
                <div v-if="message.type === 'call'" class="mx-text"><i :class="message.meta?.call_type === 'video' ? 'ri-vidicon-line' : 'ri-phone-line'" class="me-1"></i>{{ callLabel }}</div>

                <!-- text / caption -->
                <div v-if="message.body && !['poll', 'money_request', 'bill_split'].includes(message.type)" class="mx-text" dir="auto" v-html="formatted"></div>
            </template>

            <span class="mx-meta">
                <span v-if="message.is_edited && !message.is_deleted" class="me-1">{{ t('messenger.edited') }}</span>
                <i v-if="message.is_starred" class="ri-star-fill me-1"></i>
                <span v-if="message.views != null" class="me-1"><i class="ri-eye-line"></i> {{ message.views }}</span>
                {{ time }}
                <i v-if="message.is_mine && !message.is_deleted" class="mx-tick" :class="tickClass"></i>
            </span>

            <div v-if="message.reactions?.total" class="mx-reactions" @click="$emit('react', message, null)">
                <span v-for="r in message.reactions.summary.slice(0, 3)" :key="r.emoji">{{ r.emoji }}</span>
                <span v-if="message.reactions.total > 1" class="ms-1">{{ message.reactions.total }}</span>
            </div>
        </div>

        <!-- hover actions -->
        <div v-if="!message.is_deleted && !message._local" class="mx-actions">
            <div class="mx-quick">
                <button v-for="e in quick" :key="e" type="button" :class="{ on: message.reactions?.mine === e }" @click="$emit('react', message, message.reactions?.mine === e ? null : e)">{{ e }}</button>
            </div>
            <button type="button" :title="t('messenger.reply')" @click="$emit('reply', message)"><i class="ri-reply-line"></i></button>
            <button v-if="message.body" type="button" :title="t('messenger.copy')" @click="copy"><i class="ri-file-copy-line"></i></button>
            <button v-if="message.is_mine && message.type === 'text'" type="button" :title="t('messenger.edit')" @click="$emit('edit', message)"><i class="ri-pencil-line"></i></button>
            <button v-if="message.is_mine" type="button" class="danger" :title="t('messenger.delete')" @click="$emit('delete', message)"><i class="ri-delete-bin-line"></i></button>
        </div>
        <button v-if="message._local === 'failed'" type="button" class="mx-retry" @click="$emit('retry', message)"><i class="ri-refresh-line"></i> {{ t('messenger.retry') }}</button>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    message: { type: Object, required: true },
    first: { type: Boolean, default: false },
    isGroup: { type: Boolean, default: false },
    theme: { type: Object, default: null },
});
defineEmits(['reply', 'react', 'edit', 'delete', 'retry', 'jump', 'view', 'vote', 'open', 'stop-live']);

const { t, locale } = useI18n();
const quick = ['👍', '❤️', '😂', '😮', '😢', '🙏'];
const palette = ['#E11D48', '#7C3AED', '#0891B2', '#059669', '#D97706', '#2563EB', '#DB2777', '#65A30D'];

const attachments = computed(() => props.message.attachments ?? []);
const isImage = (a) => (a.mime_type || '').startsWith('image/') || props.message.type === 'image';
const visual = computed(() => (['image', 'video'].includes(props.message.type) ? attachments.value : []));
const audios = computed(() => (['audio', 'voice'].includes(props.message.type) ? attachments.value : []));
const documents = computed(() => (props.message.type === 'document' ? attachments.value : []));

/** An option's share of all ticks, for its bar. */
function pollShare(option) {
    const total = (props.message.poll?.options ?? []).reduce((n, o) => n + o.votes, 0);

    return total ? Math.round((option.votes / total) * 100) : 0;
}

const liveActive = computed(() => {
    const live = props.message.live_location;

    return !! live?.active && new Date(live.live_until) > new Date();
});
const liveUntil = computed(() => (props.message.live_location?.live_until
    ? new Date(props.message.live_location.live_until).toLocaleTimeString(locale.value, { hour: 'numeric', minute: '2-digit' })
    : ''));
const isCard = computed(() => ['wallet_transfer', 'wallet_qr'].includes(props.message.type));
const showSender = computed(() => props.isGroup && props.first && ! props.message.is_mine);

const nameColor = computed(() => {
    const key = props.message.sender?.key || '';
    let hash = 0;
    for (const ch of key) hash = (hash * 31 + ch.charCodeAt(0)) | 0;

    return palette[Math.abs(hash) % palette.length];
});

/** Admin theme colours for the bubbles; text flips dark on light colours. */
const bubbleStyle = computed(() => {
    const color = props.theme ? (props.message.is_mine ? props.theme.sender_color : props.theme.receiver_color) : null;

    return color ? { background: color, color: isLight(color) ? '#111928' : '#fff' } : {};
});

function isLight(hex) {
    const m = /^#?([0-9a-f]{6})/i.exec(hex || '');
    if (! m) return true;
    const n = parseInt(m[1], 16);

    return (0.299 * (n >> 16) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255)) / 255 > 0.6;
}

const time = computed(() => (props.message.created_at
    ? new Date(props.message.created_at).toLocaleTimeString(locale.value, { hour: 'numeric', minute: '2-digit' })
    : ''));

const tickClass = computed(() => ({
    pending: 'ri-time-line',
    sent: 'ri-check-line',
    delivered: 'ri-check-double-line',
    read: 'ri-check-double-line read',
}[props.message._local === 'pending' ? 'pending' : (props.message.status || 'sent')]));

const callLabel = computed(() => {
    const m = props.message.meta || {};
    const seconds = m.duration_seconds;

    return seconds ? `${t(m.call_type === 'video' ? 'messenger.video_call' : 'messenger.voice_call')} · ${duration(seconds * 1000)}` : t('messenger.missed_call');
});

/**
 * WhatsApp-style marks on already-escaped text: *bold*, _italic_, ~strike~, `code`, ```mono```.
 * A mark only counts at a word edge and around non-blank text (so 5*3*2 and snake_case stay as
 * typed); nothing is formatted inside code. Same rules as the app and the push text.
 */
function formatMarks(html) {
    const codes = [];
    const keep = (inner, block) => {
        codes.push(block ? `<code class="mx-code mx-code-block">${inner}</code>` : `<code class="mx-code">${inner}</code>`);
        return `\u0000${codes.length - 1}\u0000`;
    };
    html = html.replace(/```([\s\S]+?)```/g, (_, inner) => keep(inner, true));
    html = html.replace(/(?<=^|[\s\p{P}])`(?=\S)([^`\n]*?\S)`(?=$|[\s\p{P}])/gu, (_, inner) => keep(inner, false));
    for (const [mark, tag] of [['*', 'strong'], ['_', 'em'], ['~', 's']]) {
        // Only * needs escaping; `u` regexes reject needless escapes such as \~.
        const m = mark === '*' ? '\\*' : mark;
        const re = new RegExp(`(?<=^|[\\s\\p{P}])${m}(?=\\S)([^${m}\\n]*?\\S)${m}(?=$|[\\s\\p{P}])`, 'gu');
        html = html.replace(re, `<${tag}>$1</${tag}>`);
    }

    return html.replace(/\u0000(\d+)\u0000/g, (_, i) => codes[Number(i)]);
}

/** Escape, format, linkify URLs (never formatted inside) and highlight @mentions — safe to v-html. */
const formatted = computed(() => {
    const escape = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let html = (props.message.body || '').split(/(https?:\/\/[^\s<]+)/g)
        .map((part, i) => (i % 2 === 1
            ? `<a href="${escape(part)}" target="_blank" rel="noopener noreferrer">${escape(part)}</a>`
            : formatMarks(escape(part))))
        .join('');
    (props.message.mentions || []).forEach((p) => {
        if (p?.name) {
            html = html.split(`@${escape(p.name)}`).join(`<span class="mx-mention">@${escape(p.name)}</span>`);
        }
    });

    return html.replace(/\n/g, '<br>');
});

function typeLabel(type) {
    return t(`messenger.types.${type}`, type || '');
}

function duration(ms) {
    const s = Math.round(ms / 1000);

    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

function fileSize(bytes) {
    if (! bytes) return '';
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let v = bytes;
    while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }

    return `${v.toFixed(i ? 1 : 0)} ${units[i]}`;
}

const extension = (name) => (name?.split('.').pop() || 'file').slice(0, 4).toUpperCase();
const initials = (name) => (name || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase();

function money(minor, currency, places = 2) {
    if (minor == null) return '';
    const digits = Number(places ?? 2);

    return `${(Number(minor) / 10 ** digits).toLocaleString(locale.value, { minimumFractionDigits: digits })} ${currency || ''}`;
}

function copy() {
    navigator.clipboard?.writeText(props.message.body || '');
}
</script>
