<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_ar_inbox.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_ar_inbox.subtitle') }}</span>
            </div>
            <select v-model="filter" class="form-select w-auto" @change="load">
                <option value="">{{ t('ai_ar_inbox.all') }}</option>
                <option v-for="s in statuses" :key="s" :value="s">{{ t('ai_ar_inbox.status.' + s) }}</option>
            </select>
        </div>

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item"><button type="button" class="nav-link" :class="{ active: view === 'requests' }" @click="view = 'requests'">{{ t('ai_ar_inbox.tab_requests') }}</button></li>
            <li class="nav-item"><button type="button" class="nav-link" :class="{ active: view === 'jobs' }" @click="openJobs">{{ t('ai_ar_inbox.tab_jobs') }}</button></li>
        </ul>

        <div v-if="view === 'jobs'" class="card custom-card"><div class="card-body"><div class="table-responsive">
            <table class="table text-nowrap align-middle">
                <thead><tr><th>{{ t('ai_ar_inbox.job') }}</th><th>{{ t('ai_ar_inbox.amount') }}</th><th>{{ t('ai_ar_inbox.you_receive') }}</th><th>{{ t('ai_ar_inbox.job_status_label') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="j in jobs" :key="j.id">
                        <td>{{ j.title }}<div class="text-muted fs-12">#{{ j.id }}</div></td>
                        <td>{{ money(j.amount_minor) }} {{ j.currency }}</td>
                        <td>{{ money(j.provider_net_minor) }} {{ j.currency }}<div class="text-muted fs-12">{{ t('ai_ar_inbox.commission') }} {{ j.commission_percent }}%</div></td>
                        <td><span class="badge bg-light text-dark">{{ t('ai_ar_inbox.job_status.' + j.status) }}</span><div v-if="j.dispute_reason" class="text-danger fs-12 text-wrap">{{ j.dispute_reason }}</div><div v-if="j.release_at && j.status === 'delivered'" class="text-muted fs-12">{{ t('ai_ar_inbox.releases_on') }} {{ j.release_at.slice(0, 10) }}</div></td>
                        <td>
                            <div v-if="j.status === 'paid'" class="d-flex gap-2 align-items-center">
                                <input v-model="jobNotes[j.id]" class="form-control form-control-sm" maxlength="500" :placeholder="t('ai_ar_inbox.delivery_note')">
                                <button class="btn btn-sm btn-success" :disabled="saving" @click="deliver(j)">{{ t('ai_ar_inbox.deliver') }}</button>
                                <button class="btn btn-sm btn-outline-danger" :disabled="saving" @click="decline(j)">{{ t('ai_ar_inbox.decline') }}</button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="! jobs.length"><td colspan="5" class="text-center text-muted">{{ t('ai_ar_inbox.no_jobs') }}</td></tr>
                </tbody>
            </table>
        </div></div></div>

        <div v-else-if="loading" class="text-center py-5"><span class="spinner-border spinner-border-sm me-2"></span>{{ t('ai_ar_inbox.loading') }}</div>

        <div v-else-if="view === 'requests'" class="row g-3">
            <div class="col-lg-5">
                <div class="list-group">
                    <button v-for="r in rows" :key="r.id" type="button" class="list-group-item list-group-item-action"
                        :class="{ active: detail && detail.id === r.id }" @click="open(r.id)">
                        <div class="d-flex justify-content-between">
                            <strong>{{ r.title }}</strong>
                            <span class="badge bg-light text-dark">{{ t('ai_ar_inbox.status.' + r.status) }}</span>
                        </div>
                        <small>{{ (r.customer_note || '').slice(0, 80) }}</small>
                    </button>
                    <div v-if="! rows.length" class="text-muted p-3">{{ t('ai_ar_inbox.empty') }}</div>
                </div>
            </div>

            <div class="col-lg-7">
                <div v-if="detail" class="card custom-card"><div class="card-body">
                    <p class="fw-semibold fs-16 mb-1">{{ detail.title }}</p>
                    <p v-if="detail.customer_note" class="text-muted">{{ detail.customer_note }}</p>

                    <p class="fw-semibold mt-3 mb-2">{{ t('ai_ar_inbox.items') }}</p>
                    <ul class="list-unstyled">
                        <li v-for="(i, n) in detail.scene" :key="n" class="d-flex justify-content-between border-bottom py-2">
                            <span>{{ i.name || ('#' + i.asset_id) }}<span v-if="i.label" class="text-muted"> - {{ i.label }}</span></span>
                            <a :href="i.download_url" class="btn btn-sm btn-outline-secondary">GLB</a>
                        </li>
                    </ul>

                    <div v-if="detail.status === 'pending'" class="row g-2 mt-2">
                        <div class="col-12"><label class="form-label">{{ t('ai_ar_inbox.note') }}</label><textarea v-model="note" class="form-control" rows="2" maxlength="500"></textarea></div>
                        <div class="col-md-6"><label class="form-label">{{ t('ai_ar_inbox.quote') }}</label><input v-model.number="quote" type="number" min="0" step="0.01" class="form-control"></div>
                        <div class="col-12 d-flex gap-2 flex-wrap">
                            <button class="btn btn-success" :disabled="saving" @click="decide('approve')">{{ t('ai_ar_inbox.approve') }}</button>
                            <button class="btn btn-warning" :disabled="saving" @click="decide('request_changes')">{{ t('ai_ar_inbox.request_changes') }}</button>
                            <button class="btn btn-danger" :disabled="saving" @click="decide('reject')">{{ t('ai_ar_inbox.reject') }}</button>
                        </div>
                        <small class="text-muted">{{ t('ai_ar_inbox.note_hint') }}</small>
                    </div>
                    <div v-else class="mt-2">
                        <span class="badge bg-light text-dark">{{ t('ai_ar_inbox.status.' + detail.status) }}</span>
                        <p v-if="detail.provider_note" class="mt-2 mb-0">{{ detail.provider_note }}</p>
                        <p v-if="detail.quote_minor !== null" class="mt-1 mb-0">{{ t('ai_ar_inbox.quote') }}: {{ (detail.quote_minor / 100).toFixed(2) }} {{ detail.currency }}</p>
                    </div>
                </div></div>
                <div v-else class="text-muted p-3">{{ t('ai_ar_inbox.pick') }}</div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import providerAxios from '../../../../../../api/providerAxios';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';

const { t } = useI18n();
const { showSuccess, showError } = useToast();
const base = '/api/provider/v1/ai-ar/inbox';
const statuses = ['pending', 'approved', 'changes_requested', 'rejected', 'withdrawn'];

const rows = ref([]);
const detail = ref(null);
const filter = ref('pending');
const loading = ref(true);
const saving = ref(false);
const note = ref('');
const quote = ref(null);

async function run(call) {
    try { return await call(); } catch (error) { showError(extractApiErrorMessage(error, t('toast.error'))); return null; }
}

async function load() {
    loading.value = true;
    detail.value = null;
    rows.value = (await run(() => providerAxios.get(base, { params: filter.value ? { status: filter.value } : {} })))?.data.data ?? [];
    loading.value = false;
}

async function open(id) {
    note.value = '';
    quote.value = null;
    detail.value = (await run(() => providerAxios.get(`${base}/${id}`)))?.data.data ?? null;
}

async function decide(decision) {
    saving.value = true;
    const body = { decision, note: note.value || null };
    if (decision === 'approve' && quote.value !== null && quote.value !== '') body.quote_minor = Math.round(Number(quote.value) * 100);
    const res = await run(() => providerAxios.post(`${base}/${detail.value.id}/decide`, body));
    saving.value = false;
    if (res) { showSuccess(extractApiMessage(res, t('toast.success'))); await load(); }
}

const view = ref('requests');
const jobs = ref([]);
const jobNotes = ref({});
const money = (m) => (Number(m || 0) / 100).toFixed(2);

async function openJobs() {
    view.value = 'jobs';
    jobs.value = (await run(() => providerAxios.get('/api/provider/v1/ai-ar/jobs')))?.data.data ?? [];
}

async function jobAction(j, path, body = {}) {
    saving.value = true;
    const res = await run(() => providerAxios.post(`/api/provider/v1/ai-ar/jobs/${j.id}/${path}`, body));
    saving.value = false;
    if (res) { showSuccess(extractApiMessage(res, t('toast.success'))); await openJobs(); }
}
const deliver = (j) => jobAction(j, 'deliver', { note: jobNotes.value[j.id] || null });
const decline = (j) => jobAction(j, 'decline');

onMounted(load);
</script>
