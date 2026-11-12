<template>
    <div>
        <MkHeader :title="t('marketing.calendar')" :subtitle="project?.name || ''" :back="to('project', { projectId })">
            <template v-if="enabled && canEdit">
                <button type="button" class="btn btn-primary" :disabled="!!running" @click="generating = true">
                    <i class="ri-sparkling-2-line me-1"></i>{{ t('marketing.calendar_generate') }}
                </button>
                <button type="button" class="btn btn-light" @click="adding = true">
                    <i class="ri-add-line me-1"></i>{{ t('marketing.add') }}
                </button>
            </template>
        </MkHeader>

        <MkUnavailable v-if="statusReady && !enabled" />
        <template v-else>
            <!-- Week switcher + day strip -->
            <div class="card custom-card mk-card mb-3">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <button type="button" class="btn btn-light btn-icon btn-sm" :aria-label="t('marketing.prev_week')" @click="shiftWeek(-1)">
                            <i class="ri-arrow-left-s-line mk-flip"></i>
                        </button>
                        <div class="flex-grow-1 text-center fw-semibold">{{ day(weekStart) }} – {{ day(addDays(weekStart, 6), { day: 'numeric', month: 'short', year: 'numeric' }) }}</div>
                        <button type="button" class="btn btn-light btn-icon btn-sm" :aria-label="t('marketing.next_week')" @click="shiftWeek(1)">
                            <i class="ri-arrow-right-s-line mk-flip"></i>
                        </button>
                    </div>
                    <div class="mk-week">
                        <button
                            v-for="d in weekDays"
                            :key="d"
                            type="button"
                            class="mk-week__day"
                            :class="{ 'is-selected': d === selected, 'is-today': d === today }"
                            :aria-pressed="d === selected"
                            @click="selected = d"
                        >
                            <span class="mk-week__name">{{ day(d, { weekday: 'short' }) }}</span>
                            <span class="mk-week__num">{{ day(d, { day: 'numeric' }) }}</span>
                            <span class="mk-week__dot" :class="{ 'is-on': (busyDays[d] ?? 0) > 0 }"></span>
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="running" class="card custom-card mk-card mb-3">
                <div class="card-body">
                    <div class="fw-semibold fs-13 mb-2"><span class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.calendar_writing') }}</div>
                    <MkBar :value="running.progress_pct" />
                </div>
            </div>
            <div v-else-if="lastFailed" class="alert alert-warning fs-13">
                {{ t('marketing.job_failed', { reason: jobError(lastFailed.error_code) }) }}
            </div>

            <h2 class="mk-section-title">{{ day(selected, { weekday: 'long', day: 'numeric', month: 'long' }) }}</h2>
            <div v-if="items === null" class="d-grid gap-2">
                <div v-for="i in 3" :key="i" class="mk-skeleton" style="height: 76px"></div>
            </div>
            <div v-else-if="dayItems.length === 0" class="card custom-card mk-card">
                <div class="card-body text-center py-5">
                    <span class="mk-icon bg-secondary-transparent text-secondary mb-3"><i class="ri-calendar-check-line"></i></span>
                    <h6 class="fw-semibold">{{ t('marketing.calendar_empty') }}</h6>
                    <p class="text-muted mb-0">{{ canEdit ? t('marketing.calendar_empty_sub') : t('marketing.calendar_empty_view') }}</p>
                </div>
            </div>
            <div v-else class="d-grid gap-2">
                <ContentRow
                    v-for="item in dayItems"
                    :key="item.id"
                    :item="item"
                    :to="to('content', { projectId, itemId: item.id })"
                    :can-tick="canEdit"
                    @tick="(done) => tick(item, done)"
                />
            </div>
            <p v-if="unplanned > 0" class="text-muted fs-13 mt-3">{{ t('marketing.unplanned', { count: unplanned }) }}</p>
        </template>

        <!-- Fill the calendar from the approved plan -->
        <MkModal :show="generating" :title="t('marketing.calendar_generate')" :busy="busy" @close="generating = false">
            <p class="text-muted fs-13">{{ t('marketing.calendar_generate_sub') }}</p>
            <label class="form-label fw-semibold fs-13">{{ t('marketing.days') }}</label>
            <MkChips v-model="gen.days" :options="[7, 14, 30].map((n) => ({ value: n, label: t('marketing.n_days', { n }) }))" />
            <label class="form-label fw-semibold fs-13 mt-3">{{ t('marketing.channels_optional') }}</label>
            <MkChips v-model="gen.channels" multiple :max="8" :options="channelOptions(['offline', 'other'])" />
            <div class="form-check form-switch mt-3">
                <input id="mk-gen-replace" v-model="gen.replace" class="form-check-input" type="checkbox" role="switch">
                <label class="form-check-label fw-semibold" for="mk-gen-replace">{{ t('marketing.replace_drafts') }}</label>
                <div class="text-muted fs-12">{{ t('marketing.replace_drafts_sub') }}</div>
            </div>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy" @click="generating = false">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-primary" :disabled="busy" @click="generate">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.calendar_generate') }}
                </button>
            </template>
        </MkModal>

        <!-- A post, an ad or a task by hand -->
        <MkModal :show="adding" :title="t('marketing.add_item')" :busy="busy" @close="adding = false">
            <MkChips v-model="draft.kind" :options="['post', 'ad', 'task'].map((k) => ({ value: k, label: t(contentKindKey(k)) }))" />
            <label class="form-label fw-semibold fs-13 mt-3">{{ t('marketing.channel') }}</label>
            <MkChips v-model="draft.channel" :options="channelOptions(draft.kind === 'task' ? [] : ['offline'])" />
            <label class="form-label mt-3" for="mk-item-title">{{ t('marketing.item_title') }}</label>
            <input id="mk-item-title" v-model="draft.title" class="form-control" maxlength="160">
            <template v-if="draft.kind !== 'task'">
                <label class="form-label mt-3" for="mk-item-body">{{ t('marketing.body') }}</label>
                <textarea id="mk-item-body" v-model="draft.body" class="form-control" rows="4" maxlength="2200"></textarea>
            </template>
            <label class="form-label mt-3">{{ t('marketing.when') }}</label>
            <div class="d-flex gap-2">
                <input v-model="draft.day" type="date" class="form-control mk-ltr" :aria-label="t('marketing.day')">
                <input v-model="draft.time" type="time" class="form-control mk-ltr" style="max-width: 9rem" :aria-label="t('marketing.when')">
            </div>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy" @click="adding = false">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-primary" :disabled="busy || !draftValid" @click="addItem">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.save') }}
                </button>
            </template>
        </MkModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { CHANNELS, addDays, channelKey, contentKindKey, isFinished, localDay, localIso, parseDay } from './marketingContext';
