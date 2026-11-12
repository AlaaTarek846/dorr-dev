<template>
    <div>
        <h2 class="mk-section-title">{{ t('marketing.simulation') }}</h2>
        <div class="card custom-card mk-card">
            <div class="card-body">
                <p class="text-muted fs-13">{{ t('marketing.simulation_note') }}</p>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <MkChips v-model="swing" :options="[10, 20, 30].map((s) => ({ value: s, label: `±${s}%` }))" />
                    <button type="button" class="btn btn-primary btn-sm ms-auto" :disabled="busy" @click="simulate">
                        <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.simulate') }}
                    </button>
                </div>
                <template v-if="sim">
                    <p v-if="!sim.tornado?.length" class="text-muted fs-13 mt-3 mb-0">{{ t('marketing.no_finance') }}</p>
                    <template v-else>
                        <div class="fw-semibold fs-13 mt-3">{{ t('marketing.base_profit', { amount: mkMoney(sim.base_profit_minor, currency) }) }}</div>
                        <div v-for="row in sim.tornado" :key="row.input" class="mt-2">
                            <div class="fs-13 fw-semibold">{{ inputLabel(row.input) }}</div>
                            <MkBar :value="(row.swing_minor / maxSwing) * 100" color="rgb(var(--warning-rgb))" />
                            <div class="text-muted fs-12 mk-ltr d-inline-block">{{ mkMoney(row.low_profit_minor) }} … {{ mkMoney(row.high_profit_minor) }}</div>
                        </div>
                    </template>
                </template>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { mkMoney, useMk } from '../marketingContext';
import MkBar from './MkBar.vue';
import MkChips from './MkChips.vue';

/** On the finance page: which input moves the monthly profit most (each input ± swing, from the plan's own inputs). */
const props = defineProps({
    planId: { type: String, required: true },
    currency: { type: String, default: '' },
});

const { http, api, fail, t } = useMk();
const swing = ref(20);
const sim = ref(null);
const busy = ref(false);
const KNOWN_INPUTS = ['price_minor', 'variable_cost_minor', 'fixed_costs_minor', 'marketing_spend_minor', 'attributed_revenue_minor', 'expected_new_customers', 'expected_conversions', 'monthly_churn_pct'];

function inputLabel(key) {
    if (KNOWN_INPUTS.includes(key)) return t(`marketing.inputs.${key}`);

    return key === 'units_per_customer' ? t('marketing.units_per_customer') : key;
}

const maxSwing =computed(() => Math.max(1, ...(sim.value?.tornado ?? []).map((r) => r.swing_minor ?? 0)));

async function simulate() {
    busy.value = true;
    try {
        const { data } = await http.post(api(`/plans/${props.planId}/simulate`), { swing_pct: swing.value });
        sim.value = data.data;
    } catch (error) {
        fail(error);
    } finally {
        busy.value = false;
    }
}
</script>
