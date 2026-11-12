<template>
    <div>
        <MkHeader :title="t('marketing.results')" :subtitle="project?.name || ''" :back="to('project', { projectId })">
            <template v-if="enabled && canEnter">
                <button type="button" class="btn btn-primary" @click="adding = true">
                    <i class="ri-add-line me-1"></i>{{ t('marketing.results_add') }}
                </button>
                <button type="button" class="btn btn-light" :disabled="importing" @click="fileInput?.click()">
                    <span v-if="importing" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="ri-upload-2-line me-1"></i>{{ t('marketing.results_import') }}
                </button>
                <input ref="fileInput" type="file" accept=".csv,text/csv,text/plain" class="d-none" @change="importFile">
            </template>
        </MkHeader>

        <MkUnavailable v-if="statusReady && !enabled" />
        <template v-else>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <MkChips v-model="days" :options="[7, 30, 90].map((n) => ({ value: n, label: t('marketing.last_n_days', { n }) }))" />
                <router-link :to="to('report', { projectId })" class="btn btn-light btn-sm ms-auto">
                    <i class="ri-file-chart-line me-1"></i>{{ t('marketing.report') }}
                </router-link>
            </div>
            <p v-if="canEnter" class="text-muted fs-12">{{ t('marketing.import_hint') }}</p>

            <div v-if="fileErrors" class="alert alert-danger fs-13">
                <div class="fw-semibold mb-1">{{ t('marketing.file_refused') }}</div>
                <div v-for="(line, i) in fileErrors" :key="i">{{ line }}</div>
            </div>

            <div v-if="!board" class="d-grid gap-3">
                <div v-for="i in 2" :key="i" class="mk-skeleton" style="height: 110px"></div>
            </div>
            <template v-else>
                <div class="d-flex align-items-center gap-2 mk-section-title">
                    <span class="flex-grow-1">{{ t('marketing.measured') }}</span>
                    <span class="badge bg-success-transparent">{{ t('marketing.basis_imported') }}</span>
                </div>
                <div v-if="!board.gaps?.days_with_data" class="card custom-card mk-card">
                    <div class="card-body text-center py-5">
                        <span class="mk-icon bg-warning-transparent text-warning mb-3"><i class="ri-line-chart-line"></i></span>
                        <h6 class="fw-semibold">{{ t('marketing.no_results') }}</h6>
                        <p class="text-muted mb-0">{{ t('marketing.no_results_sub') }}</p>
                    </div>
                </div>
                <template v-else>
                    <KpiGrid :group="board.measured" :currency="boardCurrency" />
                    <div v-if="series.length > 1" class="card custom-card mk-card mt-3">
                        <div class="card-body">
                            <div class="text-muted fs-12 fw-semibold mb-2">{{ t('marketing.spend_per_day') }}</div>
                            <div class="mk-bars" dir="ltr">
                                <span
                                    v-for="p in series"
                                    :key="p.date"
                                    :style="{ height: `${Math.max(3, ((p.spend_minor ?? 0) / maxSpend) * 100)}%` }"
                                    :title="`${p.date}: ${mkMoney(p.spend_minor, boardCurrency)}`"
                                ></span>
                            </div>
                            <div class="d-flex justify-content-between text-muted fs-11 mt-1" dir="ltr">
                                <span>{{ day(series[0].date) }}</span><span>{{ day(series[series.length - 1].date) }}</span>
                            </div>
                        </div>
                    </div>
                    <p class="text-muted fs-12 mt-2 mb-0">
                        {{ t('marketing.coverage', { with: board.gaps.days_with_data, days: board.gaps.days }) }}
                        <template v-if="board.last_updated_at"> · {{ t('marketing.updated', { date: dateTime(board.last_updated_at) }) }}</template>
                    </p>
                    <div v-if="board.gaps.channels_without_revenue?.length" class="alert alert-warning fs-13 mt-2">
                        {{ t('marketing.no_revenue_for', { channels: board.gaps.channels_without_revenue.map((c) => t(channelKey(c))).join('، ') }) }}
                    </div>
                </template>

                <template v-if="board.estimated">
                    <div class="d-flex align-items-center gap-2 mk-section-title">
                        <span class="flex-grow-1">{{ t('marketing.estimated_section') }}</span>
                        <span class="badge bg-warning-transparent">{{ t('marketing.basis_estimated') }}</span>
                    </div>
                    <p class="text-muted fs-12">{{ t('marketing.estimated_note') }}</p>
                    <KpiGrid :group="board.estimated" :currency="boardCurrency" />
                </template>

                <template v-if="byChannel.length">
                    <h2 class="mk-section-title">{{ t('marketing.by_channel') }}</h2>
                    <div class="row g-2">
                        <div v-for="ch in byChannel" :key="ch.channel" class="col-md-6 col-xl-4">
                            <div class="card custom-card mk-card h-100 mb-0">
                                <div class="card-body">
                                    <div class="d-flex align-items-center mb-2">
                                        <span class="fw-semibold flex-grow-1">{{ t(channelKey(ch.channel)) }}</span>
                                        <span v-if="ch.last_date" class="text-muted fs-11">{{ day(ch.last_date) }}</span>
                                    </div>
                                    <div class="row g-2 fs-12">
                                        <div class="col-4"><div class="text-muted">{{ t('marketing.kpi.spend') }}</div><div class="fw-bold mk-ltr">{{ mkMoney(ch.measured?.totals?.spend_minor) }}</div></div>
                                        <div class="col-4"><div class="text-muted">{{ t('marketing.kpi.conversions') }}</div><div class="fw-bold mk-ltr">{{ ch.measured?.totals?.conversions ?? '—' }}</div></div>
                                        <div class="col-4"><div class="text-muted">{{ t('marketing.kpi.roas') }}</div><div class="fw-bold mk-ltr">{{ rate(ch.measured?.rates?.roas, '×') }}</div></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <template v-if="rows.length">
                    <h2 class="mk-section-title">{{ t('marketing.entries') }}</h2>
                    <div class="card custom-card mk-card">
                        <ul class="list-group list-group-flush">
                            <li v-for="r in rows.slice(0, 30)" :key="r.id" class="list-group-item d-flex align-items-center gap-2 flex-wrap">
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-semibold fs-13">{{ day(r.date) }} · {{ t(channelKey(r.channel)) }}</div>
                                    <div class="text-muted fs-12">{{ rowSummary(r) }}</div>
                                </div>
                                <span class="badge" :class="r.basis === 'estimated' ? 'bg-warning-transparent' : 'bg-success-transparent'">
                                    {{ r.basis === 'estimated' ? t('marketing.basis_estimated') : t('marketing.basis_imported') }}
                                </span>
                                <button v-if="canEnter" type="button" class="btn btn-icon btn-sm btn-light" :aria-label="t('marketing.delete_item')" @click="removing = r">
                                    <i class="ri-delete-bin-line"></i>
                                </button>
                            </li>
                        </ul>
                    </div>
                </template>
            </template>
        </template>

        <!-- Enter results by hand -->
        <MkModal :show="adding" :title="t('marketing.results_add')" :busy="busy" @close="adding = false">
            <label class="form-label" for="mk-r-day">{{ t('marketing.day') }}</label>
            <input id="mk-r-day" v-model="entry.date" type="date" class="form-control mk-ltr" :max="today">
            <label class="form-label fw-semibold fs-13 mt-3">{{ t('marketing.channel') }}</label>
            <MkChips v-model="entry.channel" :options="CHANNELS.filter((c) => c !== 'offline').map((c) => ({ value: c, label: t(channelKey(c)) }))" />
            <div class="mt-3">
                <MkChips v-model="entry.basis" :options="[{ value: 'imported', label: t('marketing.basis_imported') }, { value: 'estimated', label: t('marketing.basis_estimated') }]" />
                <div class="text-muted fs-12 mt-1">{{ entry.basis === 'imported' ? t('marketing.basis_imported_sub') : t('marketing.basis_estimated_sub') }}</div>
            </div>
            <div class="row g-2 mt-1">
                <div v-for="f in FIELDS" :key="f.key" class="col-6">
                    <label class="form-label fs-13" :for="`mk-r-${f.key}`">{{ t(`marketing.kpi.${f.label}`) }}</label>
                    <input
                        :id="`mk-r-${f.key}`"
                        v-model="entry[f.key]"
                        class="form-control mk-ltr"
                        :class="{ 'is-invalid': fieldInvalid(f) }"
                        :inputmode="f.money ? 'decimal' : 'numeric'"
                        :placeholder="f.money ? '0.00' : '0'"
                        maxlength="14"
                    >
                </div>
            </div>
            <div class="text-muted fs-12 mt-2">{{ t('marketing.results_blank_hint', { currency: boardCurrency }) }}</div>
            <div v-if="clicksTooMany" class="text-danger fs-12 mt-1">{{ t('marketing.clicks_too_many') }}</div>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy" @click="adding = false">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-primary" :disabled="busy || !entryValid" @click="saveEntry">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.save') }}
                </button>
            </template>
        </MkModal>

        <MkModal :show="removing !== null" :title="t('marketing.delete_item')" :busy="busy" @close="removing = null">
            <p class="mb-0">{{ removing ? `${day(removing.date)} · ${t(channelKey(removing.channel))}` : '' }}</p>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy" @click="removing = null">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-danger" :disabled="busy" @click="remove">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.delete_item') }}
                </button>
            </template>
        </MkModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { CHANNELS, addDays, channelKey, localDay, mkMoney, mkNormalizeDigits, mkParseMinor } from './marketingContext';
