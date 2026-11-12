<template>
    <div>
        <MkHeader :title="plan ? t(planKey(plan.type)) : t('marketing.plans')" :subtitle="project?.name || ''" :back="to('project', { projectId })" />

        <div v-if="!plan || !version" class="d-grid gap-3">
            <div v-for="i in 4" :key="i" class="mk-skeleton" style="height: 110px"></div>
        </div>

        <div v-else class="row g-3">
            <div class="col-xl-8 order-2 order-xl-1">
                <div class="card custom-card mk-card mb-3">
                    <div class="card-body">
                        <div class="d-flex align-items-start gap-2 flex-wrap">
                            <div class="flex-grow-1 min-w-0">
                                <h2 class="fs-16 fw-semibold mb-1">{{ version.content?.title || t(planKey(plan.type)) }}</h2>
                                <div class="text-muted fs-12">
                                    {{ t('marketing.version', { n: version.version }) }} ·
                                    {{ version.origin === 'edit' ? t('marketing.origin_edit') : t('marketing.origin_ai') }} ·
                                    {{ date(version.created_at) }}
                                </div>
                            </div>
                            <MkStatusBadge :status="isCurrent ? plan.status : 'SUPERSEDED'" />
                        </div>
                        <div v-if="plan.approval && isCurrent" class="text-success fs-13 fw-semibold mt-2">
                            <i class="ri-verified-badge-line me-1"></i>{{ t('marketing.approved_on', { n: plan.approval.version, date: date(plan.approval.approved_at) }) }}
                        </div>
                        <div v-if="writingJob" class="mt-3">
                            <div class="text-muted fs-13 mb-1"><span class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.plan_writing') }}</div>
                            <MkBar :value="writingJob.progress_pct" />
                        </div>
                        <button v-if="!isCurrent" type="button" class="btn btn-light btn-sm mt-3" @click="viewing = null">
                            <i class="ri-arrow-go-back-line me-1"></i>{{ t('marketing.back_to_current') }}
                        </button>
                    </div>
                </div>

                <div v-if="critic.length" class="card custom-card mk-card mb-3 border border-warning">
                    <div class="card-body">
                        <div class="fw-semibold mb-2"><i class="ri-error-warning-line text-warning me-1"></i>{{ t('marketing.issues') }}</div>
                        <div v-for="c in critic" :key="c.code" class="d-flex gap-2 py-1">
                            <i class="ri-checkbox-blank-circle-fill fs-8 mt-2" :class="c.severity === 'blocking' ? 'text-danger' : 'text-warning'"></i>
                            <div>
                                <div class="fs-13">{{ criticText(c.code) }}</div>
                                <div v-if="c.severity === 'blocking'" class="text-danger fs-12 fw-semibold">{{ t('marketing.issue_blocking') }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <PlanContent :type="plan.type" :content="version.content || {}" />
            </div>

            <!-- Actions -->
            <div class="col-xl-4 order-1 order-xl-2">
                <div class="card custom-card mk-card mb-3">
                    <div class="card-body d-grid gap-2">
                        <template v-if="isCurrent && abilities">
                            <button
                                v-if="plan.status === 'DRAFT' && abilities.edit"
                                type="button"
                                class="btn btn-primary"
                                :disabled="busy !== null || hasBlocking"
                                @click="act('submit')"
                            >
                                <span v-if="busy === 'submit'" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="ri-send-plane-line me-1"></i>{{ t('marketing.submit') }}
                            </button>
                            <template v-if="plan.status === 'READY_FOR_REVIEW' && abilities.approve">
                                <button type="button" class="btn btn-success" :disabled="busy !== null" @click="confirmApprove = true">
                                    <i class="ri-verified-badge-line me-1"></i>{{ t('marketing.approve', { n: version.version }) }}
                                </button>
                                <button type="button" class="btn btn-light" :disabled="busy !== null" @click="returning = true">
                                    <i class="ri-arrow-go-back-line me-1"></i>{{ t('marketing.return') }}
                                </button>
                            </template>
                        </template>

                        <router-link :to="to('finance', { projectId, planId })" class="btn btn-outline-success">
                            <i class="ri-calculator-line me-1"></i>{{ t('marketing.finance') }}
                        </router-link>
                        <div class="d-flex gap-2">
                            <button
                                v-for="f in ['pdf', 'docx']"
                                :key="f"
                                type="button"
                                class="btn btn-light flex-fill"
                                :disabled="busy !== null"
                                @click="download(f)"
                            >
                                <span v-if="busy === f" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else :class="f === 'pdf' ? 'ri-file-pdf-2-line' : 'ri-file-word-2-line'" class="me-1"></i>
                                {{ f === 'pdf' ? t('marketing.export_pdf') : t('marketing.export_docx') }}
                            </button>
                        </div>
                        <button type="button" class="btn btn-light" @click="showVersions = true">
                            <i class="ri-history-line me-1"></i>{{ t('marketing.versions') }} ({{ plan.versions_count ?? version.version }})
                        </button>
                        <template v-if="isCurrent && abilities?.edit && plan.status !== 'ARCHIVED'">
                            <button type="button" class="btn btn-light" @click="editing = true">
                                <i class="ri-edit-2-line me-1"></i>{{ t('marketing.edit') }}
                            </button>
                            <button type="button" class="btn btn-light" :disabled="!!writingJob" @click="rewriting = true">
                                <i class="ri-sparkling-2-line me-1"></i>{{ t('marketing.plan_rewrite') }}
                            </button>
                        </template>
                        <button
                            v-if="isCurrent && abilities?.manage_team && plan.status !== 'ARCHIVED'"
                            type="button"
                            class="btn btn-outline-danger"
                            :disabled="busy !== null"
                            @click="act('archive')"
                        >
                            <span v-if="busy === 'archive'" class="spinner-border spinner-border-sm me-1"></span>
                            <i v-else class="ri-archive-line me-1"></i>{{ t('marketing.archive_plan') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Approve: bound to this exact version -->
        <MkModal :show="confirmApprove" :title="t('marketing.approve_confirm_title')" :busy="busy === 'approve'" @close="confirmApprove = false">
            <p class="mb-0">{{ t('marketing.approve_confirm_text') }}</p>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy === 'approve'" @click="confirmApprove = false">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-success" :disabled="busy === 'approve'" @click="approve">
                    <span v-if="busy === 'approve'" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.approve', { n: version?.version }) }}
                </button>
            </template>
        </MkModal>

        <MkModal :show="returning" :title="t('marketing.return')" :busy="busy === 'return'" @close="returning = false">
            <label class="form-label" for="mk-return-note">{{ t('marketing.return_note') }}</label>
            <textarea id="mk-return-note" v-model="returnNote" class="form-control" rows="3" maxlength="500"></textarea>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy === 'return'" @click="returning = false">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-primary" :disabled="busy === 'return'" @click="returnToDraft">
                    <span v-if="busy === 'return'" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.return') }}
                </button>
            </template>
        </MkModal>

        <MkModal :show="showVersions" :title="t('marketing.versions')" @close="showVersions = false">
            <div v-if="versions === null" class="text-center py-3"><span class="spinner-border"></span></div>
            <div v-else class="list-group">
                <button
                    v-for="v in versions"
                    :key="v.version"
                    type="button"
                    class="list-group-item list-group-item-action d-flex align-items-center gap-2"
                    :class="{ active: v.version === version?.version }"
                    @click="pickVersion(v)"
                >
                    <i class="ri-history-line"></i>
                    <span class="flex-grow-1 text-start">
                        <span class="fw-semibold d-block">{{ t('marketing.version', { n: v.version }) }}</span>
                        <span class="fs-12 opacity-75">{{ v.origin === 'edit' ? t('marketing.origin_edit') : t('marketing.origin_ai') }} · {{ date(v.created_at) }}</span>
                    </span>
                    <span v-if="v.version === plan?.current_version?.version" class="badge bg-primary-transparent">{{ t('marketing.current') }}</span>
                </button>
            </div>
        </MkModal>

        <PlanEditModal
            v-if="plan"
            :show="editing"
            :plan-id="planId"
            :type="plan.type"
            :version="plan.current_version"
            @close="editing = false"
            @saved="onSaved"
        />
        <WritePlanModal :show="rewriting" :project-id="projectId" :type="plan?.type" @close="rewriting = false" @started="onRewrite" />
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { isFinished, planKey, relevantChange, useMk } from './marketingContext';
import MkHeader from './parts/MkHeader.vue';
import MkStatusBadge from './parts/MkStatusBadge.vue';
import MkModal from './parts/MkModal.vue';
import MkBar from './parts/MkBar.vue';
import PlanContent from './parts/PlanContent.vue';
import PlanEditModal from './parts/PlanEditModal.vue';
import WritePlanModal from './parts/WritePlanModal.vue';

const CRITIC_CODES = [
    'budget_missing', 'invented_budget_removed', 'unknown_sources_removed', 'numbers_without_sources',
    'budget_share_over_100', 'invalid_phase_range', 'overlapping_phases', 'phase_without_go_no_go',
];

/**
 * One plan: its current version laid out by type, the critic's notes and the steps its status allows — send for review,
 * approve this exact version (owner or manager: `abilities.approve`), back to draft, edit (a new version), finance,
 * versions, PDF/Word, write again with AI, archive (owner: `abilities.manage_team`).
 */
const route = useRoute();
const { projectId, planId } = route.params;
const { ctx, state, http, api, to, fail, date, t, toast } = useMk();

const plan = ref(null);
const project = ref(null);
const jobs = ref([]);
const viewing = ref(null);
const busy = ref(null);
const editing = ref(false);
const rewriting = ref(false);
const confirmApprove = ref(false);
const returning = ref(false);
const returnNote = ref('');
const showVersions = ref(false);
const versions = ref(null);

const version = computed(() => viewing.value ?? plan.value?.current_version ?? null);
const isCurrent = computed(() => viewing.value === null);
const abilities = computed(() => project.value?.abilities ?? null);
const critic = computed(() => {
    const seen = new Set();

    return (version.value?.critic ?? []).filter((c) => c?.code && ! seen.has(c.code) && seen.add(c.code));
});
const hasBlocking = computed(() => critic.value.some((c) => c.severity === 'blocking'));
const writingJob = computed(() => jobs.value.find((j) => j.type === 'plan' && j.plan_type === plan.value?.type && ! isFinished(j.status)) ?? null);

function criticText(code) {
    return t(`marketing.critic.${CRITIC_CODES.includes(code) ? code : 'numbers_without_sources'}`);
}

function setPlan(next) {
    if (! next) return;
    plan.value = { ...next, versions_count: next.versions_count ?? plan.value?.versions_count ?? null };
}

async function loadPlan() {
    try {
        const [p, j] = await Promise.all([
            http.get(api(`/plans/${planId}`)),
            http.get(api(`/projects/${projectId}/jobs`)),
        ]);
        setPlan(p.data.data);
        jobs.value = j.data.data ?? [];
    } catch (error) {
        fail(error);
    }
}

async function loadProject() {
    try {
        const { data } = await http.get(api(`/projects/${projectId}`));
        project.value = data.data;
    } catch (error) {
        fail(error);
    }
}

async function run(key, request) {
    busy.value = key;
    try {
        const { data } = await request();
        setPlan(data.data);
        toast.showSuccess(data.message);

        return true;
    } catch (error) {
        const body = fail(error);
        if (error?.response?.status === 409 || body?.error_code === 'version_conflict') {
            loadPlan();
        }

        return false;
    } finally {
        busy.value = null;
    }
}

function act(action) {
    return run(action, () => http.post(api(`/plans/${planId}/${action}`), action === 'archive' ? {} : { version: version.value.version }));
}

async function approve() {
    if (await run('approve', () => http.post(api(`/plans/${planId}/approve`), { version: version.value.version }))) {
        confirmApprove.value = false;
    }
}

async function returnToDraft() {
    const ok = await run('return', () => http.post(api(`/plans/${planId}/return`), {
        version: version.value.version,
        note: returnNote.value.trim() || undefined,
    }));
    if (ok) {
        returning.value = false;
        returnNote.value = '';
    }
}

async function pickVersion(v) {
    if (v.version === plan.value?.current_version?.version) {
        viewing.value = null;
        showVersions.value = false;

        return;
    }
    try {
        const { data } = await http.get(api(`/plans/${planId}/versions/${v.version}`));
        viewing.value = data.data;
        showVersions.value = false;
    } catch (error) {
        fail(error);
    }
}

function onSaved(updated) {
    editing.value = false;
    viewing.value = null;
    setPlan(updated);
}

function onRewrite(started) {
    rewriting.value = false;
    if (started?.job) {
        jobs.value = [started.job, ...jobs.value];
    }
    ctx.loadStatus();
}

/** The export is a file behind the bearer token: fetch it as a blob, then save it. */
async function download(format) {
    busy.value = format;
    try {
        const response = await http.get(api(`/plans/${planId}/versions/${version.value.version}/export`), {
            params: { format },
            responseType: 'blob',
        });
        const disposition = response.headers?.['content-disposition'] ?? '';
        const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition);
        const name = match ? decodeURIComponent(match[1]) : `DORR-plan-v${version.value.version}.${format}`;
        const url = URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = name;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch {
        toast.showError(t('marketing.export_failed'));
    } finally {
        busy.value = null;
    }
}

watch(showVersions, async (open) => {
    if (! open) return;
    versions.value = null;
    try {
        const { data } = await http.get(api(`/plans/${planId}/versions`));
        versions.value = [...(data.data ?? [])].reverse();
    } catch (error) {
        versions.value = [];
        fail(error);
    }
});

watch(() => state.changes, () => {
    if (relevantChange(state, projectId)) {
        loadPlan();
    }
});

onMounted(() => {
    loadPlan();
    loadProject();
});
</script>
