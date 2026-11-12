<template>
    <div>
        <MkHeader :title="t('marketing.report')" :subtitle="project?.name || ''" :back="to('project', { projectId })" />

        <MkUnavailable v-if="statusReady && !enabled" />
        <template v-else>
            <MkChips v-model="period" :options="['day', 'week', 'month'].map((p) => ({ value: p, label: t(`marketing.periods.${p}`) }))" />

            <div v-if="!report && failed" class="card custom-card mk-card mt-3">
                <div class="card-body text-center py-5">
                    <p class="mb-3">{{ t('marketing.error_generic') }}</p>
                    <button type="button" class="btn btn-light" @click="load">{{ t('marketing.retry') }}</button>
                </div>
            </div>
            <div v-else-if="!report" class="d-grid gap-3 mt-3">
                <div v-for="i in 3" :key="i" class="mk-skeleton" style="height: 96px"></div>
            </div>
            <template v-else>
                <p class="text-muted fs-13 mt-2 mb-0">{{ day(report.from) }} – {{ day(report.to) }}</p>
                <div class="row g-3">
                    <div class="col-xl-6">
                        <h2 class="mk-section-title">{{ t('marketing.decisions') }}</h2>
                        <ReportLines :lines="report.decisions || []" icon="ri-task-line" :empty="t('marketing.nothing_to_decide')" :link="lineLink" />

                        <h2 class="mk-section-title">{{ t('marketing.report_risks') }}</h2>
                        <ReportLines :lines="report.risks || []" icon="ri-error-warning-line" :empty="t('marketing.no_risks')" />

                        <h2 class="mk-section-title">{{ t('marketing.next_steps') }}</h2>
                        <ReportLines :lines="report.recommendations || []" icon="ri-lightbulb-line" :empty="t('marketing.nothing_to_suggest')" />
                    </div>
                    <div class="col-xl-6">
                        <div class="d-flex align-items-center gap-2 mk-section-title">
                            <span class="flex-grow-1">{{ t('marketing.results') }}</span>
                            <span class="badge bg-success-transparent">{{ t('marketing.basis_imported') }}</span>
                        </div>
                        <p v-if="!report.results?.days_with_data" class="text-muted fs-13">{{ t('marketing.no_results_sub') }}</p>
                        <template v-else>
                            <div class="card custom-card mk-card mb-1">
                                <ul class="list-group list-group-flush">
                                    <li v-for="row in resultRows" :key="row.key" class="list-group-item d-flex align-items-center gap-2">
                                        <span class="text-muted flex-grow-1">{{ t(`marketing.kpi.${row.label}`) }}</span>
                                        <span class="fw-bold mk-ltr">{{ row.value }}</span>
                                        <span v-if="row.change != null" class="badge mk-ltr" :class="row.change >= 0 ? 'bg-success-transparent' : 'bg-danger-transparent'">
                                            {{ row.change >= 0 ? '▲' : '▼' }} {{ Math.abs(row.change) }}%
                                        </span>
                                    </li>
                                </ul>
                            </div>
                            <p class="text-muted fs-12">{{ t('marketing.vs_previous') }}</p>
                        </template>
                        <p v-if="report.note" class="text-muted fs-12 mt-3 mb-0">{{ report.note }}</p>
                    </div>
                </div>
            </template>
        </template>
    </div>
</template>

<script setup>
import { computed, defineComponent, h, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { mkMoney } from './marketingContext';
import useProjectPage from './parts/useProjectPage';
import MkHeader from './parts/MkHeader.vue';
import MkChips from './parts/MkChips.vue';
import MkUnavailable from './parts/MkUnavailable.vue';

/** Executive report (U12): today's decisions, the period's results against the one before, risks, next steps — rules only. */
const {
    projectId, project, statusReady, enabled, http, api, to, fail, day, t, onPush,
} = useProjectPage('results');

const period = ref('week');
const report = ref(null);
const failed = ref(false);

const resultRows = computed(() => {
    const totals = report.value?.results?.current?.totals ?? {};
    const changes = report.value?.results?.change_pct ?? {};
    const cur = report.value?.currency ?? '';

    return [
        { key: 'spend_minor', label: 'spend' },
        { key: 'revenue_minor', label: 'revenue' },
        { key: 'conversions', label: 'conversions' },
        { key: 'clicks', label: 'clicks' },
    ].map((r) => ({
        ...r,
        value: r.key.endsWith('_minor') ? mkMoney(totals[r.key], cur) : (totals[r.key] ?? '—'),
        change: changes[r.key] ?? null,
    }));
});

function lineLink(line) {
    return line.item_id ? to('content', { projectId, itemId: line.item_id }) : null;
}

async function load() {
    if (! enabled.value) return;
    try {
        const { data } = await http.get(api(`/projects/${projectId}/executive-report`), { params: { period: period.value } });
        report.value = data.data;
        failed.value = false;
    } catch (error) {
        failed.value = report.value === null;
        fail(error);
    }
}

const TONES = { high: 'text-danger', medium: 'text-warning', action: 'text-warning', low: 'text-muted' };

const ReportLines = defineComponent({
    props: { lines: { type: Array, default: () => [] }, icon: { type: String, default: '' }, empty: { type: String, default: '' }, link: { type: Function, default: null } },
    setup(props) {
        return () => {
            if (! props.lines.length) return h('p', { class: 'text-muted fs-13 mb-0' }, props.empty);

            return h('div', { class: 'card custom-card mk-card mb-0' }, [
                h('ul', { class: 'list-group list-group-flush' }, props.lines.map((line, i) => {
                    const target = props.link?.(line);
                    const body = [
                        h('i', { class: `${props.icon} ${TONES[line.level] ?? 'text-info'} fs-16` }),
                        h('span', { class: 'flex-grow-1 fs-13 lh-base' }, line.text),
                    ];
                    if (target) body.push(h('i', { class: 'ri-arrow-right-s-line mk-flip text-muted' }));

                    return h('li', { key: `${line.code}-${i}`, class: 'list-group-item' }, [
                        target
                            ? h(RouterLink, { to: target, class: 'd-flex gap-2 align-items-start text-reset text-decoration-none' }, () => body)
                            : h('div', { class: 'd-flex gap-2 align-items-start' }, body),
                    ]);
                })),
            ]);
        };
    },
});

watch(period, () => {
    report.value = null;
    load();
});
watch(enabled, (on) => on && load());
onPush(load);
onMounted(load);
</script>