import useProjectPage from './parts/useProjectPage';
import MkHeader from './parts/MkHeader.vue';
import MkChips from './parts/MkChips.vue';
import MkModal from './parts/MkModal.vue';
import MkUnavailable from './parts/MkUnavailable.vue';
import KpiGrid from './parts/KpiGrid.vue';

const FIELDS = [
    { key: 'impressions', label: 'impressions', money: false },
    { key: 'clicks', label: 'clicks', money: false },
    { key: 'spend', label: 'spend', money: true },
    { key: 'revenue', label: 'revenue', money: true },
    { key: 'conversions', label: 'conversions', money: false },
];

/**
 * Results + KPI board (U11): what was measured (copied from the platforms' reports) and what was estimated are never
 * added together; a rate without what it needs says why instead of 0. Results come by hand or from a CSV file.
 */
const {
    projectId, project, abilities, statusReady, enabled, currency, http, api, to, fail, day, dateTime, t, toast, onPush,
} = useProjectPage('results');

const today = localDay();
const days = ref(30);
const board = ref(null);
const rows = ref([]);
const adding = ref(false);
const removing = ref(null);
const busy = ref(false);
const importing = ref(false);
const fileErrors = ref(null);
const fileInput = ref(null);

const canEnter = computed(() => abilities.value.results === true);
const boardCurrency = computed(() => board.value?.currency || currency.value);
const list = (v) => (Array.isArray(v) ? v : Object.values(v ?? {}));
const series = computed(() => list(board.value?.series).sort((a, b) => a.date.localeCompare(b.date)));
const maxSpend = computed(() => Math.max(1, ...series.value.map((p) => p.spend_minor ?? 0)));
const byChannel = computed(() => list(board.value?.by_channel));

