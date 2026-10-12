<template>
    <div class="card custom-card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <div class="card-title">{{ t('ai_site_requests.quote_builder') }}</div>
            <span v-if="request.quote" class="badge bg-primary-transparent">{{ t('ai_site_requests.quote_sent_badge') }}</span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">{{ t('ai_site_requests.total_price') }} <span class="text-muted">({{ request.currency || '-' }})</span></label>
                    <input v-model.number="form.amount" type="number" min="1" step="0.01" class="form-control" dir="ltr">
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ t('ai_site_requests.duration_days') }}</label>
                    <input v-model.number="form.duration_days" type="number" min="1" class="form-control" dir="ltr">
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ t('ai_site_requests.valid_days') }}</label>
                    <input v-model.number="form.valid_days" type="number" min="1" max="90" class="form-control" dir="ltr">
                </div>
                <div class="col-12">
                    <label class="form-label">{{ t('ai_site_requests.quote_notes') }}</label>
                    <textarea v-model="form.notes" rows="2" class="form-control"></textarea>
                </div>
            </div>

            <hr class="my-4">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <p class="fw-semibold mb-0">{{ t('ai_site_requests.schedule') }}</p>
                <button type="button" class="btn btn-light btn-sm" @click="addMilestone"><i class="ri-add-line me-1"></i>{{ t('ai_site_requests.add_milestone') }}</button>
            </div>
            <p v-if="! form.milestones.length" class="text-muted fs-13">{{ t('ai_site_requests.schedule_hint') }}</p>
            <div v-for="(m, i) in form.milestones" :key="i" class="row g-2 mb-2 align-items-start">
                <div class="col-md-4"><input v-model="m.title" class="form-control form-control-sm" :placeholder="t('ai_site_requests.milestone_title')"></div>
                <div class="col-md-5"><input v-model="m.description" class="form-control form-control-sm" :placeholder="t('ai_site_requests.milestone_desc')"></div>
                <div class="col-md-2">
                    <div class="input-group input-group-sm"><span class="input-group-text">{{ t('ai_site_requests.day') }}</span><input v-model.number="m.due_days" type="number" min="0" class="form-control" dir="ltr"></div>
                </div>
                <div class="col-md-1 text-end"><button type="button" class="btn btn-sm btn-danger-light btn-icon" @click="removeMilestone(i)"><i class="ri-delete-bin-line"></i></button></div>
            </div>

            <hr class="my-4">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <p class="fw-semibold mb-0">{{ t('ai_site_requests.payments') }}</p>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-light btn-sm" @click="splitDefault"><i class="ri-magic-line me-1"></i>{{ t('ai_site_requests.split_default') }}</button>
                    <button type="button" class="btn btn-light btn-sm" @click="addInstallment"><i class="ri-add-line me-1"></i>{{ t('ai_site_requests.add_payment') }}</button>
                </div>
            </div>
            <div v-for="(p, i) in form.installments" :key="i" class="row g-2 mb-2 align-items-start">
                <div class="col-md-4"><input v-model="p.title" class="form-control form-control-sm" :placeholder="t('ai_site_requests.payment_title')"></div>
                <div class="col-md-2"><input v-model.number="p.amount" type="number" min="0" step="0.01" class="form-control form-control-sm" dir="ltr"></div>
                <div class="col-md-3">
                    <select v-model="p.trigger" class="form-select form-select-sm">
                        <option value="on_accept">{{ t('ai_site_requests.trigger_on_accept') }}</option>
                        <option value="on_milestone">{{ t('ai_site_requests.trigger_on_milestone') }}</option>
                        <option value="on_delivery">{{ t('ai_site_requests.trigger_on_delivery') }}</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select v-if="p.trigger === 'on_milestone'" v-model.number="p.milestone_index" class="form-select form-select-sm">
                        <option v-for="(m, mi) in form.milestones" :key="mi" :value="mi">{{ m.title || `#${mi + 1}` }}</option>
                    </select>
                </div>
                <div class="col-md-1 text-end"><button type="button" class="btn btn-sm btn-danger-light btn-icon" @click="removeInstallment(i)"><i class="ri-delete-bin-line"></i></button></div>
            </div>

            <div class="alert mt-3 mb-0 d-flex justify-content-between flex-wrap" :class="balanced ? 'alert-success' : 'alert-warning'">
                <span>{{ t('ai_site_requests.allocated') }}: <strong dir="ltr">{{ allocated }}</strong> / <strong dir="ltr">{{ form.amount || 0 }}</strong></span>
                <span v-if="! balanced">{{ t('ai_site_requests.must_match') }}</span>
                <span v-else><i class="ri-check-line"></i> {{ t('ai_site_requests.balanced') }}</span>
            </div>
        </div>
        <div class="card-footer text-end">
            <button type="button" class="btn btn-primary" :disabled="! canSend || saving" @click="submit">
                <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span><i v-else class="ri-send-plane-line me-1"></i>{{ request.quote ? t('ai_site_requests.resend_quote') : t('ai_site_requests.send_quote') }}
            </button>
        </div>
    </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';

