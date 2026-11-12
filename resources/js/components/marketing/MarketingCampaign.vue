<template>
    <div>
        <MkHeader :title="campaign?.name || t('marketing.campaigns')" :subtitle="project?.name || ''" :back="to('campaigns', { projectId })" />

        <MkUnavailable v-if="statusReady && !enabled" />
        <div v-else-if="!campaign" class="d-grid gap-3">
            <div v-for="i in 3" :key="i" class="mk-skeleton" style="height: 100px"></div>
        </div>

        <div v-else class="row g-3">
            <div class="col-xl-7">
                <div class="card custom-card mk-card mb-3">
                    <div class="card-body">
                        <div class="d-flex align-items-start gap-2 flex-wrap">
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold fs-16 text-break">{{ campaign.name }}</div>
                                <div class="text-muted fs-13">
                                    {{ t(`marketing.platform_names.${['google', 'tiktok', 'snapchat'].includes(campaign.provider) ? campaign.provider : 'meta'}`) }}
                                    · {{ t(`marketing.objectives.${campaign.objective}`) }} · <span class="mk-ltr">v{{ campaign.version }}</span>
                                </div>
                            </div>
                            <span class="badge mk-pill" :class="`bg-${status.tone}-transparent`">{{ t(status.key) }}</span>
                        </div>
                        <div class="row g-2 mt-2">
                            <div class="col-6">
                                <div class="text-muted fs-12">{{ t('marketing.daily_budget') }}</div>
                                <div class="fw-bold fs-15 mk-ltr">{{ mkMoney(campaign.daily_budget_minor, campaign.currency) }}</div>
                            </div>
                            <div class="col-6">
                                <div class="text-muted fs-12">{{ t('marketing.total_budget') }}</div>
                                <div class="fw-bold fs-15 mk-ltr">{{ mkMoney(campaign.total_budget_minor, campaign.currency) }}</div>
                            </div>
                        </div>
                        <div class="fw-semibold fs-13 mt-3">{{ date(campaign.start_at) }} → {{ date(campaign.end_at) }}</div>
                        <div class="text-muted fs-13">{{ audience }}</div>
                    </div>
                </div>

                <div v-if="campaign.approval" class="text-success fw-semibold fs-13 mb-3">
                    <i class="ri-verified-badge-line me-1"></i>
                    {{ t(campaign.approval.by_delegation ? 'marketing.approved_by_delegation' : 'marketing.approved_cap', {
                        amount: mkMoney(campaign.approval.cap_minor, campaign.currency), date: date(campaign.approval.expires_at),
                    }) }}
                </div>
                <div v-if="lastError" class="alert alert-warning fs-13">{{ lastError }}</div>

                <template v-if="issues.length && ['DRAFT', 'REJECTED', 'FAILED'].includes(campaign.status)">
                    <h2 class="mk-section-title">{{ t('marketing.fix_before_approval') }}</h2>
                    <div class="card custom-card mk-card mb-3">
                        <div class="card-body">
                            <div v-for="(issue, i) in issues" :key="i" class="d-flex gap-2 fs-13" :class="{ 'mt-2': i > 0 }">
                                <i class="ri-error-warning-line text-danger"></i>
                                <span>{{ issueText(issue) }}</span>
                            </div>
                        </div>
                    </div>
                </template>

                <template v-if="campaign.status === 'PUBLISHED'">
                    <div class="card custom-card mk-card mb-3">
                        <div class="card-body">
                            <div class="fw-semibold">{{ t('marketing.on_platform', { status: campaign.platform_status || 'PAUSED' }) }}</div>
                            <div v-if="campaign.platform_ref" class="text-muted fs-12 mk-ltr">{{ campaign.platform_ref }}</div>
                            <div class="text-muted fs-13 mt-1">{{ t('marketing.start_on_platform') }}</div>
                        </div>
                    </div>
                    <template v-if="metrics?.rows?.length">
                        <div class="d-flex align-items-center gap-2 mk-section-title">
                            <span class="flex-grow-1">{{ t('marketing.platform_results') }}</span>
                            <span class="badge bg-success-transparent">{{ t('marketing.basis_imported') }}</span>
                        </div>
                        <div class="card custom-card mk-card">
                            <ul class="list-group list-group-flush">
                                <li v-for="r in metrics.rows.slice(0, 14)" :key="r.id" class="list-group-item d-flex gap-2 fs-13">
                                    <span class="fw-semibold flex-grow-1">{{ day(r.date) }}</span>
                                    <span class="text-muted mk-ltr">{{ mkMoney(r.spend_minor) }}</span>
                                    <span class="text-muted">· {{ t('marketing.kpi.clicks') }} {{ r.clicks ?? '—' }}</span>
                                </li>
                            </ul>
                        </div>
                        <p v-if="metrics.synced_at" class="text-muted fs-12">{{ t('marketing.updated', { date: dateTime(metrics.synced_at) }) }}</p>
                    </template>
                </template>
            </div>

            <!-- Steps -->
            <div class="col-xl-5">
                <div class="card custom-card mk-card">
                    <div class="card-body d-grid gap-2">
                        <template v-if="['DRAFT', 'REJECTED', 'FAILED'].includes(campaign.status) && canEdit">
                            <button type="button" class="btn btn-primary" :disabled="busy !== null" @click="act('validate')">
                                <span v-if="busy === 'validate'" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="ri-checkbox-circle-line me-1"></i>{{ t('marketing.check_campaign') }}
                            </button>
                        </template>
                        <template v-else-if="campaign.status === 'VALIDATED' && canEdit">
                            <button type="button" class="btn btn-primary" :disabled="busy !== null" @click="act('approval-requests')">
                                <span v-if="busy === 'approval-requests'" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="ri-send-plane-line me-1"></i>{{ t('marketing.ask_approval') }}
                            </button>
                        </template>
                        <template v-else-if="campaign.status === 'AWAITING_APPROVAL'">
                            <template v-if="abilities.approve">
                                <button type="button" class="btn btn-success" :disabled="busy !== null" @click="act('approve', { version: campaign.version })">
                                    <span v-if="busy === 'approve'" class="spinner-border spinner-border-sm me-1"></span>
                                    <i v-else class="ri-verified-badge-line me-1"></i>{{ t('marketing.approve_spend', { amount: mkMoney(campaign.total_budget_minor, campaign.currency) }) }}
                                </button>
                                <button type="button" class="btn btn-light" :disabled="busy !== null" @click="returning = true">
                                    <i class="ri-arrow-go-back-line me-1"></i>{{ t('marketing.return') }}
                                </button>
                                <div class="text-muted fs-12">{{ t('marketing.spend_who') }}</div>
                            </template>
                            <div v-else class="alert alert-info fs-13 mb-0">{{ t('marketing.waiting_spend_approval') }}</div>
                        </template>
                        <template v-else-if="campaign.status === 'APPROVED' && canEdit">
                            <button type="button" class="btn btn-primary" :disabled="busy !== null || !campaign.approval" @click="confirmPublish = true">
                                <span v-if="busy === 'publish'" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="ri-rocket-line me-1"></i>{{ pendingKey ? t('marketing.publish_retry') : t('marketing.publish') }}
                            </button>
                            <div class="text-muted fs-12">{{ t('marketing.publish_paused_note') }}</div>
                        </template>
                        <div v-else-if="campaign.status === 'SUBMITTING'" class="alert alert-info fs-13 mb-0">
                            <span class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.publishing') }}
                        </div>
                        <template v-else-if="campaign.status === 'UNKNOWN'">
                            <div class="alert alert-warning fs-13 mb-0">{{ t('marketing.unknown_note') }}</div>
                            <button v-if="canEdit" type="button" class="btn btn-primary" :disabled="busy !== null" @click="act('reconcile')">
                                <span v-if="busy === 'reconcile'" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="ri-refresh-line me-1"></i>{{ t('marketing.check_status') }}
                            </button>
                        </template>
                        <div v-else-if="campaign.status === 'PUBLISHED'" class="d-flex gap-2">
                            <button type="button" class="btn btn-light flex-fill" :disabled="busy !== null" @click="act('reconcile')">
                                <span v-if="busy === 'reconcile'" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="ri-refresh-line me-1"></i>{{ t('marketing.check_status') }}
                            </button>
                            <button v-if="abilities.results" type="button" class="btn btn-light flex-fill" :disabled="busy !== null" @click="sync">
                                <span v-if="busy === 'sync'" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="ri-loop-right-line me-1"></i>{{ t('marketing.sync_results') }}
                            </button>
                        </div>

                        <template v-if="canEdit && !['SUBMITTING', 'PUBLISHED', 'UNKNOWN'].includes(campaign.status)">
                            <div class="d-flex gap-2 mt-2">
                                <button type="button" class="btn btn-outline-light text-default flex-fill" @click="editing = true">
                                    <i class="ri-edit-2-line me-1"></i>{{ t('marketing.edit') }}
                                </button>
                                <button type="button" class="btn btn-outline-danger flex-fill" @click="deleting = true">
                                    <i class="ri-delete-bin-line me-1"></i>{{ t('marketing.delete_item') }}
                                </button>
                            </div>
                            <div v-if="campaign.approval" class="text-muted fs-12">{{ t('marketing.edit_drops_approval') }}</div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <CampaignFormModal
            :show="editing"
            :project-id="projectId"
            :existing="campaign"
            :currency="campaign?.currency || currency"
            @close="editing = false"
            @saved="onSaved"
        />

        <MkModal :show="returning" :title="t('marketing.return')" :busy="busy !== null" @close="returning = false">
            <label class="form-label" for="mk-c-note">{{ t('marketing.note_optional') }}</label>
            <textarea id="mk-c-note" v-model="returnNote" class="form-control" rows="3" maxlength="300"></textarea>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy !== null" @click="returning = false">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-primary" :disabled="busy !== null" @click="sendReturn">
                    <span v-if="busy === 'reject'" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.return') }}
                </button>
            </template>
        </MkModal>

        <MkModal :show="confirmPublish" :title="t('marketing.publish')" :busy="busy !== null" @close="confirmPublish = false">
            <p class="mb-0">{{ t('marketing.publish_confirm', { amount: mkMoney(campaign?.total_budget_minor, campaign?.currency), platform: platformName }) }}</p>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy !== null" @click="confirmPublish = false">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-primary" :disabled="busy !== null" @click="publish">
                    <span v-if="busy === 'publish'" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.publish') }}
                </button>
            </template>
        </MkModal>

        <MkModal :show="deleting" :title="t('marketing.delete_item')" :busy="busy !== null" @close="deleting = false">
            <p class="mb-0">{{ campaign?.name }}</p>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy !== null" @click="deleting = false">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-danger" :disabled="busy !== null" @click="destroy">
                    <span v-if="busy === 'delete'" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.delete_item') }}
                </button>
            </template>
        </MkModal>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { campaignStatus, mkMoney, newIdempotencyKey } from './marketingContext';