import useProjectPage from './parts/useProjectPage';
import useJobErrors from './parts/useJobErrors';
import MkHeader from './parts/MkHeader.vue';
import MkBar from './parts/MkBar.vue';
import MkModal from './parts/MkModal.vue';
import MkChips from './parts/MkChips.vue';
import MkUnavailable from './parts/MkUnavailable.vue';
import ContentRow from './parts/ContentRow.vue';

/**
 * The daily calendar (U06): a week strip, the chosen day's posts, ads and tasks, and the AI that fills the coming days
 * from the approved plan. Reloads once per push of this project (job moved) — never on a timer.
 */
const {
    projectId, project, abilities, statusReady, enabled, active, http, api, to, fail, day, t, toast, router, onPush,
} = useProjectPage('calendar');
const jobError = useJobErrors();

function firstDayOfWeek() {
    try {
        const info = new Intl.Locale(document.documentElement.lang || navigator.language).weekInfo;
        if (info?.firstDay) return info.firstDay % 7; // Intl: 1 = Monday … 7 = Sunday → JS: 0 = Sunday
    } catch { /* older browsers */ }

    return document.documentElement.dir === 'rtl' ? 6 : 1;
}

function startOfWeek(dayValue) {
    const d = parseDay(dayValue);
    const diff = (d.getDay() - firstDayOfWeek() + 7) % 7;

    return addDays(dayValue, -diff);
}

const today = localDay();
const selected = ref(today);
const weekStart = computed(() => startOfWeek(selected.value));
const weekDays = computed(() => Array.from({ length: 7 }, (_, i) => addDays(weekStart.value, i)));
const items = ref(null);
const unplanned = ref(0);
const jobs = ref([]);
const generating = ref(false);
const adding = ref(false);
const busy = ref(false);

const canEdit = computed(() => abilities.value.edit === true && active.value);
const running = computed(() => jobs.value.find((j) => j.type === 'calendar' && ! isFinished(j.status)) ?? null);
const lastFailed = computed(() => {
    const last = jobs.value.find((j) => j.type === 'calendar');

    return last?.status === 'FAILED' ? last : null;
});
const busyDays = computed(() => (items.value ?? []).reduce((acc, it) => {
    if (it.planned_at) {
        const k = localDay(it.planned_at);
        acc[k] = (acc[k] ?? 0) + 1;
    }

    return acc;
}, {}));
const dayItems = computed(() => (items.value ?? [])
    .filter((it) => it.planned_at && localDay(it.planned_at) === selected.value)
    .sort((a, b) => String(a.planned_at).localeCompare(String(b.planned_at))));

