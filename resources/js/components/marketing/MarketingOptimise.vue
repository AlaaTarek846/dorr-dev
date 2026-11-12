<template>
    <div>
        <MkHeader :title="t('marketing.optimise')" :subtitle="project?.name || ''" :back="to('project', { projectId })" />

        <MkUnavailable v-if="statusReady && !enabled" />
        <div v-else-if="!advice" class="d-grid gap-3">
            <div v-for="i in 3" :key="i" class="mk-skeleton" style="height: 80px"></div>
        </div>
        <div v-else class="row g-3">
            <!-- Suggestions + alerts -->
            <div class="col-xl-6">
                <div v-if="advice.note" class="alert alert-info fs-13">{{ advice.note }}</div>
                <h2 class="mk-section-title mt-0">{{ t('marketing.suggestions') }}</h2>
                <div class="card custom-card mk-card mb-0">
                    <ul class="list-group list-group-flush">
                        <li v-for="(s, i) in advice.suggestions || []" :key="`${s.code}-${i}`" class="list-group-item d-flex gap-2 align-items-start">
                            <i class="ri-lightbulb-line fs-16" :class="PRIORITY[s.priority] ?? 'text-info'"></i>
                            <span class="fs-13 lh-base flex-grow-1">{{ s.text }}</span>
                        </li>
                    </ul>
                </div>

                <div class="d-flex align-items-center gap-2 mk-section-title">
                    <span class="flex-grow-1">{{ t('marketing.alert_rules') }}</span>
                    <button v-if="canEdit" type="button" class="btn btn-light btn-sm" @click="addingRule = true">
                        <i class="ri-add-line me-1"></i>{{ t('marketing.add') }}
                    </button>
                </div>
                <p v-if="!rules.length" class="text-muted fs-13">{{ t('marketing.no_alert_rules') }}</p>
                <div v-else class="d-grid gap-2">
                    <div v-for="r in rules" :key="r.id" class="card custom-card mk-card mb-0">
                        <div class="card-body d-flex align-items-center gap-3 flex-wrap">
                            <span class="mk-icon" :class="r.active ? 'bg-warning-transparent text-warning' : 'bg-light text-muted'"><i class="ri-notification-3-line"></i></span>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold fs-13">
                                    {{ t(`marketing.kpi.${METRIC_LABEL[r.metric] ?? 'cpa'}`) }}
                                    {{ r.comparison === 'above' ? t('marketing.above') : t('marketing.below') }}
                                    <span class="mk-ltr">{{ metricValue(r.metric, r.threshold) }}</span>
                                </div>
                                <div class="text-muted fs-12">
                                    {{ t('marketing.last_n_days', { n: r.window_days }) }} ·
                                    {{ t('marketing.now_value', { value: r.current?.value != null ? metricValue(r.metric, r.current.value) : t('marketing.kpi_reasons.missing_data') }) }}
                                </div>
                            </div>
                            <template v-if="canEdit">
                                <div class="form-check form-switch m-0">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        role="switch"
                                        :checked="r.active"
                                        :aria-label="t('marketing.alert_rules')"
                                        @change="toggleRule(r, $event.target.checked)"
                                    >
                                </div>
                                <button type="button" class="btn btn-icon btn-sm btn-light" :aria-label="t('marketing.delete_item')" @click="deleteRule(r)">
                                    <i class="ri-delete-bin-line"></i>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <!-- A/B tests -->
            <div class="col-xl-6">
                <div class="d-flex align-items-center gap-2 mk-section-title mt-xl-0">
                    <span class="flex-grow-1">{{ t('marketing.experiments') }}</span>
                    <button v-if="canEdit" type="button" class="btn btn-light btn-sm" @click="addingTest = true">
                        <i class="ri-add-line me-1"></i>{{ t('marketing.add') }}
                    </button>
                </div>
                <p v-if="!experiments.length" class="text-muted fs-13">{{ t('marketing.no_experiments') }}</p>
                <div v-else class="d-grid gap-2">
                    <div v-for="e in experiments" :key="e.id" class="card custom-card mk-card mb-0">
                        <div class="card-body">
                            <div class="d-flex align-items-start gap-3">
                                <span class="mk-icon bg-info-transparent text-info"><i class="ri-flask-line"></i></span>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-semibold text-break">{{ e.name }}</div>
                                    <div class="text-muted fs-12">{{ e.hypothesis }}</div>
                                </div>
                                <span class="badge" :class="e.status === 'CONCLUDED' ? 'bg-success-transparent' : 'bg-info-transparent'">
                                    {{ e.status === 'CONCLUDED' ? t('marketing.concluded') : t('marketing.running') }}
                                </span>
                            </div>
                            <div class="mt-3">
                                <div v-for="v in e.variants || []" :key="v.key" class="d-flex align-items-center gap-2 py-1">
                                    <span class="fw-bold mk-ltr" style="width: 1.5rem" :class="{ 'text-success': verdictOf(e)?.winner === v.key }">{{ v.key }}</span>
                                    <MkBar
                                        class="flex-grow-1"
                                        :value="barPct(e, v.key)"
                                        :color="verdictOf(e)?.winner === v.key ? 'rgb(var(--success-rgb))' : ''"
                                    />
                                    <span class="fs-12 fw-semibold mk-ltr text-end" style="width: 3.5rem">{{ rateOf(e, v.key) != null ? `${rateOf(e, v.key)}%` : '—' }}</span>
                                </div>
                            </div>
                            <p v-if="verdictOf(e)?.text" class="fs-13 fw-semibold mt-2 mb-0" :class="verdictOf(e).verdict === 'winner' ? 'text-success' : 'text-muted'">
                                {{ verdictOf(e).text }}
                            </p>
                            <div v-if="e.status !== 'CONCLUDED' && (canResults || canEdit)" class="mk-actions mt-3">
                                <button v-if="canResults" type="button" class="btn btn-light btn-sm" @click="openRecord(e)">{{ t('marketing.results_add') }}</button>
                                <button v-if="canEdit" type="button" class="btn btn-outline-light text-default btn-sm" :disabled="busy !== null" @click="conclude(e)">
                                    <span v-if="busy === `conclude:${e.id}`" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.conclude') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- New alert rule -->
        <MkModal :show="addingRule" :title="t('marketing.alert_new')" :busy="busy !== null" @close="addingRule = false">
            <MkChips v-model="rule.metric" :options="ALERT_METRICS.map((m) => ({ value: m, label: t(`marketing.kpi.${METRIC_LABEL[m]}`) }))" />
            <div class="mt-3">
                <MkChips v-model="rule.comparison" :options="[{ value: 'above', label: t('marketing.above') }, { value: 'below', label: t('marketing.below') }]" />
            </div>
            <label class="form-label mt-3" for="mk-rule-threshold">{{ t('marketing.threshold') }}</label>
            <div class="input-group">
                <input
                    id="mk-rule-threshold"
                    v-model="rule.value"
                    class="form-control mk-ltr"
                    :class="{ 'is-invalid': rule.value && threshold === null }"
                    inputmode="decimal"
                    maxlength="14"
                >
                <span v-if="unit" class="input-group-text">{{ unit }}</span>
            </div>
            <label class="form-label fw-semibold fs-13 mt-3">{{ t('marketing.days') }}</label>
            <MkChips v-model="rule.window" :options="[1, 7, 14, 30].map((n) => ({ value: n, label: t('marketing.last_n_days', { n }) }))" />
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy !== null" @click="addingRule = false">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-primary" :disabled="busy !== null || threshold === null" @click="saveRule">
                    <span v-if="busy === 'rule'" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.save') }}
                </button>
            </template>
        </MkModal>

        <!-- New A/B test -->
        <MkModal :show="addingTest" :title="t('marketing.experiment_new')" :busy="busy !== null" @close="addingTest = false">
            <label class="form-label" for="mk-exp-name">{{ t('marketing.item_title') }}</label>
            <input id="mk-exp-name" v-model="test.name" class="form-control" maxlength="160">
            <label class="form-label mt-3" for="mk-exp-hyp">{{ t('marketing.hypothesis') }}</label>
            <textarea id="mk-exp-hyp" v-model="test.hypothesis" class="form-control" rows="3" maxlength="500" :placeholder="t('marketing.hypothesis_hint')"></textarea>
            <div class="mt-3">
                <MkChips v-model="test.metric" :options="[{ value: 'ctr', label: t('marketing.kpi.ctr') }, { value: 'conversion_rate', label: t('marketing.conversion_rate') }]" />
            </div>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy !== null" @click="addingTest = false">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-primary" :disabled="busy !== null || test.name.trim().length < 2 || test.hypothesis.trim().length < 5" @click="saveTest">
                    <span v-if="busy === 'test'" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.save') }}
                </button>
            </template>
        </MkModal>

        <!-- Record results for one version -->
        <MkModal :show="recording !== null" :title="recording?.name || ''" :busy="busy !== null" @close="recording = null">
            <MkChips v-model="record.variant" :options="(recording?.variants || []).map((v) => ({ value: v.key, label: t('marketing.variant', { key: v.key }) }))" />
            <div class="row g-2 mt-1">
                <div v-for="k in RESULT_FIELDS" :key="k" class="col-4">
                    <label class="form-label fs-13" :for="`mk-exp-${k}`">{{ t(`marketing.kpi.${k}`) }}</label>
                    <input :id="`mk-exp-${k}`" v-model="record[k]" class="form-control mk-ltr" inputmode="numeric" maxlength="12">
                </div>
            </div>
            <div v-if="recordBad" class="text-danger fs-12 mt-2">{{ t('marketing.clicks_too_many') }}</div>
            <div class="text-muted fs-12 mt-2">{{ t('marketing.experiment_min') }}</div>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy !== null" @click="recording = null">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-primary" :disabled="busy !== null || recordBad || recordEmpty" @click="saveRecord">
                    <span v-if="busy === 'record'" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.save') }}
                </button>
            </template>
        </MkModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { mkMoney, mkNormalizeDigits, mkParseMinor } from './marketingContext';
