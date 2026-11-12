<template>
    <div class="d-grid gap-3">
        <Block v-if="str(c.summary)" :title="t('marketing.summary')"><p class="mk-prewrap mb-0">{{ c.summary }}</p></Block>

        <template v-if="type === 'business'">
            <Block v-for="(s, i) in objects(c.sections)" :key="`s${i}`" :title="str(s.title) || str(s.key) || ''">
                <p class="mk-prewrap mb-0">{{ s.body }}</p>
            </Block>
        </template>

        <template v-else-if="type === 'marketing'">
            <Block v-if="str(c.positioning)" :title="t('marketing.plan.positioning')"><p class="mk-prewrap mb-0">{{ c.positioning }}</p></Block>
            <Block v-if="objects(c.segments).length" :title="t('marketing.plan.segments')">
                <div v-for="(r, i) in objects(c.segments)" :key="`g${i}`" :class="{ 'mt-3': i > 0 }">
                    <div class="fw-semibold">{{ r.name }}</div>
                    <div v-if="str(r.description)" class="text-muted fs-13 mk-prewrap">{{ r.description }}</div>
                </div>
            </Block>
            <Block v-if="objects(c.channels).length" :title="t('marketing.plan.channels')">
                <div v-for="(r, i) in objects(c.channels)" :key="`c${i}`" class="py-2" :class="{ 'border-top': i > 0 }">
                    <div class="d-flex align-items-center gap-2">
                        <span class="fw-semibold flex-grow-1">{{ r.channel }}</span>
                        <span v-if="isNum(r.budget_share_pct)" class="badge bg-primary-transparent mk-ltr">{{ Math.round(r.budget_share_pct) }}%</span>
                    </div>
                    <div v-if="str(r.rationale)" class="text-muted fs-13 mk-prewrap">{{ r.rationale }}</div>
                </div>
            </Block>
            <Block v-if="strings(c.messages).length" :title="t('marketing.plan.messages')"><Bullets :items="strings(c.messages)" /></Block>
            <Block v-if="objects(c.kpis).length" :title="t('marketing.plan.kpis')">
                <div v-for="(r, i) in objects(c.kpis)" :key="`k${i}`" class="d-flex justify-content-between gap-3 py-1" :class="{ 'border-top': i > 0 }">
                    <span class="text-muted">{{ r.name }}</span>
                    <span class="fw-semibold text-end">{{ r.target }}</span>
                </div>
            </Block>
        </template>

        <template v-else>
            <div v-if="isNum(c.horizon_days) || strings(c.channels).length" class="d-flex flex-wrap gap-1">
                <span v-if="isNum(c.horizon_days)" class="badge bg-primary-transparent">{{ t('marketing.plan.horizon', { days: c.horizon_days }) }}</span>
                <span v-for="ch in strings(c.channels)" :key="ch" class="badge bg-light text-muted">{{ ch }}</span>
            </div>
            <Block v-for="(phase, i) in objects(c.phases)" :key="`p${i}`" :title="phaseTitle(phase)">
                <template v-if="strings(phase.tasks).length">
                    <div class="text-muted fs-12 fw-semibold mb-1">{{ t('marketing.plan.tasks') }}</div>
                    <Bullets :items="strings(phase.tasks)" />
                </template>
                <template v-if="strings(phase.kpis).length">
                    <div class="text-muted fs-12 fw-semibold mb-1 mt-2">{{ t('marketing.plan.kpis') }}</div>
                    <Bullets :items="strings(phase.kpis)" />
                </template>
                <template v-if="strings(phase.go_no_go_criteria).length">
                    <div class="text-muted fs-12 fw-semibold mb-1 mt-2">{{ t('marketing.plan.go_no_go') }}</div>
                    <Bullets :items="strings(phase.go_no_go_criteria)" />
                </template>
            </Block>
        </template>

        <Block v-if="strings(c.assumptions).length" :title="t('marketing.plan.assumptions')"><Bullets :items="strings(c.assumptions)" /></Block>
        <Block v-if="strings(c.risks).length" :title="t('marketing.plan.risks')"><Bullets :items="strings(c.risks)" /></Block>
        <Block v-if="strings(c.confidence_notes).length" :title="t('marketing.plan.confidence_notes')"><Bullets :items="strings(c.confidence_notes)" class="text-muted" /></Block>
        <p v-if="strings(c.sources).length" class="text-muted fs-12 mb-0">{{ t('marketing.plan.cited_sources', { refs: strings(c.sources).join(', ') }) }}</p>
    </div>
</template>

<script setup>
import { computed, defineComponent, h } from 'vue';
import { useI18n } from 'vue-i18n';

/** A plan version's content laid out by plan type; every list shows only when it has items. */
const props = defineProps({
    type: { type: String, required: true },
    content: { type: Object, default: () => ({}) },
});

const { t } = useI18n();
const c = computed(() => props.content ?? {});

const str = (v) => (typeof v === 'string' && v.trim() !== '' ? v : null);
const isNum = (v) => typeof v === 'number' && Number.isFinite(v);
const strings = (v) => (Array.isArray(v) ? v.filter((x) => typeof x === 'string' && x.trim() !== '') : []);
const objects = (v) => (Array.isArray(v) ? v.filter((x) => x && typeof x === 'object' && ! Array.isArray(x)) : []);

function phaseTitle(phase) {
    const days = isNum(phase.day_from) && isNum(phase.day_to) ? t('marketing.plan.phase_days', { from: phase.day_from, to: phase.day_to }) : null;

    return [days, str(phase.title)].filter(Boolean).join(' · ');
}

const Block = defineComponent({
    props: { title: { type: String, default: '' } },
    setup(blockProps, { slots }) {
        return () => h('div', { class: 'card custom-card mk-card mb-0' }, [
            h('div', { class: 'card-body' }, [
                blockProps.title ? h('h3', { class: 'fs-15 fw-semibold mb-2' }, blockProps.title) : null,
                slots.default?.(),
            ]),
        ]);
    },
});

const Bullets = defineComponent({
    props: { items: { type: Array, default: () => [] } },
    setup(bulletProps) {
        return () => h('ul', { class: 'mk-bullets mb-0' }, bulletProps.items.map((item, i) => h('li', { key: i }, item)));
    },
});
</script>