import useProjectPage from './parts/useProjectPage';
import MkHeader from './parts/MkHeader.vue';
import MkModal from './parts/MkModal.vue';
import MkUnavailable from './parts/MkUnavailable.vue';
import CampaignFormModal from './parts/CampaignFormModal.vue';

const ISSUES = ['platform_off', 'integration_not_connected', 'integration_expired', 'currency_mismatch', 'budget_too_low', 'start_in_past', 'bad_dates', 'no_audience', 'content_not_approved', 'content_has_blocking_issues'];

/**
 * One campaign (U09): validate → ask for approval → approve (owner / delegated manager) → publish → created PAUSED on
 * the platform. The Idempotency-Key is made once per campaign version and kept only while a request was lost (no
 * answer), so the retry reuses it; an UNKNOWN result is checked (reconcile), never re-sent blindly.
 */
const route = useRoute();
const campaignId = route.params.campaignId;
const {
    projectId, project, abilities, statusReady, enabled, currency, http, api, to, fail, date, dateTime, day, t, toast, router, onPush,
} = useProjectPage('campaigns');

const campaign = ref(null);
const metrics = ref(null);
const issues = ref([]);
const busy = ref(null);
const editing = ref(false);
const deleting = ref(false);
const returning = ref(false);
const returnNote = ref('');
const confirmPublish = ref(false);
const pendingKey = ref(null);

