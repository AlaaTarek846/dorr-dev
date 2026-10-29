<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('freelance.disputes.title') }}
                <span v-if="waiting" class="badge bg-danger-transparent ms-2 fs-12 align-middle">{{ t('freelance.disputes.waiting', { n: waiting }) }}</span>
            </h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link></li>
                        <li class="breadcrumb-item">{{ t('sidebar.freelance') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('freelance.disputes.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="alert alert-light border fs-13">{{ t('freelance.disputes.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <Select v-model="statusFilter" :options="statusOptions" option-label="label" option-value="value" class="wallet-filter-select" />
                <Select v-model="categoryFilter" :options="categoryOptions" option-label="label" option-value="value" class="wallet-filter-select" />
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('freelance.disputes.project') }}</th>
                                <th>{{ t('freelance.disputes.reason') }}</th>
                                <th>{{ t('freelance.disputes.amount') }}</th>
                                <th>{{ t('freelance.disputes.opened') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="text-end pe-4">{{ t('countries.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="8" :layout="['two-lines', 'text', 'text', 'two-lines', 'badge', 'actions']" />
                            <tr v-else-if="!rows.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="row in rows" v-else :key="row.uuid">
                                <td class="ps-4">
                                    <span class="fw-semibold d-block text-truncate dispute-title">{{ row.project.title || '—' }}</span>
                                    <span class="text-muted fs-11">{{ row.milestone.title }}</span>
                                </td>
                                <td>
                                    <span class="d-block">{{ row.reason_label }}</span>
                                    <span class="text-muted fs-11">{{ t(`freelance.disputes.by.${row.opened_by}`) }}</span>
                                    <span v-if="row.category" class="badge bg-primary-transparent ms-1">{{ t(`freelance.disputeCategory.${row.category}`) }}</span>
                                </td>
                                <td dir="ltr">{{ money(row.milestone.amount_minor) }} {{ row.currency }}</td>
                                <td>
                                    <span class="d-block">{{ formatCatalogDate(row.opened_at, locale) }}</span>
                                    <span v-if="row.overdue" class="text-danger fs-11">{{ t('freelance.disputes.overdue') }}</span>
                                    <span v-else-if="row.status === 'open'" class="text-muted fs-11">{{ t('freelance.disputes.respond_by', { date: formatCatalogDate(row.respond_by, locale) }) }}</span>
                                </td>
                                <td><span class="badge" :class="statusBadge(row.status)">{{ t(`freelance.disputes.status.${row.status}`) }}</span></td>
                                <td class="text-end pe-4">
                                    <button type="button" class="btn btn-sm btn-wave" :class="needsDecision(row.status) ? 'btn-primary' : 'btn-light'" @click="open(row)">
                                        <i :class="needsDecision(row.status) ? 'ri-scales-3-line' : 'ri-eye-line'" class="me-1"></i>{{ needsDecision(row.status) ? t('freelance.disputes.decide') : t('freelance.verifications.view') }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="!loading && rows.length" class="card-footer border-top-0 d-flex align-items-center flex-wrap gap-3">
                <div class="text-muted fs-13">{{ t('countries.showing_entries', { from: pagination.from ?? 0, to: pagination.to ?? 0, total: pagination.total ?? 0 }) }}</div>
                <nav v-if="(pagination.last_page ?? 1) > 1" class="pagination-style-4 ms-md-auto">
                    <ul class="pagination mb-0">
                        <li class="page-item" :class="{ disabled: page <= 1 }"><button type="button" class="page-link" @click="page -= 1">{{ t('countries.previous') }}</button></li>
                        <li class="page-item active"><span class="page-link">{{ page }}</span></li>
                        <li class="page-item" :class="{ disabled: page >= pagination.last_page }"><button type="button" class="page-link text-primary" @click="page += 1">{{ t('countries.next') }}</button></li>
                    </ul>
                </nav>
            </div>
        </div>

        <!-- One case: what each side said and showed, the part and its history, the agreement, the chat; then the decision. -->
        <WalletModal :show="!!current" :title="t('freelance.disputes.case')" size="xl" @close="close">
            <div v-if="current">
                <div class="d-flex align-items-start gap-3 mb-3 flex-wrap">
                    <div>
                        <div class="fw-semibold">{{ current.project.title }}</div>
                        <div class="text-muted fs-12">{{ current.milestone?.title }} · <span dir="ltr">{{ money(current.milestone?.amount_minor) }} {{ current.currency }}</span></div>
                        <div class="text-muted fs-12">{{ t('freelance.disputes.client') }}: <UserName :user="current.client">{{ current.client.name }}</UserName> · {{ t('freelance.disputes.freelancer') }}: {{ current.freelancer.name }}</div>
                    </div>
                    <span class="badge ms-auto" :class="statusBadge(current.status)">{{ t(`freelance.disputes.status.${current.status}`) }}</span>
                </div>

                <div class="border rounded p-3 mb-3">
                    <div class="fw-semibold fs-13">{{ current.reason_label }} · {{ t(`freelance.disputes.by.${current.opened_by}`) }}</div>
                    <div class="fs-13 mt-1" style="white-space: pre-line">{{ current.details }}</div>
                    <div class="text-muted fs-11 mt-1">{{ formatCatalogDate(current.opened_at, locale) }} · {{ t('freelance.disputes.respond_by', { date: formatCatalogDate(current.respond_by, locale) }) }}</div>
                </div>

                <!-- FL-803: staff's label for the problem; the person's own reason stays as it was. -->
                <div class="border rounded p-3 mb-3">
                    <div class="fs-12 fw-semibold mb-2">{{ t('freelance.disputeCategory.title') }}</div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <button
                            v-for="c in categories"
                            :key="c"
                            type="button"
                            class="btn btn-sm"
                            :class="(current.category ?? null) === c ? 'btn-primary' : 'btn-outline-primary'"
                            :disabled="!canClassify || classifying"
                            @click="classify(c)"
                        >
                            {{ t(`freelance.disputeCategory.${c}`) }}
                        </button>
                    </div>
                    <div v-if="!current.category" class="text-muted fs-11 mt-2">{{ t('freelance.disputeCategory.suggested', { name: t(`freelance.disputeCategory.${current.suggested_category}`) }) }}</div>
                </div>

                <div v-if="current.appeal" class="alert alert-warning fs-13">
                    <div class="fw-semibold">{{ t('freelance.disputes.appeal_by', { side: t(`freelance.disputes.side.${current.appeal.by}`) }) }}</div>
                    <div style="white-space: pre-line">{{ current.appeal.reason }}</div>
                </div>
                <div v-if="current.decision" class="alert fs-13" :class="current.status === 'resolved' ? 'alert-success' : 'alert-info'">
                    <div class="fw-semibold">{{ decisionText(current.decision) }}<template v-if="current.decision.final"> · {{ t('freelance.disputes.final') }}</template></div>
                    <div style="white-space: pre-line">{{ current.decision.note }}</div>
                    <div class="fs-11 mt-1">
                        <template v-if="current.status === 'resolved'">{{ t('freelance.disputes.done_at', { date: formatCatalogDate(current.resolved_at, locale) }) }}</template>
                        <template v-else-if="current.decision.executes_at">{{ t('freelance.disputes.runs_at', { date: formatCatalogDate(current.decision.executes_at, locale) }) }}</template>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-6">
                        <h6 class="fs-13 fw-semibold">{{ t('freelance.disputes.evidence') }}</h6>
                        <div v-if="!current.evidence.length" class="text-muted fs-12">{{ t('freelance.disputes.no_evidence') }}</div>
                        <div v-for="e in current.evidence" :key="e.id" class="border rounded p-2 mb-2 fs-13" :class="e.side === 'client' ? 'border-primary-subtle' : 'border-success-subtle'">
                            <div class="fw-semibold fs-12">{{ t(`freelance.disputes.side.${e.side}`) }} · <span class="text-muted">{{ formatCatalogDate(e.at, locale) }}</span></div>
                            <div style="white-space: pre-line">{{ e.body }}</div>
                            <a v-if="e.file" href="#" class="fs-12" @click.prevent="openFile(e.file)"><i class="ri-attachment-2 me-1"></i>{{ e.file.name }}</a>
                        </div>

                        <h6 class="fs-13 fw-semibold mt-3">{{ t('freelance.disputes.part') }}</h6>
                        <div class="fs-12">
                            <div><span class="text-muted">{{ t('freelance.disputes.held') }}:</span> <span dir="ltr">{{ money(current.part.held_minor) }} {{ current.currency }}</span></div>
                            <div><span class="text-muted">{{ t('freelance.disputes.revisions_used') }}:</span> {{ current.part.revisions_used }}</div>
                            <div v-if="current.part.delivery_note"><span class="text-muted">{{ t('freelance.disputes.delivery') }}:</span> {{ current.part.delivery_note }}</div>
                            <div v-if="current.part.revision_reason"><span class="text-muted">{{ t('freelance.disputes.last_changes') }}:</span> {{ current.part.revision_reason }}</div>
                            <div v-for="(h, i) in current.part.history" :key="i" class="text-muted">{{ formatCatalogDate(h.at, locale) }} · {{ h.type }}</div>
                        </div>

                        <h6 class="fs-13 fw-semibold mt-3">{{ t('freelance.disputes.agreement') }}</h6>
                        <div class="fs-12" style="white-space: pre-line">{{ current.agreement.scope }}</div>
                        <div v-if="current.agreement.includes" class="fs-12 mt-1"><span class="text-muted">{{ t('freelance.disputes.includes') }}:</span> {{ current.agreement.includes }}</div>
                        <div v-if="current.agreement.excludes" class="fs-12"><span class="text-muted">{{ t('freelance.disputes.excludes') }}:</span> {{ current.agreement.excludes }}</div>
                    </div>
                    <div class="col-lg-6">
                        <h6 class="fs-13 fw-semibold">{{ t('freelance.disputes.chat') }}</h6>
                        <div class="border rounded p-2 dispute-chat">
                            <div v-if="!current.chat.length" class="text-muted fs-12">{{ t('freelance.disputes.no_chat') }}</div>
                            <div v-for="(msg, i) in current.chat" :key="i" class="fs-12 mb-1">
                                <span class="fw-semibold" :class="msg.from === 'client' ? 'text-primary' : 'text-success'">{{ t(`freelance.disputes.side.${msg.from}`) }}:</span>
                                {{ msg.body ?? `[${msg.kind}]` }}
                                <span class="text-muted fs-11">· {{ formatCatalogDate(msg.at, locale) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FL-606: everything on the project in one list, oldest first (reading it is on the audit log). -->
                <div class="border rounded p-3 mt-3">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <h6 class="fs-13 fw-semibold mb-0 me-auto">{{ t('freelance.disputes.timeline') }}</h6>
                        <button type="button" class="btn btn-sm btn-outline-primary" :disabled="timelineLoading" @click="toggleTimeline">
                            {{ timeline ? t('freelance.disputes.timeline_hide') : t('freelance.disputes.timeline_show') }}
                        </button>
                        <button v-if="timeline" type="button" class="btn btn-sm btn-outline-secondary" @click="exportTimeline"><i class="ri-download-2-line me-1"></i>{{ t('freelance.disputes.timeline_export') }}</button>
                    </div>
                    <div v-if="timeline" class="dispute-chat mt-2">
                        <div v-if="!timeline.length" class="text-muted fs-12">{{ t('freelance.disputes.timeline_empty') }}</div>
                        <div v-for="(e, i) in timeline" :key="i" class="fs-12 py-1 border-bottom">
                            <span class="badge me-1" :class="kindBadge(e.kind)">{{ t(`freelance.disputes.timeline_kind.${e.kind}`) }}</span>
                            <span v-if="e.actor" class="fw-semibold me-1" :class="actorClass(e.actor)">{{ actorText(e.actor) }}</span>
                            <span style="white-space: pre-line">{{ e.label }}</span>
                            <span class="text-muted fs-11"> · {{ formatCatalogDate(e.at, locale) }}</span>
                            <div v-if="e.detail" class="text-muted ps-2" style="white-space: pre-line">{{ e.detail }}</div>
                            <div v-for="f in e.files" :key="f.path" class="ps-2">
                                <a href="#" @click.prevent="openFile(f)"><i class="ri-attachment-2 me-1"></i>{{ f.name }}</a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- The decision: what it does to the money is shown before it is taken. -->
                <div v-if="canDecide" class="border rounded p-3 mt-3">
                    <h6 class="fs-13 fw-semibold">{{ current.status === 'appealed' ? t('freelance.disputes.decide_appeal') : t('freelance.disputes.decide') }}</h6>
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <button v-for="d in ['refund', 'release', 'split']" :key="d" type="button" class="btn btn-sm" :class="form.decision === d ? 'btn-primary' : 'btn-outline-primary'" @click="form.decision = d">{{ t(`freelance.disputes.decision.${d}`) }}</button>
                    </div>
                    <div v-if="form.decision === 'split'" class="input-group input-group-sm mb-2 split-input">
                        <span class="input-group-text">{{ t('freelance.disputes.freelancer_share') }}</span>
                        <input v-model.number="form.percent" type="number" min="1" max="99" step="1" class="form-control" dir="ltr">
                        <span class="input-group-text">%</span>
                    </div>
                    <div v-if="outcome" class="fs-12 mb-2">
                        <span class="text-success">{{ t('freelance.disputes.to_freelancer', { amount: `${money(outcome.freelancer)} ${current.currency}` }) }}</span> ·
                        <span class="text-primary">{{ t('freelance.disputes.to_client', { amount: `${money(outcome.client)} ${current.currency}` }) }}</span>
                        <span class="text-muted">{{ t('freelance.disputes.before_fees') }}</span>
                    </div>
                    <textarea v-model="form.note" rows="3" maxlength="3000" class="form-control" :placeholder="t('freelance.disputes.note_hint')"></textarea>
                    <div class="text-muted fs-11 mt-1">{{ current.status === 'appealed' ? t('freelance.disputes.appeal_rule') : t('freelance.disputes.first_rule') }}</div>
                </div>
            </div>
            <template #footer>
                <button type="button" class="btn btn-light" @click="close">{{ t('cancel') }}</button>
                <button v-if="canDecide" type="button" class="btn btn-primary" :disabled="working || !ready" @click="decide">
                    <span v-if="working" class="spinner-border spinner-border-sm me-1"></span>{{ t('freelance.disputes.confirm') }}
                </button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Select from 'primevue/select';
import adminAxios from '../../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';
import { formatCatalogDate } from '../../../../../../../utils/catalog';
import UserName from '../../../../../../../components/ui/UserName.vue';

// The disputes desk (FL-705): appealed and open cases first. A case shows everything both sides said and showed, the part with its
// delivery and history, the agreement and the chat. The decision moves the held money — back to the client, to the freelancer, or a
// split — after the appeal days; an appeal is decided by another member of staff, finally and at once.
const { t, locale } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const endpoint = '/api/admin/v1/freelance-disputes';

const rows = ref([]);
const pagination = ref({});
const counts = ref({});
const loading = ref(false);
const statusFilter = ref('all');
const page = ref(1);

const statuses = ['open', 'appealed', 'decided', 'resolved'];
const categories = ['payment', 'delivery', 'quality', 'conduct', 'scope', 'other'];
const categoryFilter = ref('all');
const classifying = ref(false);
const categoryOptions = computed(() => [
    { value: 'all', label: t('freelance.disputeCategory.all') },
    ...categories.map((c) => ({ value: c, label: t(`freelance.disputeCategory.${c}`) })),
]);
const total = computed(() => Object.values(counts.value).reduce((a, b) => a + b, 0));
const waiting = computed(() => (counts.value.open ?? 0) + (counts.value.appealed ?? 0));
const statusOptions = computed(() => [
    { value: 'all', label: `${t('countries.filter_all')} (${total.value})` },
    ...statuses.map((s) => ({ value: s, label: `${t(`freelance.disputes.status.${s}`)} (${counts.value[s] ?? 0})` })),
]);
const statusBadge = (s) => ({ open: 'bg-warning-transparent', appealed: 'bg-danger-transparent', decided: 'bg-info-transparent', resolved: 'bg-success-transparent' }[s] ?? 'bg-light text-dark');
const needsDecision = (s) => s === 'open' || s === 'appealed';
const money = (minor) => (minor == null ? '—' : (minor / 100).toFixed(2));
const decisionText = (d) => (d.type === 'split' ? t('freelance.disputes.decision_split', { n: (d.freelancer_bp ?? 0) / 100 }) : t(`freelance.disputes.decision.${d.type}`));

watch([statusFilter, categoryFilter], () => (page.value === 1 ? load() : (page.value = 1)));
watch(page, load);

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(endpoint, { params: { page: page.value, status: statusFilter.value === 'all' ? undefined : statusFilter.value, category: categoryFilter.value === 'all' ? undefined : categoryFilter.value } });
        rows.value = data.data ?? [];
        pagination.value = data.pagination ?? {};
        counts.value = data.pagination?.counts ?? {};
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

// ---- one case
const current = ref(null);
const working = ref(false);
// FL-606: the evidence timeline, read when asked for.
const timeline = ref(null);
const timelineLoading = ref(false);
const kindBadge = (k) => ({ agreement: 'bg-primary-transparent', approval: 'bg-info-transparent', work: 'bg-secondary-transparent', delivery: 'bg-success-transparent', dispute: 'bg-danger-transparent', evidence: 'bg-warning-transparent', chat: 'bg-light text-dark' }[k] ?? 'bg-light text-dark');
const actorClass = (a) => (a === 'client' ? 'text-primary' : a === 'freelancer' ? 'text-success' : 'text-muted');
const actorText = (a) => (['client', 'freelancer'].includes(a) ? t(`freelance.disputes.side.${a}`) : t('freelance.disputes.timeline_staff'));
const form = reactive({ decision: null, percent: 50, note: '' });
const canClassify = computed(() => can('freelance-disputes.update'));
const canDecide = computed(() => !!current.value?.can?.decide && can('freelance-disputes.update'));
const ready = computed(() => !!form.decision && form.note.trim().length >= 10 && (form.decision !== 'split' || (form.percent > 0 && form.percent < 100)));
// What the decision does to the part's amount (the fee and the commission follow the same share).
const outcome = computed(() => {
    const amount = current.value?.milestone?.amount_minor;
    if (!form.decision || amount == null) return null;
    const share = form.decision === 'release' ? amount : form.decision === 'refund' ? 0 : Math.floor((amount * Math.round((form.percent || 0) * 100)) / 10000);
    return { freelancer: share, client: amount - share };
});

async function open(row) {
    timeline.value = null;
    try {
        const { data } = await adminAxios.get(`${endpoint}/${row.uuid}`);
        current.value = data.data;
        Object.assign(form, { decision: null, percent: 50, note: '' });
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

function close() {
    current.value = null;
    timeline.value = null;
}

async function toggleTimeline() {
    if (timeline.value) {
        timeline.value = null;
        return;
    }
    timelineLoading.value = true;
    try {
        const { data } = await adminAxios.get(`${endpoint}/${current.value.uuid}/timeline`);
        timeline.value = data.data.entries ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        timelineLoading.value = false;
    }
}

async function exportTimeline() {
    try {
        const { data } = await adminAxios.get(`${endpoint}/${current.value.uuid}/timeline/export`, { responseType: 'blob' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(data);
        link.download = `dispute-${current.value.uuid}-timeline.csv`;
        link.click();
        URL.revokeObjectURL(link.href);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

async function openFile(file) {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/${file.path}`, { responseType: 'blob' });
        window.open(URL.createObjectURL(data), '_blank', 'noopener');
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

async function classify(category) {
    classifying.value = true;
    try {
        const response = await adminAxios.post(`${endpoint}/${current.value.uuid}/classify`, { category });
        showSuccess(extractApiMessage(response, t('toast.success')));
        current.value = response.data.data;
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        classifying.value = false;
    }
}

async function decide() {
    const amounts = outcome.value;
    const sure = window.confirm(t('freelance.disputes.confirm_text', {
        freelancer: `${money(amounts.freelancer)} ${current.value.currency}`,
        client: `${money(amounts.client)} ${current.value.currency}`,
    }));
    if (!sure) return;
    working.value = true;
    try {
        const body = { decision: form.decision, note: form.note.trim(), ...(form.decision === 'split' ? { freelancer_percent: form.percent } : {}) };
        const response = await adminAxios.post(`${endpoint}/${current.value.uuid}/decide`, body);
        showSuccess(extractApiMessage(response, t('toast.success')));
        current.value = response.data.data;
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        working.value = false;
    }
}

onMounted(load);
</script>

<style scoped>
.dispute-title {
    max-width: 280px;
}

.dispute-chat {
    max-height: 420px;
    overflow-y: auto;
}

.split-input {
    max-width: 320px;
}
</style>