import useProjectPage from './parts/useProjectPage';
import MkHeader from './parts/MkHeader.vue';
import MkBar from './parts/MkBar.vue';
import MkChips from './parts/MkChips.vue';
import MkModal from './parts/MkModal.vue';
import MkUnavailable from './parts/MkUnavailable.vue';

const ALERT_METRICS = ['cpa', 'roas', 'ctr', 'spend', 'conversions'];
const METRIC_LABEL = { cpa: 'cpa', roas: 'roas', ctr: 'ctr', spend: 'spend', conversions: 'conversions' };
const PRIORITY = { high: 'text-danger', medium: 'text-warning' };
const RESULT_FIELDS = ['impressions', 'clicks', 'conversions'];
const isMoney = (metric) => metric === 'cpa' || metric === 'spend';

/**
 * Optimisation (phase D): suggestions with their numbers, alert rules and A/B tests judged in code. Nothing here changes
 * a budget or a campaign — every line is for a person to decide. Reloads once per push of this project.
 */
const {
    projectId, project, abilities, statusReady, enabled, currency, http, api, to, fail, t, toast, onPush,
} = useProjectPage('optimisation');

const advice = ref(null);
const rules = ref([]);
const experiments = ref([]);
const busy = ref(null);
const addingRule = ref(false);
const addingTest = ref(false);
const recording = ref(null);

