<template>
    <div>
        <WalletPageHeader :title="item ? item.full_name : t('chat.verification.requests.title')" :section="t('chat.verification.requests.title')" />

        <div v-if="loading && !item" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></div>

        <div v-else-if="item" class="row g-3">
            <div class="col-xl-4">
                <div class="card custom-card mb-3">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                            <span class="badge fs-12" :class="statusClass(item.status)">{{ t(`chat.verification.statuses.${item.status}`) }}</span>
                            <span class="badge" :class="item.track === 'celebrity' ? 'bg-warning-transparent' : 'bg-info-transparent'">{{ t(`chat.verification.tracks.${item.track}`) }}</span>
                            <span v-if="item.identity_checked" class="badge bg-success-transparent"><i class="ri-shield-check-line me-1"></i>{{ t('chat.verification.requests.identity_checked') }}</span>
                        </div>
                        <dl class="row mb-0 fs-13">
                            <dt class="col-5 text-muted fw-normal">{{ t('chat.verification.requests.account') }}</dt>
                            <dd class="col-7"><UserName :user="item.user">{{ item.user?.name || '—' }}</UserName> <span class="d-block fs-11 text-muted" dir="ltr">{{ item.user?.phone }}</span></dd>
                            <dt class="col-5 text-muted fw-normal">{{ t('chat.verification.requests.full_name') }}</dt>
                            <dd class="col-7">{{ item.full_name }}</dd>
                            <dt class="col-5 text-muted fw-normal">{{ t('chat.verification.requests.contact') }}</dt>
                            <dd class="col-7" dir="ltr">{{ [item.contact_phone, item.contact_email].filter(Boolean).join(' · ') || '—' }}</dd>
                            <dt class="col-5 text-muted fw-normal">{{ t('chat.verification.requests.submitted') }}</dt>
                            <dd class="col-7">{{ fmtDate(item.submitted_at) }}</dd>
                            <dt class="col-5 text-muted fw-normal">{{ t('chat.verification.requests.deadline') }}</dt>
                            <dd class="col-7">
                                <span v-if="item.deadline_paused" class="text-muted"><i class="ri-pause-circle-line me-1"></i>{{ t('chat.verification.requests.paused') }}</span>
                                <template v-else-if="item.deadline_at">
                                    <span :class="new Date(item.deadline_at) < new Date() ? 'text-danger fw-semibold' : ''">{{ fmtDate(item.deadline_at) }}</span>
                                    <span class="d-block fs-11 text-muted">{{ t(`chat.verification.requests.owner_${item.deadline_owner}`) }}</span>
                                </template>
                                <span v-else>—</span>
                            </dd>
                        </dl>
                        <div v-if="item.notes" class="mt-3 p-2 bg-light rounded fs-13 text-wrap">{{ item.notes }}</div>
                        <div v-if="item.completion_reason" class="alert alert-warning fs-13 mt-3 mb-0"><strong>{{ t('chat.verification.requests.asked_for') }}:</strong> {{ item.completion_reason }}</div>
                        <div v-if="item.decision_reason" class="alert alert-light border fs-13 mt-3 mb-0"><strong>{{ t('chat.verification.requests.decision_reason') }}:</strong> {{ item.decision_reason }}</div>
                    </div>
                </div>

                <div class="card custom-card mb-3">
                    <div class="card-body py-3">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <i class="ri-verified-badge-line text-primary fs-18"></i>
                            <span class="fw-semibold fs-13">{{ t('chat.verification.badges.title') }}:</span>
                            <span v-if="item.badge" class="badge" :class="item.badge.is_verified ? 'bg-success' : 'bg-light text-default'">{{ t(`chat.verification.badge_statuses.${item.badge.status}`) }}</span>
                            <span v-else class="text-muted fs-12">{{ t('chat.verification.badges.none') }}</span>
                        </div>
                        <div class="fs-12 text-muted mt-1">
                            {{ t('chat.verification.requests.activation') }}: {{ t(`chat.verification.timings.${item.activation_timing}`) }}
                            <span v-if="item.activation_timing_custom" class="badge bg-warning-transparent ms-1">{{ t('chat.verification.requests.custom_timing') }}</span>
                        </div>
                    </div>
                </div>

                <div v-if="buttons.length" class="card custom-card mb-3">
                    <div class="card-header py-3"><div class="card-title mb-0">{{ t('chat.verification.requests.actions_title') }}</div></div>
                    <div class="card-body d-grid gap-2">
                        <button v-for="a in buttons" :key="a" type="button" class="btn btn-sm" :class="buttonClass(a)" :disabled="busy" @click="openAction(a)">
                            <i :class="actionIcon[a]" class="me-1"></i>{{ t(`chat.verification.requests.action_${a}`) }}
                        </button>
                    </div>
                </div>

                <div class="card custom-card">
                    <div class="card-header py-3"><div class="card-title mb-0">{{ t('chat.verification.requests.history') }}</div></div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-0">
                            <li v-for="(h, i) in item.history" :key="i" class="mb-3 fs-13">
                                <div class="fw-semibold">{{ t(`chat.verification.statuses.${h.to}`) }}</div>
                                <div class="fs-11 text-muted">{{ fmtDate(h.at) }} · {{ t(`chat.verification.audit.actor_${h.actor_type === 'user' ? 'user' : h.actor_type}`) }}</div>
                                <div v-if="h.reason" class="fs-12 text-wrap">{{ h.reason === 'deadline_passed' ? t('chat.verification.requests.deadline_passed') : h.reason }}</div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-xl-8">
                <div class="card custom-card">
                    <div class="card-header py-3"><div class="card-title mb-0">{{ t('chat.verification.requests.documents') }}</div></div>
                    <div class="card-body">
                        <div v-if="!item.documents.length" class="text-muted">{{ t('wallet.common.empty') }}</div>
                        <div v-for="doc in item.documents" :key="doc.id" class="border rounded p-3 mb-3">
                            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                                <span class="fw-semibold">{{ doc.type?.name }}</span>
                                <span class="badge" :class="docClass(doc.status)">{{ t(`chat.verification.requests.doc_${doc.status}`) }}</span>
                                <span class="fs-11 text-muted ms-auto">{{ fmtDate(doc.uploaded_at) }}</span>
                            </div>
                            <div class="d-flex flex-wrap gap-3">
                                <template v-for="side in ['front', 'back']" :key="side">
                                    <div v-if="doc.files[side]" class="text-center">
                                        <a v-if="previews[`${doc.id}-${side}`] && isImage(doc.files[side])" :href="previews[`${doc.id}-${side}`]" target="_blank" rel="noopener">
                                            <img :src="previews[`${doc.id}-${side}`]" :alt="side" class="rounded border" style="max-height: 180px; max-width: 260px; object-fit: contain;">
                                        </a>
                                        <button v-else type="button" class="btn btn-sm btn-light" @click="openFile(doc, side)"><i class="ri-file-pdf-2-line me-1"></i>{{ doc.files[side].name }}</button>
                                        <div class="fs-11 text-muted mt-1">{{ t(`chat.verification.requests.side_${side}`) }}</div>
                                    </div>
                                </template>
                            </div>
                            <div v-if="doc.review_note" class="fs-12 text-danger mt-2">{{ doc.review_note }}</div>
                            <div v-if="item.actions.includes('review_documents') && doc.status === 'pending'" class="d-flex gap-2 mt-2">
                                <button type="button" class="btn btn-sm btn-success-light" :disabled="busy" @click="reviewDoc(doc, true)"><i class="ri-check-line me-1"></i>{{ t('chat.verification.requests.accept') }}</button>
                                <button type="button" class="btn btn-sm btn-danger-light" :disabled="busy" @click="openAction('reject_document', doc)"><i class="ri-close-line me-1"></i>{{ t('chat.verification.requests.turn_down') }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <WalletModal :show="!!action" :title="action ? t(`chat.verification.requests.action_${action}`) : ''" @close="action = null">
            <form id="vr-action-form" novalidate @submit.prevent="submitAction">
                <p class="text-muted fs-13">{{ action ? t(`chat.verification.requests.confirm_${action}`) : '' }}</p>
                <div v-if="spec.badgeStatus" class="mb-3">
                    <label class="form-label">{{ t('chat.verification.requests.badge_kind') }}</label>
                    <select v-model="form.status" class="form-select">
                        <option value="temporary">{{ t('chat.verification.badge_statuses.temporary') }}</option>
                        <option value="active">{{ t('chat.verification.badge_statuses.active') }}</option>
                    </select>
                </div>
                <div v-if="spec.timing" class="mb-3">
                    <label class="form-label">{{ t('chat.verification.requests.activation') }}</label>
                    <select v-model="form.timing" class="form-select">
                        <option v-for="tm in timings" :key="tm" :value="tm">{{ t(`chat.verification.timings.${tm}`) }}</option>
                    </select>
                </div>
                <div v-if="spec.until" class="mb-3">
                    <label class="form-label">{{ t('chat.verification.requests.new_deadline') }} <span class="text-danger">*</span></label>
                    <input v-model="form.until" type="datetime-local" class="form-control" :class="{ 'is-invalid': errors.until }">
                    <div v-if="errors.until" class="text-danger fs-12 mt-1">{{ errors.until }}</div>
                </div>
                <div v-if="spec.docs" class="mb-3">
                    <label class="form-label">{{ t('chat.verification.requests.upload_again') }}</label>
                    <div v-for="doc in item?.documents ?? []" :key="doc.id" class="form-check">
                        <input :id="`vr-doc-${doc.type?.id}`" v-model="form.document_type_ids" class="form-check-input" type="checkbox" :value="doc.type?.id">
                        <label class="form-check-label" :for="`vr-doc-${doc.type?.id}`">{{ doc.type?.name }}</label>
                    </div>
                </div>
                <div v-if="spec.reason" class="mb-1">
                    <label class="form-label">{{ t('chat.verification.audit.reason') }} <span v-if="spec.reason === 'required'" class="text-danger">*</span></label>
                    <textarea v-model="form.reason" rows="3" class="form-control" :class="{ 'is-invalid': errors.reason || errors.note }"></textarea>
                    <div class="fs-11 text-muted mt-1">{{ t('chat.verification.requests.reason_hint') }}</div>
                    <div v-if="errors.reason || errors.note" class="text-danger fs-12 mt-1">{{ errors.reason || errors.note }}</div>
                </div>
                <div v-if="errors.general" class="text-danger fs-13 mt-2">{{ errors.general }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" @click="action = null">{{ t('cancel') }}</button>
                <button type="submit" form="vr-action-form" class="btn" :class="buttonClass(action)" :disabled="busy"><span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}</button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import adminAxios from '../../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../../composables/useToast';
import { statusClass } from './status';
import UserName from '../../../../../../../../components/ui/UserName.vue';

// One verification request (docs/verification_tasks.md AV-015 – AV-018): the documents side by side, the trail, and the
// decisions this member of staff may take now — the server sends them (`actions`) and checks them again on press.
const { t } = useI18n();
const route = useRoute();
const { showSuccess, showError } = useToast();
const base = computed(() => `/api/admin/v1/chat-verification/requests/${route.params.uuid}`);

const item = ref(null);
const loading = ref(false);
const busy = ref(false);
const previews = reactive({});
const action = ref(null);
const target = ref(null);
const form = reactive({ reason: '', until: '', document_type_ids: [], status: 'temporary', timing: '' });
const timings = ['after_identity_check', 'after_approval', 'after_payment', 'after_approval_and_payment', 'after_proof_approved'];
const errors = reactive({});

/** What each decision asks for, and where it goes. */
const specs = {
    start_review: { path: 'start-review' },
    check_identity: { path: 'identity-check' },
    request_completion: { path: 'request-completion', reason: 'required', docs: true },
    approve: { path: 'approve', reason: 'optional' },
    reject: { path: 'reject', reason: 'required' },
    extend_deadline: { path: 'deadline', reason: 'required', until: true },
    pause_deadline: { path: 'deadline/pause', reason: 'required' },
    resume_deadline: { path: 'deadline/resume', reason: 'optional' },
    resume: { path: 'resume', reason: 'required', until: true },
    expire: { path: 'expire', reason: 'required' },
    cancel: { path: 'cancel', reason: 'required' },
    reject_document: { reason: 'required' },
    early_activate: { path: 'early-activate', reason: 'required', badgeStatus: true },
    set_timing: { path: 'activation-timing', reason: 'required', timing: true },
};
const actionIcon = {
    start_review: 'ri-play-circle-line', check_identity: 'ri-shield-check-line', request_completion: 'ri-file-add-line',
    approve: 'ri-checkbox-circle-line', reject: 'ri-close-circle-line', extend_deadline: 'ri-calendar-schedule-line',
    pause_deadline: 'ri-pause-circle-line', resume_deadline: 'ri-play-line', resume: 'ri-restart-line', expire: 'ri-timer-flash-line', cancel: 'ri-forbid-line',
    early_activate: 'ri-verified-badge-line', set_timing: 'ri-time-line',
};
const spec = computed(() => specs[action.value] ?? {});
const buttons = computed(() => (item.value?.actions ?? []).filter((a) => a !== 'review_documents'));

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '—');
const isImage = (file) => String(file?.mime ?? '').startsWith('image/');
const docClass = (s) => ({ pending: 'bg-warning-transparent', accepted: 'bg-success-transparent', rejected: 'bg-danger-transparent' }[s] ?? 'bg-light');
const buttonClass = (a) => ({ approve: 'btn-success', reject: 'btn-danger', reject_document: 'btn-danger', cancel: 'btn-outline-danger', expire: 'btn-outline-danger' }[a] ?? 'btn-primary-light');