const props = defineProps({ request: { type: Object, required: true } });
const emit = defineEmits(['saved']);

const { t } = useI18n();
const { showSuccess, showError } = useToast();
const saving = ref(false);

const q = props.request.quote;
const form = reactive({
    amount: q?.amount ?? null,
    duration_days: q?.duration_days ?? 14,
    valid_days: 7,
    notes: q?.notes ?? '',
    milestones: (props.request.milestones ?? []).map((m) => ({ title: m.title, description: m.description ?? '', due_days: m.due_days })),
    installments: (props.request.installments ?? []).map((p) => ({
        title: p.title,
        amount: p.amount,
        trigger: p.trigger,
        milestone_index: Math.max(0, (props.request.milestones ?? []).findIndex((m) => m.id === p.milestone_id)),
    })),
});

const allocated = computed(() => Math.round(form.installments.reduce((s, p) => s + Number(p.amount || 0), 0) * 100) / 100);
const balanced = computed(() => Number(form.amount) > 0 && Math.abs(allocated.value - Number(form.amount)) < 0.01);
const canSend = computed(() => balanced.value && form.duration_days > 0 && form.installments.some((p) => p.trigger === 'on_accept') && form.installments.every((p) => p.title && p.amount > 0));

function addMilestone() {
    form.milestones.push({ title: '', description: '', due_days: Math.min(form.duration_days || 1, (form.milestones.at(-1)?.due_days ?? 0) + 3) });
}

function removeMilestone(index) {
    form.milestones.splice(index, 1);
    form.installments.forEach((p) => {
        if (p.trigger === 'on_milestone') {
            p.milestone_index = Math.max(0, Math.min(p.milestone_index ?? 0, form.milestones.length - 1));
        }
    });
}

function addInstallment() {
    form.installments.push({ title: '', amount: Math.max(0, Number(form.amount || 0) - allocated.value), trigger: form.installments.length ? 'on_delivery' : 'on_accept', milestone_index: 0 });
}

function removeInstallment(index) {
    form.installments.splice(index, 1);
}

/** 40% on acceptance, 30% on the first deliverable (if any), the rest at handover. */
function splitDefault() {
    const total = Number(form.amount || 0);

    if (total <= 0) {
        showError(t('ai_site_requests.enter_price_first'));

        return;
    }

    const first = Math.round(total * 0.4 * 100) / 100;
    const hasMilestone = form.milestones.length > 0;
    const second = hasMilestone ? Math.round(total * 0.3 * 100) / 100 : 0;
    const last = Math.round((total - first - second) * 100) / 100;

    form.installments = [
        { title: t('ai_site_requests.pay_first'), amount: first, trigger: 'on_accept', milestone_index: 0 },
        ...(hasMilestone ? [{ title: t('ai_site_requests.pay_second'), amount: second, trigger: 'on_milestone', milestone_index: 0 }] : []),
        { title: t('ai_site_requests.pay_last'), amount: last, trigger: 'on_delivery', milestone_index: 0 },
    ];
}

async function submit() {
    saving.value = true;

    try {
        const payload = {
            ...form,
            milestones: form.milestones.map((m) => ({ ...m, description: m.description || null })),
            installments: form.installments.map((p) => ({ title: p.title, amount: p.amount, trigger: p.trigger, milestone_index: p.trigger === 'on_milestone' ? p.milestone_index : null })),
        };
        const response = await adminAxios.post(`/api/admin/v1/ai-site-requests/${props.request.id}/quote`, payload);
        showSuccess(extractApiMessage(response, t('toast.success')));
        emit('saved', response.data.data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        saving.value = false;
    }
}
</script>
