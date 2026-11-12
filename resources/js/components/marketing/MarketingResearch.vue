<template>
    <div>
        <MkHeader :title="run ? t(kindKey(run.kind)) : t('marketing.research')" :subtitle="run ? date(run.created_at) : ''" :back="to('project', { projectId })">
            <MkStatusBadge v-if="run" :status="run.status" class="align-self-center" />
        </MkHeader>

        <div v-if="!run" class="d-grid gap-3">
            <div v-for="i in 3" :key="i" class="mk-skeleton" style="height: 120px"></div>
        </div>

        <div v-else class="row g-3">
            <div class="col-xl-8">
                <div class="card custom-card mk-card mb-3">
                    <div class="card-body">
                        <p v-if="run.summary" class="mk-prewrap mb-0">{{ run.summary }}</p>
                        <p v-else-if="!isFinished(run.status)" class="text-muted mb-0">
                            <span class="spinner-border spinner-border-sm me-2"></span>{{ t(statusKey(run.status)) }}
                        </p>
                        <p v-else-if="run.error_code" class="text-danger mb-0">{{ t('marketing.job_failed', { reason: jobError(run.error_code) }) }}</p>
                        <template v-if="run.quality_score != null">
                            <div class="d-flex justify-content-between fs-13 mt-3 mb-1">
                                <span class="text-muted">{{ t('marketing.quality') }}</span>
                                <span class="fw-semibold mk-ltr">{{ run.quality_score }}%</span>
                            </div>
                            <MkBar :value="run.quality_score" :color="qualityColor" />
                        </template>
                    </div>
                </div>

                <div v-if="run.gaps?.length" class="alert alert-warning mb-3">
                    <div class="fw-semibold mb-1"><i class="ri-error-warning-line me-1"></i>{{ t('marketing.gaps') }}</div>
                    <ul class="mk-bullets fs-13 mb-0">
                        <li v-for="(gap, i) in run.gaps" :key="i">{{ gap }}</li>
                    </ul>
                </div>

                <h2 class="mk-section-title">{{ t('marketing.findings') }}</h2>
                <div v-if="!findings.length" class="card custom-card mk-card">
                    <div class="card-body text-muted text-center py-4">
                        {{ isFinished(run.status) ? t('marketing.no_findings') : t(statusKey(run.status)) }}
                    </div>
                </div>
                <div v-else class="d-grid gap-2">
                    <div v-for="f in findings" :key="f.id ?? f.claim" class="card custom-card mk-card mb-0">
                        <div class="card-body">
                            <p class="mb-2 mk-prewrap">{{ f.claim }}</p>
                            <div class="d-flex flex-wrap gap-1">
                                <span v-if="f.is_assumption" class="badge bg-warning-transparent">{{ t('marketing.assumption') }}</span>
                                <span class="badge bg-light text-muted">{{ t(`marketing.confidence.${['high', 'medium'].includes(f.confidence) ? f.confidence : 'low'}`) }}</span>
                                <button
                                    v-for="sref in f.sources"
                                    :key="sref"
                                    type="button"
                                    class="badge bg-primary-transparent border-0"
                                    @click="showSource(sref)"
                                >{{ sref }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <h2 class="mk-section-title mt-xl-0">{{ t('marketing.sources') }}</h2>
                <div v-if="!sources.length" class="text-muted fs-13">{{ t('marketing.no_sources') }}</div>
                <div v-else class="card custom-card mk-card">
                    <ul class="list-group list-group-flush">
                        <li v-for="s in sources" :id="`mk-src-${s.ref}`" :key="s.ref" class="list-group-item" :class="{ 'bg-primary-transparent': highlighted === s.ref }">
                            <div class="d-flex gap-2 align-items-start">
                                <span class="badge bg-primary-transparent mt-1">{{ s.ref }}</span>
                                <div class="flex-grow-1 min-w-0">
                                    <a :href="s.url" target="_blank" rel="noopener noreferrer nofollow" class="fw-semibold d-block text-break">
                                        {{ s.title || s.publisher || s.url }}
                                        <i class="ri-external-link-line fs-12"></i>
                                    </a>
                                    <div class="text-muted fs-12">{{ sourceMeta(s) }}</div>
                                    <p v-if="s.excerpt" class="fs-12 mb-0 mt-1 text-muted fst-italic text-break">“{{ s.excerpt }}”</p>
                                </div>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { isFinished, kindKey, relevantChange, statusKey, useMk } from './marketingContext';
import MkHeader from './parts/MkHeader.vue';
import MkStatusBadge from './parts/MkStatusBadge.vue';
import MkBar from './parts/MkBar.vue';
import useJobErrors from './parts/useJobErrors';

/** One research run: summary, evidence quality, what the sources don't cover, findings with their sources. */
const route = useRoute();
const { projectId, runId } = route.params;
const { state, http, api, to, fail, date, t } = useMk();
const jobError = useJobErrors();

const run = ref(null);
const findings = computed(() => run.value?.findings ?? []);
const sources = computed(() => run.value?.sources ?? []);
const qualityColor = computed(() => {
    const q = run.value?.quality_score ?? 0;
    if (q >= 70) return 'var(--success-color, #26bf94)';
    if (q >= 40) return 'var(--warning-color, #f5b849)';

    return 'var(--danger-color, #e6533c)';
});

function sourceMeta(s) {
    return [
        s.publisher,
        s.published_at ? t('marketing.published', { date: s.published_at }) : null,
        s.retrieved_at ? t('marketing.retrieved', { date: date(s.retrieved_at) }) : null,
    ].filter(Boolean).join(' · ');
}

const highlighted = ref(null);

function showSource(sref) {
    highlighted.value = sref;
    document.getElementById(`mk-src-${sref}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

async function load() {
    try {
        const { data } = await http.get(api(`/research-runs/${runId}`));
        run.value = data.data;
    } catch (error) {
        fail(error);
    }
}

watch(() => state.changes, () => {
    if (relevantChange(state, projectId)) {
        load();
    }
});

onMounted(load);
</script>