function rate(r, suffix) {
    if (r?.value === null || r?.value === undefined) return '—';

    return `${Number(r.value).toLocaleString('en-US', { maximumFractionDigits: 2 })}${suffix}`;
}

function rowSummary(r) {
    return [
        r.spend_minor != null ? `${t('marketing.kpi.spend')} ${mkMoney(r.spend_minor)}` : null,
        r.clicks != null ? `${t('marketing.kpi.clicks')} ${r.clicks}` : null,
        r.conversions != null ? `${t('marketing.kpi.conversions')} ${r.conversions}` : null,
    ].filter(Boolean).join(' · ');
}

async function load() {
    if (! enabled.value) return;
    try {
        const [k, m] = await Promise.all([
            http.get(api(`/projects/${projectId}/kpis`), { params: { from: addDays(today, -(days.value - 1)), to: today } }),
            http.get(api(`/projects/${projectId}/metrics`)),
        ]);
        board.value = k.data.data;
        rows.value = m.data.data ?? [];
    } catch (error) {
        fail(error);
    }
}

/** `data.errors[{line, field}]` of a refused file / rows, as readable lines. */
function lineErrors(error) {
    const errors = error?.response?.data?.data?.errors;
    if (! Array.isArray(errors) || ! errors.length) return null;

    return errors.slice(0, 8).map((e) => t('marketing.file_line', { line: e.line ?? 0, field: e.field ?? '' }));
}

