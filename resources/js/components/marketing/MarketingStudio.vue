<template>
    <div>
        <MkHeader :title="t('marketing.studio')" :subtitle="project?.name || ''" :back="to('project', { projectId })" />

        <MkUnavailable v-if="statusReady && !enabled" />
        <div v-else class="row g-3">
            <div class="col-xl-7">
                <div class="alert alert-info fs-13">{{ t('marketing.studio_note') }}</div>
                <div class="card custom-card mk-card">
                    <div class="card-body">
                        <label class="form-label fw-semibold fs-13">{{ t('marketing.channel') }}</label>
                        <MkChips v-model="form.channel" :options="channelOptions" :disabled="!canEdit" />
                        <label class="form-label fw-semibold fs-13 mt-3">{{ t('marketing.format') }}</label>
                        <MkChips v-model="form.format" :options="FORMATS.map((f) => ({ value: f, label: t(formatKey(f)) }))" :disabled="!canEdit" />
                        <div class="d-flex flex-wrap gap-3 mt-3">
                            <div>
                                <label class="form-label fw-semibold fs-13">{{ t('marketing.kind') }}</label>
                                <MkChips v-model="form.kind" :options="['ad', 'post'].map((k) => ({ value: k, label: t(contentKindKey(k)) }))" :disabled="!canEdit" />
                            </div>
                            <div>
                                <label class="form-label fw-semibold fs-13">{{ t('marketing.variants') }}</label>
                                <MkChips v-model="form.variants" :options="[2, 3].map((n) => ({ value: n, label: t('marketing.n_variants', { n }) }))" :disabled="!canEdit" />
                            </div>
                        </div>
                        <label class="form-label mt-3" for="mk-studio-objective">{{ t('marketing.objective') }}</label>
                        <input id="mk-studio-objective" v-model="form.objective" class="form-control" maxlength="300" :placeholder="t('marketing.objective_hint')" :disabled="!canEdit">
                        <label class="form-label mt-3" for="mk-studio-brief">{{ t('marketing.brief') }}</label>
                        <textarea id="mk-studio-brief" v-model="form.brief" class="form-control" rows="5" maxlength="1000" :placeholder="t('marketing.brief_hint')" :disabled="!canEdit"></textarea>
                        <div class="text-muted fs-11 text-end mk-ltr">{{ form.brief.length }}/1000</div>
                        <button
                            v-if="canEdit"
                            type="button"
                            class="btn btn-primary w-100 mt-2"
                            :disabled="busy || waiting !== null || !!running || form.brief.trim().length < 5"
                            @click="write"
                        >
                            <span v-if="busy || waiting !== null" class="spinner-border spinner-border-sm me-1"></span>
                            <i v-else class="ri-sparkling-2-line me-1"></i>{{ t('marketing.studio_write') }}
                        </button>
                    </div>
                </div>
                <div v-if="running || waiting" class="card custom-card mk-card mt-3">
                    <div class="card-body">
                        <div class="fw-semibold fs-13 mb-2">{{ t('marketing.studio_writing') }}</div>
                        <MkBar :value="running?.progress_pct ?? 10" />
                    </div>
                </div>
            </div>

            <div class="col-xl-5">
                <h2 class="mk-section-title mt-xl-0">{{ t('marketing.drafts') }}</h2>
                <div v-if="drafts === null" class="mk-skeleton" style="height: 76px"></div>
                <p v-else-if="drafts.length === 0" class="text-muted fs-13">{{ t('marketing.no_drafts') }}</p>
                <div v-else class="d-grid gap-2">
                    <ContentRow v-for="d in drafts" :key="d.id" :item="d" :show-time="false" :to="to('content', { projectId, itemId: d.id })" />
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { CHANNELS, FORMATS, channelKey, contentKindKey, formatKey, isFinished } from './marketingContext';
import useProjectPage from './parts/useProjectPage';
import MkHeader from './parts/MkHeader.vue';
import MkBar from './parts/MkBar.vue';
import MkChips from './parts/MkChips.vue';
import MkUnavailable from './parts/MkUnavailable.vue';
import ContentRow from './parts/ContentRow.vue';

/**
 * The ad studio (U07): a brief in, A/B variants out — the AI writes, the person reviews. When the job finishes (one
 * `marketing.job.changed` push, or the catch-up on open) its `created` ids open the new draft.
 */
const {
    projectId, project, abilities, statusReady, enabled, active, http, api, to, fail, t, toast, router, onPush,
} = useProjectPage('content');

const form = reactive({ channel: 'instagram', format: 'image', kind: 'ad', variants: 2, objective: '', brief: '' });
const busy = ref(false);
const waiting = ref(null);
const jobs = ref([]);
const drafts = ref(null);

const canEdit = computed(() => abilities.value.edit === true && active.value);
const running = computed(() => jobs.value.find((j) => j.type === 'content' && ! isFinished(j.status)) ?? null);
const channelOptions = computed(() => CHANNELS.filter((c) => ! ['offline', 'other', 'website'].includes(c)).map((c) => ({ value: c, label: t(channelKey(c)) })));

async function load() {
    if (! enabled.value) return;
    try {
        const [j, c] = await Promise.all([
            http.get(api(`/projects/${projectId}/jobs`)),
            http.get(api(`/projects/${projectId}/content`), { params: { status: 'DRAFT,POLICY_REVIEW,REJECTED' } }),
        ]);
        jobs.value = j.data.data ?? [];
        drafts.value = (c.data.data ?? []).filter((it) => it.kind !== 'task');

        const mine = waiting.value ? jobs.value.find((job) => job.id === waiting.value) : null;
        if (mine && isFinished(mine.status)) {
            waiting.value = null;
            const created = Array.isArray(mine.created) ? mine.created[0] : null;
            if (mine.status === 'COMPLETED' && created) {
                router.push(to('content', { projectId, itemId: created }));
            } else {
                toast.showError(t('marketing.error_generic'));
            }
        }
    } catch (error) {
        drafts.value = drafts.value ?? [];
        fail(error);
    }
}

async function write() {
    busy.value = true;
    try {
        const { data } = await http.post(api(`/projects/${projectId}/content/generate`), {
            channel: form.channel,
            brief: form.brief.trim(),
            kind: form.kind,
            format: form.format,
            objective: form.objective.trim() || undefined,
            variants: form.variants,
        });
        waiting.value = data.data?.job?.id ?? null;
        if (data.data?.job) jobs.value = [data.data.job, ...jobs.value];
        toast.showSuccess(t('marketing.studio_started'));
    } catch (error) {
        fail(error);
    } finally {
        busy.value = false;
    }
}

watch(enabled, (on) => on && load());
onPush(load);
onMounted(load);
</script>
