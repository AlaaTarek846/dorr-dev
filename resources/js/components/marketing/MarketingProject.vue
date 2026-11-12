<template>
    <div>
        <MkHeader :title="project?.name || t('marketing.title')" :back="to('home')">
            <span v-if="project" class="badge bg-primary-transparent align-self-center">{{ t(roleKey(project.my_role)) }}</span>
        </MkHeader>

        <div v-if="!project && failed" class="card custom-card mk-card">
            <div class="card-body text-center py-5">
                <p class="mb-3">{{ t('marketing.error_generic') }}</p>
                <button type="button" class="btn btn-light" @click="load">{{ t('marketing.retry') }}</button>
            </div>
        </div>
        <div v-else-if="!project" class="d-grid gap-3">
            <div v-for="i in 4" :key="i" class="mk-skeleton" style="height: 84px"></div>
        </div>

        <div v-else class="row g-3">
            <!-- Left: profile, discovery, team, owner actions -->
            <div class="col-xl-4 col-lg-5">
                <div class="card custom-card mk-card mb-3">
                    <div class="card-body">
                        <div class="d-flex align-items-start gap-2">
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold fs-15">{{ project.industry }}</div>
                                <div class="text-muted fs-13">{{ place }}</div>
                            </div>
                            <span v-if="project.status === 'archived'" class="badge bg-light text-muted">{{ t('marketing.archived_badge') }}</span>
                        </div>
                        <div v-if="project.profile?.monthly_budget_minor != null" class="mt-2">
                            <span class="text-muted fs-12 d-block">{{ t('marketing.monthly_budget') }}</span>
                            <span class="fw-bold fs-16 text-primary mk-ltr">{{ mkMoney(project.profile.monthly_budget_minor, project.currency) }}</span>
                        </div>
                    </div>
                </div>

                <MkNavCard
                    class="mb-3"
                    icon="ri-questionnaire-line"
                    :tone="discovery?.complete ? 'success' : 'warning'"
                    :title="t('marketing.discovery')"
                    :subtitle="discovery ? (discovery.complete ? t('marketing.discovery_done') : t('marketing.discovery_missing')) : ''"
                    :to="to('discovery', { projectId })"
                >
                    <template #trailing>
                        <span v-if="discovery" class="badge mk-ltr" :class="discovery.complete ? 'bg-success-transparent' : 'bg-warning-transparent'">
                            {{ discovery.answered }}/{{ discovery.total }}
                        </span>
                    </template>
                </MkNavCard>

                <MkNavCard
                    class="mb-3"
                    icon="ri-team-line"
                    tone="info"
                    :title="t('marketing.team')"
                    :subtitle="t('marketing.team_sub', { count: project.members_count ?? 1 })"
                    :to="to('team', { projectId })"
                />

                <!-- Execution (phase B) and ads (phase C): each card behind its country switch. -->
                <template v-if="execCards.length">
                    <h2 class="mk-section-title">{{ t('marketing.execution') }}</h2>
                    <div class="d-grid gap-2 mb-3">
                        <MkNavCard
                            v-for="card in execCards"
                            :key="card.page"
                            :icon="card.icon"
                            :tone="card.tone"
                            :title="t(`marketing.${card.page}`)"
                            :subtitle="t(`marketing.${card.page}_sub`)"
                            :to="to(card.page, { projectId })"
                        />
                    </div>
                </template>

                <div v-if="abilities?.manage_team" class="mk-actions">
                    <button type="button" class="btn btn-light btn-sm" :disabled="archiving" @click="toggleArchive">
                        <span v-if="archiving" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else :class="archived ? 'ri-inbox-unarchive-line' : 'ri-archive-line'" class="me-1"></i>
                        {{ archived ? t('marketing.unarchive') : t('marketing.archive') }}
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm" @click="confirmDelete = true">
                        <i class="ri-delete-bin-line me-1"></i>{{ t('marketing.delete') }}
                    </button>
                </div>
            </div>

            <!-- Right: research and plans -->
            <div class="col-xl-8 col-lg-7">
                <h2 class="mk-section-title mt-0">{{ t('marketing.research') }}</h2>
                <div v-if="abilities?.research && !archived" class="mk-actions mb-3">
                    <button
                        v-for="kind in RESEARCH_KINDS"
                        :key="kind"
                        type="button"
                        class="btn btn-outline-primary btn-sm"
                        :disabled="startingKind !== null"
                        @click="startResearch(kind)"
                    >
                        <span v-if="startingKind === kind" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="ri-search-eye-line me-1"></i>
                        {{ t('marketing.research_start', { kind: t(kindKey(kind)) }) }}
                    </button>
                </div>
                <p v-if="runs.length === 0" class="text-muted fs-13">{{ t('marketing.no_research') }}</p>
                <div v-else class="d-grid gap-2">
                    <MkNavCard
                        v-for="run in shownRuns"
                        :key="run.id"
                        icon="ri-bar-chart-box-line"
                        tone="pink"
                        :title="t(kindKey(run.kind))"
                        :subtitle="runSubtitle(run)"
                        :to="to('research', { projectId, runId: run.id })"
                    >
                        <template #trailing><MkStatusBadge :status="run.status" /></template>
                        <div v-if="!isFinished(run.status) && researchJob" class="px-3 pb-3">
                            <MkBar :value="researchJob.progress_pct" />
                        </div>
                    </MkNavCard>
                    <button v-if="runs.length > shownRuns.length" type="button" class="btn btn-light btn-sm" @click="allRuns = true">
                        {{ t('marketing.load_more') }}
                    </button>
                </div>

                <h2 class="mk-section-title">{{ t('marketing.plans') }}</h2>
                <div class="d-grid gap-2">
                    <template v-for="row in planRows" :key="row.type">
                        <MkNavCard
                            v-if="row.plan?.current_version"
                            icon="ri-file-list-3-line"
                            tone="primary"
                            :title="t(planKey(row.type))"
                            :subtitle="row.writing ? t('marketing.plan_writing') : `${t('marketing.version', { n: row.plan.current_version.version })} · ${date(row.plan.current_version.created_at)}`"
                            :to="to('plan', { projectId, planId: row.plan.id })"
                        >
                            <template #trailing><MkStatusBadge :status="row.plan.status" /></template>
                            <div v-if="row.writing" class="px-3 pb-3"><MkBar :value="row.job?.progress_pct ?? 0" /></div>
                        </MkNavCard>
                        <div v-else class="card custom-card mk-card mb-0">
                            <div class="card-body d-flex align-items-center gap-3 flex-wrap">
                                <span class="mk-icon bg-primary-transparent text-primary"><i class="ri-file-list-3-line"></i></span>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-semibold">{{ t(planKey(row.type)) }}</div>
                                    <div v-if="row.writing" class="text-muted fs-13">{{ t('marketing.plan_writing') }}</div>
                                    <div v-else-if="row.job?.status === 'FAILED'" class="text-danger fs-12">
                                        {{ t('marketing.job_failed', { reason: jobError(row.job.error_code) }) }}
                                    </div>
                                    <div v-else class="text-muted fs-13">{{ t('marketing.plan_not_written') }}</div>
                                </div>
                                <button v-if="canWrite && !row.writing" type="button" class="btn btn-primary btn-sm" @click="writing = row.type">
                                    {{ row.job?.status === 'FAILED' ? t('marketing.retry') : t('marketing.plan_write') }}
                                </button>
                                <div v-if="row.writing" class="w-100"><MkBar :value="row.job?.progress_pct ?? 0" /></div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <WritePlanModal :show="writing !== null" :project-id="projectId" :type="writing" @close="writing = null" @started="onStarted" />

        <MkModal :show="confirmDelete" :title="t('marketing.delete_confirm_title')" :busy="deleting" @close="confirmDelete = false">
            <p class="mb-0">{{ t('marketing.delete_confirm_text') }}</p>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="deleting" @click="confirmDelete = false">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-danger" :disabled="deleting" @click="destroy">
                    <span v-if="deleting" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.delete') }}
                </button>
            </template>
        </MkModal>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import {
    PLAN_TYPES, RESEARCH_KINDS, isFinished, kindKey, mkMoney, planKey, relevantChange, roleKey, stageKey, statusKey, useMk,
} from './marketingContext';
import MkHeader from './parts/MkHeader.vue';
import MkNavCard from './parts/MkNavCard.vue';
import MkStatusBadge from './parts/MkStatusBadge.vue';
import MkBar from './parts/MkBar.vue';
import MkModal from './parts/MkModal.vue';
import WritePlanModal from './parts/WritePlanModal.vue';
import useJobErrors from './parts/useJobErrors';