async function importFile(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (! file) return;
    fileErrors.value = null;
    if (file.size > 1_000_000) {
        toast.showError(t('marketing.file_too_big'));

        return;
    }
    importing.value = true;
    try {
        const body = new FormData();
        body.append('file', file);
        const { data } = await http.post(api(`/projects/${projectId}/metrics/import`), body);
        toast.showSuccess(`${t('marketing.results_imported')} (${(data.data?.created ?? 0) + (data.data?.updated ?? 0)})`);
        load();
    } catch (error) {
        fileErrors.value = lineErrors(error);
        fail(error);
    } finally {
        importing.value = false;
    }
}

// ---------------------------------------------------------------- manual entry
const entry = reactive({ date: addDays(today, -1), channel: 'instagram', basis: 'imported', impressions: '', clicks: '', spend: '', revenue: '', conversions: '' });

function count(key) {
    const v = mkNormalizeDigits(entry[key]);

    return /^\d+$/.test(v) ? Number(v) : null;
}

function fieldInvalid(f) {
    const raw = String(entry[f.key] ?? '').trim();
    if (! raw) return false;

    return f.money ? mkParseMinor(raw) === null : count(f.key) === null;
}

const clicksTooMany = computed(() => count('clicks') !== null && count('impressions') !== null && count('clicks') > count('impressions'));
const entryEmpty = computed(() => FIELDS.every((f) => ! String(entry[f.key] ?? '').trim()));
const entryValid = computed(() => !! entry.date && entry.date <= today && ! entryEmpty.value && ! clicksTooMany.value && ! FIELDS.some(fieldInvalid));

watch(adding, (open) => {
    if (open) Object.assign(entry, { date: addDays(today, -1), impressions: '', clicks: '', spend: '', revenue: '', conversions: '' });
});

async function saveEntry() {
    busy.value = true;
    const money = (k) => (String(entry[k]).trim() ? mkParseMinor(entry[k]) : null);
    try {
        await http.post(api(`/projects/${projectId}/metrics`), {
            rows: [{
                date: entry.date,
                channel: entry.channel,
                basis: entry.basis,
                impressions: count('impressions'),
                clicks: count('clicks'),
                spend_minor: money('spend'),
                conversions: count('conversions'),
                revenue_minor: money('revenue'),
            }],
        });
        toast.showSuccess(t('marketing.results_saved'));
        adding.value = false;
        load();
    } catch (error) {
        const lines = lineErrors(error);
        if (lines) fileErrors.value = lines;
        fail(error);
    } finally {
        busy.value = false;
    }
}

async function remove() {
    busy.value = true;
    try {
        await http.delete(api(`/metrics/${removing.value.id}`));
        removing.value = null;
        load();
    } catch (error) {
        fail(error);
    } finally {
        busy.value = false;
    }
}

watch(days, () => {
    board.value = null;
    load();
});
watch(enabled, (on) => on && load());
onPush(load);
onMounted(load);
</script>

<style>
.mk-bars {
    display: flex;
    align-items: flex-end;
    gap: 3px;
    height: 80px;
}

.mk-bars > span {
    flex: 1;
    min-width: 2px;
    border-radius: 3px 3px 0 0;
    background: var(--primary-color, #845adf);
}
</style>