const canEdit = computed(() => abilities.value.edit === true);
const status = computed(() => campaignStatus(campaign.value?.status));
const platformName = computed(() => t(`marketing.platform_names.${['google', 'tiktok', 'snapchat'].includes(campaign.value?.provider) ? campaign.value.provider : 'meta'}`));
const audience = computed(() => {
    const tg = campaign.value?.targeting ?? {};
    const ages = tg.age_min != null && tg.age_max != null ? ` · ${tg.age_min}–${tg.age_max}` : '';

    return `${(tg.countries ?? []).join(', ')}${ages}`;
});
const lastError = computed(() => {
    const c = campaign.value;
    if (! c?.last_error || ! ['FAILED', 'REJECTED', 'UNKNOWN', 'APPROVED', 'PUBLISHED'].includes(c.status)) return '';
    if (c.last_error === 'integration_expired') return t('marketing.campaign_issues.integration_expired');
    if (c.last_error === 'platform_unreachable') return t('marketing.platform_no_answer');

    return c.last_error;
});

function issueText(issue) {
    const code = issue.code === 'content_has_blocking_issues' ? 'content_blocking' : issue.code;

    return t(`marketing.campaign_issues.${ISSUES.includes(issue.code) ? code : 'integration_not_connected'}`, { detail: issue.detail ?? '' });
}