const canEdit = computed(() => abilities.value.edit === true);
const canResults = computed(() => abilities.value.results === true);

function metricValue(metric, value) {
    if (value === null || value === undefined) return '—';
    if (isMoney(metric)) return mkMoney(Math.round(Number(value)), currency.value);
    const n = Number(value).toLocaleString('en-US', { maximumFractionDigits: 2 });

    return metric === 'ctr' ? `${n}%` : metric === 'roas' ? `${n}×` : n;
}

const verdictOf = (e) => e.conclusion ?? e.preview ?? null;
const rateOf = (e, key) => verdictOf(e)?.rates?.[key] ?? null;

function barPct(e, key) {
    const rates = Object.values(verdictOf(e)?.rates ?? {}).filter((r) => r != null);
    const max = Math.max(0, ...rates);

    return max > 0 ? ((rateOf(e, key) ?? 0) / max) * 100 : 0;
}

async function load() {
    if (! enabled.value) return;
    try {
        const [a, r, e] = await Promise.all([
            http.get(api(`/projects/${projectId}/optimisations`)),
            http.get(api(`/projects/${projectId}/alert-rules`)),
            http.get(api(`/projects/${projectId}/experiments`)),
        ]);
        advice.value = a.data.data ?? { suggestions: [] };
        rules.value = r.data.data ?? [];
        experiments.value = e.data.data ?? [];
    } catch (error) {
        advice.value = advice.value ?? { suggestions: [] };
        fail(error);
    }
}