/**
 * One project's board: discovery progress, research runs, the three plans and the team. Loads on open (that includes
 * `GET projects/{id}/jobs`, the catch-up) and once per `marketing.job.changed` of this project — never on a timer.
 */
const route = useRoute();
const projectId = route.params.projectId;
const { ctx, state, http, api, to, fail, date, t, toast, router, feature } = useMk();
const jobError = useJobErrors();

const project = ref(null);
const discovery = ref(null);
const runs = ref([]);
const plans = ref([]);
const jobs = ref([]);
const failed = ref(false);
const allRuns = ref(false);
const startingKind = ref(null);
const writing = ref(null);
const archiving = ref(false);
const confirmDelete = ref(false);
const deleting = ref(false);

const abilities = computed(() => project.value?.abilities);
const archived = computed(() => project.value?.status === 'archived');
const canWrite = computed(() => abilities.value?.edit === true && ! archived.value);
const place = computed(() => [(project.value?.cities ?? []).join('، '), t(stageKey(project.value?.stage))].filter(Boolean).join(' · '));
const shownRuns = computed(() => (allRuns.value ? runs.value : runs.value.slice(0, 6)));
const EXEC_CARDS = [
    { page: 'calendar', feature: 'calendar', icon: 'ri-calendar-2-line', tone: 'info' },
    { page: 'studio', feature: 'content', icon: 'ri-sparkling-2-line', tone: 'pink' },
    { page: 'budgets', feature: 'budgets', icon: 'ri-safe-2-line', tone: 'success' },
    { page: 'results', feature: 'results', icon: 'ri-line-chart-line', tone: 'warning' },
    { page: 'report', feature: 'results', icon: 'ri-file-chart-line', tone: 'danger' },
    { page: 'campaigns', feature: 'campaigns', icon: 'ri-megaphone-line', tone: 'pink' },
    { page: 'platforms', feature: 'platforms', icon: 'ri-share-circle-line', tone: 'info' },
    { page: 'optimise', feature: 'optimisation', icon: 'ri-flask-line', tone: 'success' },
];
const execCards = computed(() => EXEC_CARDS.filter((c) => feature(c.feature)));
const researchJob = computed(() => jobs.value.find((j) => j.type === 'research' && ! isFinished(j.status)));

