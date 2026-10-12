<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <router-link :to="{ name: 'admin.ai-site-requests.index' }" class="text-muted fs-13"><i class="ri-arrow-left-line me-1 align-middle rtl-flip"></i>{{ t('ai_site_requests.back') }}</router-link>
                <p v-if="request" class="fw-semibold fs-18 mb-0 mt-1">
                    {{ request.title }}
                    <span class="badge bg-primary-transparent ms-2">{{ request.reference }}</span>
                    <span class="badge ms-1" :class="`bg-${request.status.color}-transparent`">{{ request.status.name }}</span>
                </p>
            </div>
            <div v-if="request?.timeline.due_at && ! request.status.final" class="text-end">
                <div class="fs-12 text-muted">{{ t('ai_site_requests.countdown') }}</div>
                <div class="fs-20 fw-semibold" :class="request.timeline.overdue ? 'text-danger' : 'text-primary'" dir="ltr">{{ countdown }}</div>
            </div>
        </div>

        <div v-if="loading" class="text-center py-5"><span class="spinner-border spinner-border-sm me-2"></span>{{ t('ai_site_offers.loading') }}</div>

        <div v-else-if="request" class="row g-3">
            <div class="col-xl-8">
                <!-- Payments summary -->
                <div v-if="request.quote" class="card custom-card">
                    <div class="card-body">
                        <div class="row text-center g-3">
                            <div class="col-4"><div class="text-muted fs-12">{{ t('ai_site_requests.total_price') }}</div><div class="fs-18 fw-semibold" dir="ltr">{{ request.payments.total }} {{ request.currency }}</div></div>
                            <div class="col-4"><div class="text-muted fs-12">{{ t('ai_site_requests.paid') }}</div><div class="fs-18 fw-semibold text-success" dir="ltr">{{ request.payments.paid }}</div></div>
                            <div class="col-4"><div class="text-muted fs-12">{{ t('ai_site_requests.remaining') }}</div><div class="fs-18 fw-semibold text-warning" dir="ltr">{{ request.payments.remaining }}</div></div>
                        </div>
                        <div class="progress mt-3" style="height: 6px;"><div class="progress-bar bg-success" :style="{ width: paidPercent + '%' }"></div></div>
                        <p v-if="request.decline_reason" class="mt-3 mb-0 text-danger fs-13"><i class="ri-close-circle-line me-1"></i>{{ t('ai_site_requests.decline_reason') }}: {{ request.decline_reason }}</p>
                    </div>
                </div>

                <!-- Quote builder (before acceptance) -->
                <QuoteBuilder v-if="canQuote" :key="'q' + request.updated_at" :request="request" @saved="onUpdated" />

                <!-- Delivery plan (after acceptance) -->
                <div v-if="request.accepted_at" class="card custom-card">
                    <div class="card-header"><div class="card-title">{{ t('ai_site_requests.delivery_plan') }}</div></div>
                    <div class="card-body">
                        <p v-if="! request.milestones.length" class="text-muted fs-13">{{ t('ai_site_requests.no_milestones') }}</p>
                        <div v-for="m in request.milestones" :key="m.id" class="d-flex flex-wrap align-items-center gap-2 py-2 border-bottom">
                            <i :class="m.status === 'delivered' ? 'ri-checkbox-circle-fill text-success' : 'ri-time-line text-muted'" class="fs-20"></i>
                            <div class="flex-fill">
                                <div class="fw-semibold">{{ m.title }}</div>
                                <div class="fs-12 text-muted">{{ m.description }} <span v-if="m.due_at">· {{ t('ai_site_requests.due') }} {{ new Date(m.due_at).toLocaleDateString() }}</span></div>
                            </div>
                            <select class="form-select form-select-sm w-auto" :value="m.status" :disabled="request.status.final" @change="setMilestone(m, $event.target.value)">
                                <option value="pending">{{ t('ai_site_requests.ms_pending') }}</option>
                                <option value="in_progress">{{ t('ai_site_requests.ms_in_progress') }}</option>
                                <option value="delivered">{{ t('ai_site_requests.ms_delivered') }}</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Installments -->
                <div v-if="request.installments.length" class="card custom-card">
                    <div class="card-header"><div class="card-title">{{ t('ai_site_requests.payments') }}</div></div>
                    <div class="table-responsive">
                        <table class="table mb-0 align-middle">
                            <tbody>
                                <tr v-for="p in request.installments" :key="p.id">
                                    <td class="fw-semibold">{{ p.title }}<div class="fs-12 text-muted">{{ t('ai_site_requests.trigger_' + p.trigger) }}</div></td>
                                    <td dir="ltr">{{ p.amount }} {{ request.currency }}</td>
                                    <td>
                                        <span class="badge" :class="{ 'bg-success-transparent': p.status === 'paid', 'bg-warning-transparent': p.status === 'due', 'bg-secondary-transparent': p.status === 'pending' }">{{ t('ai_site_requests.pay_' + p.status) }}</span>
                                        <div v-if="p.paid_at" class="fs-11 text-muted">{{ new Date(p.paid_at).toLocaleString() }}</div>
                                    </td>
                                    <td class="text-end">
                                        <button v-if="p.status === 'pending' && request.accepted_at && ! request.status.final" type="button" class="btn btn-sm btn-primary-light" @click="release(p)">{{ t('ai_site_requests.release_now') }}</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Brief -->
                <div class="card custom-card">
                    <div class="card-header"><div class="card-title">{{ t('ai_site_requests.brief') }}</div></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div v-for="row in briefRows" :key="row.key" :class="row.wide ? 'col-12' : 'col-md-6'">
                                <div class="text-muted fs-12">{{ t('ai_site_requests.f_' + row.key) }}</div>
                                <div class="fw-medium" style="white-space: pre-wrap;" :dir="row.ltr ? 'ltr' : 'auto'">{{ row.value }}</div>
                            </div>
                        </div>
                        <div v-if="request.files.length" class="mt-4">
                            <div class="text-muted fs-12 mb-1">{{ t('ai_site_requests.files') }}</div>
                            <button v-for="f in request.files" :key="f.index" type="button" class="btn btn-light btn-sm me-2 mb-2" @click="download(f)"><i class="ri-attachment-2 me-1"></i>{{ f.name }}</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <!-- Status -->
                <div class="card custom-card">
                    <div class="card-header"><div class="card-title">{{ t('ai_site_requests.change_status') }}</div></div>
                    <div class="card-body">
                        <select v-model="statusForm.status" class="form-select mb-2">
                            <option v-for="s in statuses" :key="s.code" :value="s.code">{{ locale === 'ar' ? s.name_ar : s.name_en }}</option>
                        </select>
                        <input v-model="statusForm.note" class="form-control mb-2" :placeholder="t('ai_site_requests.status_note')">
                        <button type="button" class="btn btn-primary w-100" :disabled="busy || statusForm.status === request.status.code" @click="applyStatus">{{ t('ai_site_requests.apply') }}</button>
                        <p class="text-muted fs-12 mt-2 mb-0">{{ t('ai_site_requests.status_hint') }}</p>

                        <template v-if="request.accepted_at && ! request.status.final">
                            <hr>
                            <label class="form-label fs-13">{{ t('ai_site_requests.extend_deadline') }}</label>
                            <div class="input-group input-group-sm">
                                <input v-model="extendDate" type="date" class="form-control" :min="today">
                                <button type="button" class="btn btn-light" :disabled="! extendDate" @click="extend">{{ t('ai_site_requests.apply') }}</button>
                            </div>
                        </template>
                    </div>
                </div>

                <RequestChat :request-id="request.id" />

                <!-- Handover + notes -->
                <div class="card custom-card">
                    <div class="card-header"><div class="card-title">{{ t('ai_site_requests.handover') }}</div></div>
                    <div class="card-body">
                        <label class="form-label fs-13">{{ t('ai_site_requests.delivery_url') }}</label>
                        <input v-model="internal.delivery_url" class="form-control form-control-sm mb-2" dir="ltr" placeholder="https://">
                        <label class="form-label fs-13">{{ t('ai_site_requests.delivery_notes') }}</label>
                        <textarea v-model="internal.delivery_notes" rows="2" class="form-control form-control-sm mb-2"></textarea>
                        <p class="text-muted fs-12">{{ t('ai_site_requests.handover_hint') }}</p>
                        <label class="form-label fs-13">{{ t('ai_site_requests.admin_notes') }}</label>
                        <textarea v-model="internal.admin_notes" rows="3" class="form-control form-control-sm mb-2"></textarea>
                        <button type="button" class="btn btn-light btn-sm w-100" :disabled="busy" @click="saveInternal">{{ t('ai_site_offers.save') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import adminAxios from '../../../../../../api/adminAxios';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import QuoteBuilder from './QuoteBuilder.vue';
import RequestChat from './RequestChat.vue';

const { t, locale } = useI18n();
const route = useRoute();
const { showSuccess, showError } = useToast();

const request = ref(null);
const statuses = ref([]);
const loading = ref(true);
const busy = ref(false);
const statusForm = reactive({ status: '', note: '' });
const internal = reactive({ delivery_url: '', delivery_notes: '', admin_notes: '' });
const extendDate = ref('');
const now = ref(Date.now());
let ticker = null;

const base = computed(() => `/api/admin/v1/ai-site-requests/${route.params.id}`);
const today = new Date().toISOString().slice(0, 10);
const canQuote = computed(() => request.value && ['submitted', 'under_review', 'quote_sent', 'quote_declined'].includes(request.value.status.code));
const paidPercent = computed(() => (request.value?.payments.total ? Math.round((request.value.payments.paid / request.value.payments.total) * 100) : 0));

const countdown = computed(() => {
    const due = request.value?.timeline.due_at ? new Date(request.value.timeline.due_at).getTime() : null;

    if (! due) {
        return '';
    }

    const diff = Math.abs(due - now.value) / 1000;
    const d = Math.floor(diff / 86400);
    const h = Math.floor((diff % 86400) / 3600);
    const m = Math.floor((diff % 3600) / 60);
    const text = `${d}d ${String(h).padStart(2, '0')}h ${String(m).padStart(2, '0')}m`;

    return due < now.value ? `-${text}` : text;
});

const briefRows = computed(() => {
    const b = request.value?.brief ?? {};
    const list = (v) => (Array.isArray(v) ? v.join('، ') : v);
    const yn = (v) => (v === undefined || v === null ? null : t(v ? 'ai_site_requests.yes' : 'ai_site_requests.no'));
    const rows = [
        { key: 'business_name', value: b.business_name },
        { key: 'site_type', value: b.site_type },
        { key: 'business_description', value: b.business_description, wide: true },
        { key: 'target_audience', value: b.target_audience, wide: true },
        { key: 'goals', value: list(b.goals) },
        { key: 'pages', value: list(b.pages) },
        { key: 'features', value: list(b.features), wide: true },
        { key: 'languages', value: list(b.languages) },
        { key: 'style_notes', value: b.style_notes, wide: true },
        { key: 'colors', value: b.colors },
        { key: 'reference_links', value: list(b.reference_links), wide: true, ltr: true },
        { key: 'has_logo', value: yn(b.has_logo) },
        { key: 'has_domain', value: yn(b.has_domain) },
        { key: 'domain_name', value: b.domain_name, ltr: true },
        { key: 'content_ready', value: b.content_ready ? t('ai_site_requests.content_' + b.content_ready) : null },
        { key: 'budget', value: b.budget_min || b.budget_max ? `${b.budget_min ?? '?'} - ${b.budget_max ?? '?'} ${request.value.currency ?? ''}` : null, ltr: true },
        { key: 'deadline_days', value: b.deadline_days ? `${b.deadline_days} ${t('ai_site_requests.days')}` : null },
        { key: 'contact_name', value: b.contact_name },
        { key: 'contact_phone', value: b.contact_phone, ltr: true },
        { key: 'contact_method', value: b.contact_method ? t('ai_site_requests.method_' + b.contact_method) : null },
        { key: 'contact_time', value: b.contact_time },
        { key: 'notes', value: b.notes, wide: true },
    ];

    return rows.filter((r) => r.value !== undefined && r.value !== null && r.value !== '');
});

function apply(data) {
    request.value = data;
    statusForm.status = data.status.code;
    internal.delivery_url = data.admin?.delivery_url ?? '';
    internal.delivery_notes = data.admin?.delivery_notes ?? '';
    internal.admin_notes = data.admin?.notes ?? '';
}

async function load() {
    try {
        const [{ data }, list] = await Promise.all([
            adminAxios.get(base.value),
            adminAxios.get('/api/admin/v1/ai-site-requests/statuses'),
        ]);
        statuses.value = (list.data.data ?? []).filter((s) => s.is_active);
        apply(data.data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

async function run(call) {
    busy.value = true;

    try {
        const response = await call();
        apply(response.data.data);
        showSuccess(extractApiMessage(response, t('toast.success')));
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        busy.value = false;
    }
}

const applyStatus = () => run(() => adminAxios.post(`${base.value}/status`, { status: statusForm.status, note: statusForm.note || null }).then((r) => { statusForm.note = ''; return r; }));
const setMilestone = (m, status) => run(() => adminAxios.post(`${base.value}/milestones/${m.id}`, { status }));
const release = (p) => run(() => adminAxios.post(`${base.value}/installments/${p.id}/release`));
const extend = () => run(() => adminAxios.post(`${base.value}/extend`, { due_at: `${extendDate.value} 23:59:00` }).then((r) => { extendDate.value = ''; return r; }));
const saveInternal = () => run(() => adminAxios.put(base.value, { ...internal }));

function onUpdated(data) {
    apply(data);
}

async function download(file) {
    try {
        const response = await adminAxios.get(`${base.value}/files/${file.index}`, { responseType: 'blob' });
        const url = URL.createObjectURL(response.data);
        const a = document.createElement('a');
        a.href = url;
        a.download = file.name;
        a.click();
        URL.revokeObjectURL(url);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

onMounted(() => {
    load();
    ticker = setInterval(() => { now.value = Date.now(); }, 30000);
});
onUnmounted(() => clearInterval(ticker));
</script>