async function call(key, request, after) {
    busy.value = key;
    try {
        const { data } = await request();
        if (data?.message) toast.showSuccess(data.message);
        after?.();
    } catch (error) {
        fail(error);
    } finally {
        busy.value = null;
        load();
    }
}

function toggleRule(r, active) {
    return call(`toggle:${r.id}`, () => http.patch(api(`/alert-rules/${r.id}`), { active }));
}

function deleteRule(r) {
    return call(`delete:${r.id}`, () => http.delete(api(`/alert-rules/${r.id}`)));
}

function conclude(e) {
    return call(`conclude:${e.id}`, () => http.post(api(`/experiments/${e.id}/conclude`)));
}

// ---------------------------------------------------------------- alert rule
const rule = reactive({ metric: 'cpa', comparison: 'above', value: '', window: 7 });
const unit = computed(() => (isMoney(rule.metric) ? currency.value : rule.metric === 'ctr' ? '%' : rule.metric === 'roas' ? '×' : ''));
/** Money (cpa, spend) is typed in major units and sent in minor units; the rest as a plain number. */
const threshold = computed(() => {
    if (! String(rule.value).trim()) return null;
    if (isMoney(rule.metric)) return mkParseMinor(rule.value);
    const clean = mkNormalizeDigits(rule.value);

    return /^\d+(\.\d+)?$/.test(clean) ? Number(clean) : null;
});

watch(() => rule.metric, () => { rule.value = ''; });
watch(addingRule, (open) => open && Object.assign(rule, { metric: 'cpa', comparison: 'above', value: '', window: 7 }));

function saveRule() {
    return call('rule', () => http.post(api(`/projects/${projectId}/alert-rules`), {
        metric: rule.metric, comparison: rule.comparison, threshold: threshold.value, window_days: rule.window,
    }), () => { addingRule.value = false; });
}

// ---------------------------------------------------------------- A/B test
const test = reactive({ name: '', hypothesis: '', metric: 'ctr' });

watch(addingTest, (open) => open && Object.assign(test, { name: '', hypothesis: '', metric: 'ctr' }));

function saveTest() {
    return call('test', () => http.post(api(`/projects/${projectId}/experiments`), {
        name: test.name.trim(), hypothesis: test.hypothesis.trim(), success_metric: test.metric,
    }), () => { addingTest.value = false; });
}

// ---------------------------------------------------------------- results per version
const record = reactive({ variant: 'A', impressions: '', clicks: '', conversions: '' });

function num(key) {
    const clean = mkNormalizeDigits(record[key]);

    return /^\d+$/.test(clean) ? Number(clean) : null;
}

const recordEmpty = computed(() => RESULT_FIELDS.every((k) => ! String(record[k] ?? '').trim()));
const recordBad = computed(() => RESULT_FIELDS.some((k) => String(record[k] ?? '').trim() && num(k) === null)
    || (num('clicks') !== null && num('impressions') !== null && num('clicks') > num('impressions'))
    || (num('conversions') !== null && num('clicks') !== null && num('conversions') > num('clicks')));

function fillRecord() {
    const saved = recording.value?.results?.[record.variant] ?? {};
    RESULT_FIELDS.forEach((k) => { record[k] = saved[k] != null ? String(saved[k]) : ''; });
}

function openRecord(e) {
    recording.value = e;
    record.variant = e.variants?.[0]?.key ?? 'A';
    fillRecord();
}

watch(() => record.variant, fillRecord);

function saveRecord() {
    return call('record', () => http.post(api(`/experiments/${recording.value.id}/results`), {
        variant: record.variant, impressions: num('impressions'), clicks: num('clicks'), conversions: num('conversions'),
    }), () => { recording.value = null; });
}

watch(enabled, (on) => on && load());
onPush(load);
onMounted(load);
</script>