function channelOptions(except = []) {
    return CHANNELS.filter((c) => ! except.includes(c)).map((c) => ({ value: c, label: t(channelKey(c)) }));
}

function shiftWeek(delta) {
    selected.value = addDays(selected.value, delta * 7);
}

async function load() {
    if (! enabled.value) return;
    try {
        const [cal, j] = await Promise.all([
            http.get(api(`/projects/${projectId}/calendar`), { params: { from: weekStart.value, to: addDays(weekStart.value, 6) } }),
            http.get(api(`/projects/${projectId}/jobs`)),
        ]);
        items.value = cal.data.data?.items ?? [];
        unplanned.value = cal.data.data?.unplanned ?? 0;
        jobs.value = j.data.data ?? [];
    } catch (error) {
        items.value = items.value ?? [];
        fail(error);
    }
}

async function tick(item, done) {
    try {
        await http.post(api(`/content/${item.id}/done`), { done });
        load();
    } catch (error) {
        fail(error);
    }
}

// ---------------------------------------------------------------- generate
const gen = reactive({ days: 7, channels: [], replace: true });

async function generate() {
    busy.value = true;
    try {
        const { data } = await http.post(api(`/projects/${projectId}/calendar/generate`), {
            days: gen.days,
            from: selected.value < today ? today : selected.value,
            channels: gen.channels.length ? gen.channels : undefined,
            replace_drafts: gen.replace,
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        });
        toast.showSuccess(t('marketing.calendar_started'));
        generating.value = false;
        if (data.data?.job) jobs.value = [data.data.job, ...jobs.value];
    } catch (error) {
        fail(error);
    } finally {
        busy.value = false;
    }
}

// ---------------------------------------------------------------- add by hand
const draft = reactive({ kind: 'post', channel: 'instagram', title: '', body: '', day: today, time: '19:00' });
const draftValid = computed(() => draft.title.trim().length >= 2 && (draft.kind === 'task' || draft.body.trim() !== '') && !! draft.day && !! draft.time);

watch(() => draft.kind, (kind) => {
    if (kind === 'task') draft.channel = 'offline';
    else if (draft.channel === 'offline') draft.channel = 'instagram';
});
watch(adding, (open) => {
    if (open) Object.assign(draft, { title: '', body: '', day: selected.value, time: '19:00' });
});

async function addItem() {
    busy.value = true;
    try {
        const body = {
            kind: draft.kind,
            channel: draft.channel,
            title: draft.title.trim(),
            planned_at: localIso(draft.day, draft.time),
        };
        if (draft.kind !== 'task') body.variants = [{ body: draft.body.trim() }];
        const { data } = await http.post(api(`/projects/${projectId}/content`), body);
        adding.value = false;
        toast.showSuccess(data.message || t('marketing.saved'));
        if (draft.kind !== 'task' && data.data?.id) {
            router.push(to('content', { projectId, itemId: data.data.id }));
        } else {
            load();
        }
    } catch (error) {
        fail(error);
    } finally {
        busy.value = false;
    }
}

watch(weekStart, load);
watch(enabled, (on) => on && load());
onPush(load);
onMounted(load);
</script>

<style>
.mk-week {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 0.25rem;
}

.mk-week__day {
    border: 1px solid transparent;
    background: transparent;
    border-radius: 0.75rem;
    padding: 0.5rem 0.125rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.125rem;
    color: inherit;
    min-width: 0;
}

.mk-week__day:hover {
    background: rgba(140, 144, 151, 0.1);
}

.mk-week__day.is-today {
    border-color: var(--primary03, rgba(132, 90, 223, 0.4));
}

.mk-week__day.is-selected {
    background: var(--primary-color, #845adf);
    color: #fff;
}

.mk-week__name {
    font-size: 0.6875rem;
    opacity: 0.75;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
}

.mk-week__num {
    font-size: 1rem;
    font-weight: 700;
}

.mk-week__dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: transparent;
}

.mk-week__dot.is-on {
    background: var(--primary-color, #845adf);
}

.mk-week__day.is-selected .mk-week__dot.is-on {
    background: #fff;
}
</style>
