<template>
    <div>
        <MkHeader :title="t('marketing.finance')" :subtitle="plan ? t(planKey(plan.type)) : ''" :back="to('plan', { projectId, planId })">
            <button v-if="version && canEdit" type="button" class="btn btn-primary" :disabled="busy" @click="save">
                <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.save') }}
            </button>
        </MkHeader>

        <div v-if="!version" class="d-grid gap-3">
            <div v-for="i in 3" :key="i" class="mk-skeleton" style="height: 100px"></div>
        </div>

        <div v-else class="row g-3">
            <div class="col-xl-5">
                <div class="alert alert-info fs-13">{{ t('marketing.finance_note') }}</div>
                <div class="card custom-card mk-card">
                    <div class="card-body">
                        <div v-for="key in MONEY_INPUTS" :key="key" class="mb-3">
                            <label class="form-label fs-13" :for="`mk-fin-${key}`">{{ t(`marketing.inputs.${key}`) }}</label>
                            <div class="input-group">
                                <input
                                    :id="`mk-fin-${key}`"
                                    v-model="drafts[key]"
                                    class="form-control mk-ltr"
                                    :class="{ 'is-invalid': invalid[key] }"
                                    inputmode="decimal"
                                    placeholder="0.00"
                                    maxlength="16"
                                    :disabled="!canEdit"
                                >
                                <span v-if="currency" class="input-group-text">{{ currency }}</span>
                            </div>
                        </div>
                        <div v-for="key in COUNT_INPUTS" :key="key" class="mb-3">
                            <label class="form-label fs-13" :for="`mk-fin-${key}`">{{ t(`marketing.inputs.${key}`) }}</label>
                            <input
                                :id="`mk-fin-${key}`"
                                v-model="drafts[key]"
                                class="form-control mk-ltr"
                                :class="{ 'is-invalid': invalid[key] }"
                                inputmode="numeric"
                                placeholder="0"
                                maxlength="9"
                                :disabled="!canEdit"
                            >
                        </div>
                        <div>
                            <label class="form-label fs-13" for="mk-fin-churn">{{ t('marketing.inputs.monthly_churn_pct') }}</label>
                            <div class="input-group">
                                <input
                                    id="mk-fin-churn"
                                    v-model="drafts.monthly_churn_pct"
                                    class="form-control mk-ltr"
                                    :class="{ 'is-invalid': invalid.monthly_churn_pct }"
                                    inputmode="decimal"
                                    placeholder="0"
                                    maxlength="6"
                                    :disabled="!canEdit"
                                >
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div v-if="hasInvalid" class="text-danger fs-13 mt-2">{{ t('marketing.answer_invalid') }}</div>
                        <button v-if="canEdit" type="button" class="btn btn-primary w-100 mt-3" :disabled="busy" @click="save">
                            <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.save') }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-xl-7">
                <h2 class="mk-section-title mt-xl-0">{{ t('marketing.finance_sub') }}</h2>
                <div v-if="!hasScenarios" class="card custom-card mk-card">
                    <div class="card-body text-muted text-center py-5">{{ t('marketing.no_finance') }}</div>
                </div>
                <div v-else class="card custom-card mk-card">
                    <div class="card-body p-0 mk-table-scroll">
                        <table class="table table-sm align-middle mb-0 mk-fin-table">
                            <thead>
                                <tr>
                                    <th scope="col"></th>
                                    <th v-for="s in SCENARIOS" :key="s" scope="col" class="text-center" :class="{ 'text-primary': s === 'base' }">
                                        {{ t(`marketing.scenarios.${s}`) }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="metric in METRICS" :key="metric">
                                    <th scope="row" class="fw-normal text-muted fs-13">{{ t(`marketing.metrics.${metric}`) }}</th>
                                    <td v-for="s in SCENARIOS" :key="s" class="text-center">
                                        <span
                                            class="mk-ltr fw-semibold fs-13"
                                            :class="cellClass(s, metric)"
                                            :title="reasonFor(s, metric)"
                                        >{{ cellText(s, metric) }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer text-muted fs-12">
                        {{ t('marketing.estimated') }}<template v-if="currency"> · {{ currency }}</template>
                    </div>
                </div>
                <SimulationBlock v-if="hasScenarios && feature('optimisation')" :plan-id="planId" :currency="currency" />
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { mkMinorToInput, mkMoney, mkNormalizeDigits, mkParseMinor, planKey, useMk } from './marketingContext';
import MkHeader from './parts/MkHeader.vue';
import SimulationBlock from './parts/SimulationBlock.vue';

const MONEY_INPUTS = ['price_minor', 'variable_cost_minor', 'fixed_costs_minor', 'marketing_spend_minor', 'attributed_revenue_minor'];
const COUNT_INPUTS = ['expected_new_customers', 'expected_conversions'];
const SCENARIOS = ['conservative', 'base', 'optimistic'];
const METRICS = ['contribution_margin_per_unit_minor', 'break_even_units', 'cac_minor', 'cpa_minor', 'roas', 'ltv_minor', 'monthly_profit_minor'];
const REASONS = ['missing_input', 'non_positive_margin', 'no_customers', 'no_conversions', 'no_spend', 'no_churn'];

/**
 * Finance: the person enters figures, the server computes the three scenarios (the AI never does the arithmetic).
 * A metric without what it needs says "N/A" with the reason, never a made-up number. Saving makes a new plan version.
 */
const route = useRoute();
const { projectId, planId } = route.params;
const { ctx, state, http, api, to, fail, t, toast, feature } = useMk();

const plan = ref(null);
const project = ref(null);
const drafts = reactive({});
const invalid = reactive({});
const busy = ref(false);

const version = computed(() => plan.value?.current_version ?? null);
const canEdit = computed(() => project.value?.abilities?.edit === true && plan.value?.status !== 'ARCHIVED');
const currency = computed(() => version.value?.content?.finance?.currency || project.value?.currency || '');
const scenarios = computed(() => version.value?.scenarios ?? {});
const hasScenarios = computed(() => Object.keys(scenarios.value).length > 0);
const hasInvalid = computed(() => Object.values(invalid).some(Boolean));

function fill(p) {
    plan.value = p;
    const inputs = p?.current_version?.scenarios?.base?.inputs ?? {};
    MONEY_INPUTS.forEach((k) => { drafts[k] = inputs[k] != null ? mkMinorToInput(inputs[k]) : ''; });
    COUNT_INPUTS.forEach((k) => { drafts[k] = inputs[k] != null ? String(Math.trunc(inputs[k])) : ''; });
    drafts.monthly_churn_pct = inputs.monthly_churn_pct != null ? String(inputs.monthly_churn_pct) : '';
}

function metric(s, key) {
    return scenarios.value?.[s]?.outputs?.[key] ?? null;
}

function cellText(s, key) {
    const m = metric(s, key);
    if (m?.value === null || m?.value === undefined) return t('marketing.unavailable');
    if (key.endsWith('_minor')) return mkMoney(m.value);
    if (key === 'roas') return `${Number(m.value).toLocaleString('en-US', { maximumFractionDigits: 2 })}×`;

    return Math.trunc(m.value).toLocaleString('en-US');
}

function cellClass(s, key) {
    const m = metric(s, key);
    if (m?.value === null || m?.value === undefined) return 'text-muted fw-normal';

    return m.value < 0 ? 'text-danger' : '';
}

function reasonFor(s, key) {
    const reason = metric(s, key)?.reason;

    return reason && REASONS.includes(reason) ? t(`marketing.reasons.${reason}`) : '';
}

function collect() {
    Object.keys(invalid).forEach((k) => delete invalid[k]);
    const inputs = {};
    MONEY_INPUTS.forEach((k) => {
        const text = String(drafts[k] ?? '').trim();
        if (! text) { inputs[k] = null; return; }
        const minor = mkParseMinor(text);
        if (minor === null) invalid[k] = true; else inputs[k] = minor;
    });
    COUNT_INPUTS.forEach((k) => {
        const text = mkNormalizeDigits(drafts[k]);
        if (! text) { inputs[k] = null; return; }
        if (/^\d+$/.test(text)) inputs[k] = Number(text); else invalid[k] = true;
    });
    const churn = mkNormalizeDigits(drafts.monthly_churn_pct);
    if (! churn) {
        inputs.monthly_churn_pct = null;
    } else {
        const n = /^\d+(\.\d+)?$/.test(churn) ? Number(churn) : NaN;
        if (Number.isFinite(n) && n >= 0 && n <= 100) inputs.monthly_churn_pct = n; else invalid.monthly_churn_pct = true;
    }

    return hasInvalid.value ? null : inputs;
}

async function save() {
    const inputs = collect();
    if (! inputs) return;
    busy.value = true;
    try {
        const { data } = await http.put(api(`/plans/${planId}/finance`), { version: version.value.version, inputs });
        fill({ ...data.data, versions_count: data.data?.versions_count ?? plan.value?.versions_count });
        toast.showSuccess(data.message || t('marketing.saved'));
    } catch (error) {
        fail(error);
    } finally {
        busy.value = false;
    }
}

onMounted(async () => {
    if (! state.status) {
        ctx.loadStatus();
    }
    try {
        const [p, pr] = await Promise.all([
            http.get(api(`/plans/${planId}`)),
            http.get(api(`/projects/${projectId}`)),
        ]);
        fill(p.data.data);
        project.value = pr.data.data;
    } catch (error) {
        fail(error);
    }
});
</script>

<style>
.mk-fin-table th,
.mk-fin-table td {
    padding: 0.75rem 0.875rem;
    white-space: nowrap;
}

.mk-fin-table tbody th {
    white-space: normal;
    min-width: 9rem;
}
</style>