function openAction(name, doc = null) {
    Object.keys(errors).forEach((k) => delete errors[k]);
    Object.assign(form, { reason: '', until: '', document_type_ids: [], status: 'temporary', timing: item.value?.activation_timing ?? 'after_approval' });
    target.value = doc;
    action.value = name;
}

async function submitAction() {
    Object.keys(errors).forEach((k) => delete errors[k]);
    busy.value = true;
    try {
        let response;
        if (action.value === 'reject_document') {
            response = await adminAxios.post(`${base.value}/documents/${target.value.id}/review`, { accepted: false, note: form.reason });
        } else {
            const body = {};
            if (spec.value.reason) body.reason = form.reason || null;
            if (spec.value.until) body.until = form.until ? new Date(form.until).toISOString() : null;
            if (spec.value.docs) body.document_type_ids = form.document_type_ids.filter(Boolean);
            if (spec.value.badgeStatus) body.status = form.status;
            if (spec.value.timing) body.timing = form.timing;
            response = await adminAxios.post(`${base.value}/${spec.value.path}`, body);
        }
        fill(response.data.data);
        action.value = null;
        showSuccess(extractApiMessage(response, t('toast.success')));
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};
        Object.entries(bag).forEach(([key, messages]) => { errors[key] ??= messages[0]; });
        errors.general = Object.keys(bag).length ? '' : extractApiErrorMessage(error, t('toast.error'));
    } finally {
        busy.value = false;
    }
}

async function reviewDoc(doc, accepted) {
    busy.value = true;
    try {
        const { data } = await adminAxios.post(`${base.value}/documents/${doc.id}/review`, { accepted });
        fill(data.data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        busy.value = false;
    }
}

/** The files need the staff's token, so they are fetched and shown from memory (never a public link). */
async function loadPreviews() {
    for (const doc of item.value?.documents ?? []) {
        for (const side of ['front', 'back']) {
            const file = doc.files[side];
            const key = `${doc.id}-${side}`;
            if (!file || previews[key] || !isImage(file)) continue;
            try {
                const { data } = await adminAxios.get(`/api/admin/v1/${file.path}`, { responseType: 'blob' });
                previews[key] = URL.createObjectURL(data);
            } catch {
                // left as a button
            }
        }
    }
}

async function openFile(doc, side) {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/${doc.files[side].path}`, { responseType: 'blob' });
        window.open(URL.createObjectURL(data), '_blank', 'noopener');
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

function fill(data) {
    item.value = data;
    loadPreviews();
}

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(base.value);
        fill(data.data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

onMounted(load);
onBeforeUnmount(() => Object.values(previews).forEach((url) => URL.revokeObjectURL(url)));
</script>
