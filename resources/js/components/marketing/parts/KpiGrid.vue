<template>
    <div class="row g-2">
        <div v-for="tile in tiles" :key="tile.key" class="col-6 col-md-4 col-xxl-2">
            <div class="card custom-card mk-card h-100 mb-0">
                <div class="card-body py-3">
                    <div class="text-muted fs-12 text-truncate">{{ t(`marketing.kpi.${tile.key}`) }}</div>
                    <div class="fs-18 fw-bold mk-ltr text-truncate" :class="{ 'text-muted': tile.value === '—' }">{{ tile.value }}</div>
                    <div v-if="tile.reason" class="text-muted fs-11">{{ t(`marketing.kpi_reasons.${REASONS.includes(tile.reason) ? tile.reason : 'missing_data'}`) }}</div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { mkMoney } from '../marketingContext';

/** Six tiles (totals and rates); a rate that can't be computed says why — a missing number is never shown as 0. */
const props = defineProps({
    group: { type: Object, default: () => ({}) },
    currency: { type: String, default: '' },
});

const REASONS = ['missing_data', 'no_impressions', 'no_clicks', 'no_conversions', 'no_spend', 'no_revenue_data'];
const { t } = useI18n();

function rate(r, suffix) {
    if (r?.value === null || r?.value === undefined) return '—';

    return `${Number(r.value).toLocaleString('en-US', { maximumFractionDigits: 2 })}${suffix}`;
}

const tiles = computed(() => {
    const totals = props.group?.totals ?? {};
    const rates = props.group?.rates ?? {};
    const reason = (r) => (r && (r.value === null || r.value === undefined) ? r.reason ?? 'missing_data' : null);

    return [
        { key: 'spend', value: totals.spend_minor != null ? mkMoney(totals.spend_minor, props.currency) : '—', reason: null },
        { key: 'revenue', value: totals.revenue_minor != null ? mkMoney(totals.revenue_minor, props.currency) : '—', reason: null },
        { key: 'roas', value: rate(rates.roas, '×'), reason: reason(rates.roas) },
        { key: 'cpa', value: rates.cpa_minor?.value != null ? mkMoney(rates.cpa_minor.value, props.currency) : '—', reason: reason(rates.cpa_minor) },
        { key: 'ctr', value: rate(rates.ctr_pct, '%'), reason: reason(rates.ctr_pct) },
        { key: 'conversions', value: totals.conversions != null ? Number(totals.conversions).toLocaleString('en-US') : '—', reason: null },
    ];
});
</script>
