<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">{{ t('freelance.messageReports.title') }}</h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link></li>
                        <li class="breadcrumb-item">{{ t('sidebar.freelance') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('freelance.messageReports.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="alert alert-light border fs-13">{{ t('freelance.messageReports.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <button v-for="s in statuses" :key="s" type="button" class="btn btn-sm btn-wave" :class="status === s ? 'btn-primary' : 'btn-light'" @click="setStatus(s)">
                    {{ t(`freelance.messageReports.status.${s}`) }} <span class="badge bg-white text-default ms-1">{{ counts[s] ?? 0 }}</span>
                </button>
            </div>
            <div class="table-responsive">
                <table class="table text-nowrap mb-0 align-middle">
                    <thead>
                        <tr>
                            <th class="ps-4">{{ t('freelance.messageReports.message') }}</th>
                            <th>{{ t('freelance.messageReports.reason') }}</th>
                            <th>{{ t('freelance.messageReports.people') }}</th>
                            <th>{{ t('freelance.messageReports.when') }}</th>
                            <th class="pe-4">{{ t('freelance.messageReports.decision') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <TableSkeleton v-if="loading" :rows="3" :layout="['two-lines', 'two-lines', 'two-lines', 'text', 'text']" />
                        <tr v-else-if="!rows.length"><td colspan="5" class="text-center text-muted py-5">{{ t('freelance.messageReports.empty') }}</td></tr>
                        <tr v-for="r in rows" v-else :key="r.uuid">
                            <td class="ps-4 text-wrap" style="max-width: 320px">
                                <span class="d-block">{{ r.message.body || '—' }}</span>
                                <a v-if="r.file" href="#" class="fs-12 d-block" @click.prevent="openFile(r.file)"><i class="ri-attachment-2 me-1"></i>{{ r.file.name }}</a>
                                <span v-if="r.message.now_hidden" class="badge bg-secondary-transparent fs-10">{{ t('freelance.messageReports.hidden') }}</span>
                            </td>
                            <td class="text-wrap" style="max-width: 220px">
                                <span class="d-block fw-semibold">{{ t(`freelance.messageReports.reasons.${r.reason}`) }}</span>
                                <span v-if="r.note" class="text-muted fs-11">{{ r.note }}</span>
                            </td>
                            <td class="fs-12">
                                <span class="d-block">{{ t('freelance.messageReports.by') }}: {{ r.reporter.name }} <span class="text-muted">({{ t(`freelance.messageReports.side.${r.reporter.type}`) }})</span></span>
                                <span class="d-block">{{ t('freelance.messageReports.against') }}: {{ r.reported.name }} <span class="text-muted">({{ t(`freelance.messageReports.side.${r.reported.type}`) }})</span></span>
                            </td>
                            <td class="fs-12" dir="ltr">{{ (r.created_at || '').slice(0, 16).replace('T', ' ') }}</td>
                            <td class="pe-4">
                                <template v-if="r.status === 'open'">
                                    <button v-if="canDecide" type="button" class="btn btn-sm btn-primary btn-wave" @click="open(r)">{{ t('freelance.messageReports.decide') }}</button>
                                </template>
                                <template v-else>
                                    <span class="badge" :class="r.status === 'upheld' ? 'bg-danger-transparent' : 'bg-success-transparent'">{{ t(`freelance.messageReports.status.${r.status}`) }}</span>
                                    <span v-if="r.message_hidden" class="d-block text-muted fs-11">{{ t('freelance.messageReports.was_hidden') }}</span>
                                    <span v-if="r.decision_note" class="d-block text-muted fs-11 text-wrap">{{ r.decision_note }}</span>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Deciding one report. Information only: nothing here restricts an account (warnings and limits are a separate, human decision). -->
        <div v-if="current" class="modal fade show d-block" tabindex="-1" style="background: rgba(0, 0, 0, 0.45)" @click.self="current = null">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title">{{ t('freelance.messageReports.decide') }}</h6>
                        <button type="button" class="btn-close" :aria-label="t('close')" @click="current = null"></button>
                    </div>
                    <div class="modal-body">
                        <p class="border rounded p-2 fs-13 text-wrap">{{ current.message.body || '—' }}</p>
                        <div class="d-flex gap-2 mb-3">
                            <button type="button" class="btn btn-sm flex-fill" :class="decision === 'dismiss' ? 'btn-success' : 'btn-light'" @click="decision = 'dismiss'">{{ t('freelance.messageReports.dismiss') }}</button>
                            <button type="button" class="btn btn-sm flex-fill" :class="decision === 'uphold' ? 'btn-danger' : 'btn-light'" @click="decision = 'uphold'">{{ t('freelance.messageReports.uphold') }}</button>
                        </div>
                        <div v-if="decision === 'uphold'" class="form-check mb-3">
                            <input id="hideMessage" v-model="hide" class="form-check-input" type="checkbox" />
                            <label class="form-check-label" for="hideMessage">{{ t('freelance.messageReports.hide') }}</label>
                        </div>
                        <textarea v-model="note" class="form-control" rows="2" maxlength="500" :placeholder="t('freelance.messageReports.note')"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" @click="current = null">{{ t('cancel') }}</button>
                        <button type="button" class="btn btn-primary" :disabled="!decision || saving" @click="save">{{ t('freelance.messageReports.save') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// Reported chat messages (FL-709): the text as it was when reported, the people, the reason; staff dismiss or uphold (and may hide the message).
const { t } = useI18n();
const { showError, showSuccess } = useToast();
const { can } = usePermission();
const endpoint = '/api/admin/v1/freelance-message-reports';
const statuses = ['open', 'upheld', 'dismissed'];

const status = ref('open');
const rows = ref([]);
const counts = ref({});
const loading = ref(false);
const current = ref(null);
const decision = ref('');
const hide = ref(false);
const note = ref('');
const saving = ref(false);
const canDecide = computed(() => can('freelance-message-reports.update'));

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(endpoint, { params: { status: status.value } });
        rows.value = data.data ?? [];
        counts.value = data.pagination?.counts ?? {};
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
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

function setStatus(s) {
    status.value = s;
    load();
}

function open(r) {
    current.value = r;
    decision.value = '';
    hide.value = false;
    note.value = '';
}

async function save() {
    saving.value = true;
    try {
        await adminAxios.post(`${endpoint}/${current.value.uuid}/decide`, {
            decision: decision.value,
            hide_message: decision.value === 'uphold' ? hide.value : false,
            note: note.value || undefined,
        });
        current.value = null;
        showSuccess(t('freelance.messageReports.saved'));
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>
