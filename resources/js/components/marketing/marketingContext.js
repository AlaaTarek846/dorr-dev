import { inject, reactive } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import useToast from '../../composables/useToast';

/**
 * AI Marketing Director (docs/modules/marketing/API.md) — the context the shared pages read. Each SPA builds it once in its
 * thin wrapper page (user: userAxios + /api/user/v1/marketing; provider: providerAxios + /api/provider/v1/marketing) and the
 * shared pages under components/marketing/ never know which SPA they run in.
 *
 * `changes` is bumped by every `marketing.job.changed` push and by a realtime reconnect (the catch-up), so the open page
 * reloads once — never on a timer (AGENTS.md, no polling).
 */
const KEY = Symbol('marketing');

export function createMarketingContext(config) {
    const state = reactive({
        status: null,
        statusLoading: false,
        statusError: false,
        changes: 0,
        changedProject: null,
    });

    async function loadStatus() {
        state.statusLoading = true;
        state.statusError = false;
        try {
            const { data } = await config.http.get(`${config.apiBase}/status`);
            state.status = data.data;
        } catch {
            if (! state.status) {
                state.statusError = true;
            }
        } finally {
            state.statusLoading = false;
        }
    }

    function changed(projectId = null) {
        state.changedProject = projectId;
        state.changes += 1;
    }

    return {
        ...config,
        state,
        loadStatus,
        changed,
        api: (path) => `${config.apiBase}${path}`,
        routeName: (page) => (page === 'home' ? `${config.portal}.marketing` : `${config.portal}.marketing.${page}`),
    };
}

export function provideKey() {
    return KEY;
}

/** The context plus the helpers every page needs: `fail()`, `to()` and formatting. */
export function useMk() {
    const ctx = inject(KEY);
    const router = useRouter();
    const { t, locale } = useI18n();
    const toast = useToast();

    if (! ctx) {
        throw new Error('Marketing context missing — render marketing pages inside MarketingSection.');
    }

    function to(page, params = {}) {
        return { name: ctx.routeName(page), params };
    }

    /** The server's message as a red toast; with no package the packages page opens (402 marketing_package_required). */
    function fail(error, fallback = null) {
        const body = error?.response?.data ?? {};
        toast.showError(body.message || fallback || t('marketing.error_generic'));
        if (body.error_code === 'marketing_package_required') {
            router.push(to('packages'));
        }

        return body;
    }

    function date(iso) {
        if (! iso) {
            return '';
        }
        const parsed = new Date(iso);
        if (Number.isNaN(parsed.getTime())) {
            return String(iso).slice(0, 10);
        }

        return parsed.toLocaleDateString(`${locale.value}-u-nu-latn`, { year: 'numeric', month: 'short', day: 'numeric' });
    }

    const intl = () => `${locale.value}-u-nu-latn`;

    /** "Tue 12 Nov · 19:00" in the reader's language. */
    function dateTime(iso) {
        if (! iso) return '';
        const parsed = new Date(iso);
        if (Number.isNaN(parsed.getTime())) return '';

        return parsed.toLocaleString(intl(), { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false });
    }

    /** A `YYYY-MM-DD` day with Intl options (default "12 Nov"). */
    function day(value, options = { day: 'numeric', month: 'short' }) {
        if (! value) return '';
        const [y, m, d] = String(value).slice(0, 10).split('-').map(Number);

        return new Date(y, m - 1, d).toLocaleDateString(intl(), options);
    }

    /** Is this feature switched on in the person's country (status.features)? */
    function feature(key) {
        return ctx.state.status?.features?.[key] === true;
    }

    return { ctx, state: ctx.state, http: ctx.http, api: ctx.api, to, fail, date, dateTime, day, feature, t, toast, router };
}

/** A reactive "is this push for my project?" helper: pages of another project ignore it. */
export function relevantChange(state, projectId) {
    return ! state.changedProject || state.changedProject === projectId;
}

// ------------------------------------------------------------------ money (integers in minor units, no float math)

export function mkMoney(minor, currency = '', decimals = 2) {
    if (minor === null || minor === undefined || minor === '') {
        return '—';
    }
    const n = Number(minor);
    const negative = n < 0;
    const abs = Math.abs(Math.trunc(n));
    const factor = 10 ** decimals;
    const whole = Math.floor(abs / factor).toLocaleString('en-US');
    const frac = decimals > 0 ? `.${String(abs % factor).padStart(decimals, '0')}` : '';
    const amount = `${negative ? '-' : ''}${whole}${frac}`;

    return currency ? `${amount} ${currency}` : amount;
}

/** Minor units as an editable plain number ("1234.50"). */
export function mkMinorToInput(minor, decimals = 2) {
    if (minor === null || minor === undefined) {
        return '';
    }

    return mkMoney(minor, '', decimals).replace(/,/g, '');
}

/** Western digits from Arabic-Indic ones; Arabic decimal separator to a dot. */
export function mkNormalizeDigits(text) {
    return String(text ?? '')
        .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660))
        .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0))
        .replace(/٫/g, '.')
        .replace(/[,،]/g, '')
        .trim();
}

/** "1,234.5" typed by a person → minor units; null when it isn't a non-negative number. */
export function mkParseMinor(text, decimals = 2) {
    const clean = mkNormalizeDigits(text);
    const match = /^(\d+)(?:\.(\d*))?$/.exec(clean);
    if (! match) {
        return null;
    }
    const whole = Number(match[1]);
    let frac = (match[2] ?? '').padEnd(decimals + 1, '0');
    const roundUp = Number(frac[decimals]) >= 5;
    frac = frac.slice(0, decimals);
    const value = whole * (10 ** decimals) + (decimals ? Number(frac) : 0) + (roundUp ? 1 : 0);

    return Number.isSafeInteger(value) ? value : null;
}