const planRows = computed(() => PLAN_TYPES.map((type) => {
    const job = jobs.value.find((j) => j.type === 'plan' && j.plan_type === type) ?? null;

    return {
        type,
        plan: plans.value.find((p) => p.type === type) ?? null,
        job,
        writing: job !== null && ! isFinished(job.status),
    };
}));

function runSubtitle(run) {
    const head = isFinished(run.status)
        ? t('marketing.sources_count', { sources: run.sources_count ?? 0, findings: run.findings_count ?? 0 })
        : t(statusKey(run.status));

    return `${head} · ${date(run.created_at)}`;
}

async function load() {
    try {
        const [p, d, r, pl, j] = await Promise.all([
            http.get(api(`/projects/${projectId}`)),
            http.get(api(`/projects/${projectId}/discovery`)),
            http.get(api(`/projects/${projectId}/research-runs`)),
            http.get(api(`/projects/${projectId}/plans`)),
            http.get(api(`/projects/${projectId}/jobs`)),
        ]);
        project.value = p.data.data;
        discovery.value = d.data.data;
        runs.value = r.data.data ?? [];
        plans.value = pl.data.data ?? [];
        jobs.value = j.data.data ?? [];
        failed.value = false;
    } catch (error) {
        if (! project.value) {
            failed.value = true;
        }
        fail(error);
    }
}

async function startResearch(kind) {
    startingKind.value = kind;
    try {
        const { data } = await http.post(api(`/projects/${projectId}/research-runs`), { kind });
        toast.showSuccess(t('marketing.research_started'));
        if (data.data?.run) {
            runs.value = [data.data.run, ...runs.value.filter((r) => r.id !== data.data.run.id)];
        }
        if (data.data?.job) {
            jobs.value = [data.data.job, ...jobs.value];
        }
        ctx.loadStatus();
    } catch (error) {
        fail(error);
    } finally {
        startingKind.value = null;
    }
}

function onStarted() {
    writing.value = null;
    ctx.loadStatus();
    load();
}

async function toggleArchive() {
    archiving.value = true;
    try {
        const { data } = await http.post(api(`/projects/${projectId}/archive`), { archived: ! archived.value });
        toast.showSuccess(data.message);
        ctx.loadStatus();
        await load();
    } catch (error) {
        fail(error);
    } finally {
        archiving.value = false;
    }
}

async function destroy() {
    deleting.value = true;
    try {
        await http.delete(api(`/projects/${projectId}`));
        toast.showSuccess(t('marketing.deleted'));
        confirmDelete.value = false;
        ctx.loadStatus();
        router.push(to('home'));
    } catch (error) {
        fail(error);
    } finally {
        deleting.value = false;
    }
}

watch(() => state.changes, () => {
    if (relevantChange(state, projectId)) {
        load();
    }
});

onMounted(() => {
    if (! state.status) {
        ctx.loadStatus();
    }
    load();
});
</script>