// ---------------------------------------------------------------- the publish key (kept across a reload while lost)
const keyName = () => `mk-publish:${campaignId}:${campaign.value?.version}`;

function readKey() {
    try {
        return sessionStorage.getItem(keyName());
    } catch {
        return null;
    }
}

function keepKey(key) {
    pendingKey.value = key;
    try {
        sessionStorage.setItem(keyName(), key);
    } catch { /* memory only */ }
}

function dropKey() {
    pendingKey.value = null;
    try {
        sessionStorage.removeItem(keyName());
    } catch { /* nothing kept */ }
}

// ---------------------------------------------------------------- load + actions
async function load() {
    if (! enabled.value) return;
    try {
        const { data } = await http.get(api(`/campaigns/${campaignId}`));
        campaign.value = data.data;
        if (data.data?.validation?.length) issues.value = data.data.validation;
        pendingKey.value = readKey();
        if (data.data?.status === 'PUBLISHED') {
            const m = await http.get(api(`/campaigns/${campaignId}/metrics`));
            metrics.value = m.data.data;
        }
    } catch (error) {
        fail(error);
    }
}

async function act(action, body = {}) {
    busy.value = action;
    try {
        const { data } = await http.post(api(`/campaigns/${campaignId}/${action}`), body);
        if (data.data?.id) campaign.value = data.data;
        if (action === 'validate') issues.value = [];
        toast.showSuccess(data.message);
    } catch (error) {
        const res = fail(error);
        if (res?.error_code === 'marketing_campaign_invalid' && Array.isArray(res.data?.issues)) {
            issues.value = res.data.issues;
        }
        load();
    } finally {
        busy.value = null;
    }
}

async function sendReturn() {
    await act('reject', { note: returnNote.value.trim() || undefined });
    returning.value = false;
    returnNote.value = '';
}

async function sync() {
    busy.value = 'sync';
    try {
        const { data } = await http.post(api(`/campaigns/${campaignId}/metrics/sync`));
        toast.showSuccess(data.message);
        load();
    } catch (error) {
        fail(error);
    } finally {
        busy.value = null;
    }
}

async function publish() {
    const c = campaign.value;
    if (! c?.approval) return;
    confirmPublish.value = false;
    const key = readKey() ?? newIdempotencyKey();
    keepKey(key);
    busy.value = 'publish';
    try {
        const { data } = await http.post(api(`/campaigns/${campaignId}/publish`), {
            approval_id: c.approval.id,
            campaign_version: c.version,
            expected_budget_minor: c.total_budget_minor,
            currency: c.currency,
        }, { headers: { 'Idempotency-Key': key } });
        dropKey();
        if (data.data?.campaign) campaign.value = data.data.campaign;
        const outcome = data.data?.action?.status;
        if (outcome === 'SUCCEEDED') toast.showSuccess(t('marketing.published_paused'));
        else if (outcome === 'UNKNOWN') toast.showWarning(t('marketing.unknown_note'));
        else toast.showError(t('marketing.publish_refused'));
    } catch (error) {
        // The server answered (refused, conflict…): this attempt is over. No answer: keep the key for the retry.
        if (error?.response) dropKey();
        fail(error);
        load();
    } finally {
        busy.value = null;
    }
}

function onSaved(updated) {
    editing.value = false;
    issues.value = [];
    if (updated?.id) campaign.value = updated;
}

async function destroy() {
    busy.value = 'delete';
    try {
        await http.delete(api(`/campaigns/${campaignId}`));
        deleting.value = false;
        router.push(to('campaigns', { projectId }));
    } catch (error) {
        fail(error);
    } finally {
        busy.value = null;
    }
}

watch(enabled, (on) => on && load());
onPush(load);
onMounted(load);
</script>