// ------------------------------------------------------------------ labels and colors

export const RESEARCH_KINDS = ['market', 'competitor', 'audience'];
export const PLAN_TYPES = ['business', 'marketing', 'gtm'];
export const STAGES = ['idea', 'launch', 'growth'];
export const ASSIGNABLE_ROLES = ['manager', 'analyst', 'viewer'];
const FINISHED = ['COMPLETED', 'PARTIAL', 'FAILED', 'CANCELLED'];

export function isFinished(status) {
    return FINISHED.includes(status);
}

export function statusTone(status) {
    if (['COMPLETED', 'APPROVED'].includes(status)) return 'success';
    if (['PARTIAL', 'READY_FOR_REVIEW'].includes(status)) return 'warning';
    if (['FAILED', 'CANCELLED'].includes(status)) return 'danger';
    if (['QUEUED', 'RUNNING', 'EVIDENCE_CHECK'].includes(status)) return 'info';

    return 'secondary';
}

export function statusKey(status) {
    const known = ['QUEUED', 'RUNNING', 'EVIDENCE_CHECK', 'COMPLETED', 'PARTIAL', 'FAILED', 'CANCELLED', 'DRAFT', 'READY_FOR_REVIEW', 'APPROVED', 'SUPERSEDED', 'ARCHIVED'];

    return `marketing.status.${known.includes(status) ? status : 'DRAFT'}`;
}

export function roleKey(role) {
    return `marketing.roles.${['owner', 'manager', 'analyst'].includes(role) ? role : 'viewer'}`;
}

export function stageKey(stage) {
    return `marketing.stages.${STAGES.includes(stage) ? stage : 'idea'}`;
}

export function kindKey(kind) {
    return `marketing.research_kinds.${RESEARCH_KINDS.includes(kind) ? kind : 'market'}`;
}

export function planKey(type) {
    return `marketing.plan_types.${PLAN_TYPES.includes(type) ? type : 'gtm'}`;
}

// ------------------------------------------------------------------ phase B / C (execution, platforms, campaigns)

export const CHANNELS = ['instagram', 'tiktok', 'snapchat', 'x', 'facebook', 'youtube', 'google', 'linkedin', 'whatsapp', 'email', 'sms', 'website', 'offline', 'other'];
export const FORMATS = ['image', 'video', 'story', 'reel', 'carousel', 'text'];
export const OBJECTIVES = ['awareness', 'traffic', 'engagement', 'leads', 'sales'];

export function channelKey(channel) {
    return `marketing.channels.${CHANNELS.includes(channel) ? channel : 'other'}`;
}

export function formatKey(format) {
    return `marketing.formats.${FORMATS.includes(format) ? format : 'image'}`;
}

export function contentKindKey(kind) {
    return `marketing.content_kinds.${['ad', 'task'].includes(kind) ? kind : 'post'}`;
}

const CONTENT_TONES = {
    DRAFT: 'secondary', POLICY_REVIEW: 'warning', APPROVED: 'success', SCHEDULED: 'info', PUBLISHED: 'success',
    FAILED: 'danger', REJECTED: 'danger', OPEN: 'secondary', DONE: 'success',
};

export function contentStatus(status) {
    const known = status in CONTENT_TONES ? status : 'DRAFT';

    return { key: `marketing.content_status.${known}`, tone: CONTENT_TONES[known] };
}

const CAMPAIGN_TONES = {
    DRAFT: 'secondary', VALIDATED: 'info', AWAITING_APPROVAL: 'warning', APPROVED: 'success', SUBMITTING: 'info',
    PUBLISHED: 'success', REJECTED: 'danger', FAILED: 'danger', UNKNOWN: 'warning',
};

export function campaignStatus(status) {
    const known = status in CAMPAIGN_TONES ? status : 'DRAFT';

    return { key: `marketing.campaign_status.${known}`, tone: CAMPAIGN_TONES[known] };
}

/** A local calendar day as `YYYY-MM-DD` (never through UTC, so the day doesn't shift). */
export function localDay(date = new Date()) {
    const d = new Date(date);

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function addDays(day, n) {
    const [y, m, d] = day.split('-').map(Number);

    return localDay(new Date(y, m - 1, d + n));
}

export function parseDay(day) {
    const [y, m, d] = day.split('-').map(Number);

    return new Date(y, m - 1, d);
}

/** A server time from a local day and `HH:mm`. */
export function localIso(day, time = '09:00') {
    const [y, m, d] = day.split('-').map(Number);
    const [hh, mm] = time.split(':').map(Number);

    return new Date(y, m - 1, d, hh, mm).toISOString();
}

export function timeOf(iso) {
    if (! iso) return '';
    const d = new Date(iso);

    return Number.isNaN(d.getTime()) ? '' : `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

/** `datetime-local` input value from a server time. */
export function toLocalInput(iso) {
    if (! iso) return '';
    const d = new Date(iso);

    return Number.isNaN(d.getTime()) ? '' : `${localDay(d)}T${timeOf(iso)}`;
}

export function newIdempotencyKey() {
    const random = typeof crypto !== 'undefined' && crypto.randomUUID
        ? crypto.randomUUID()
        : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;

    return `mk-${random}`;
}
