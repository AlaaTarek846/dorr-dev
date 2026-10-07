<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_trial_control.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_trial_control.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_trial_control.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-12">
                <div class="card custom-card">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table text-nowrap table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ t('ai_trial_control.owner') }}</th>
                                        <th scope="col">{{ t('ai_trial_control.trial_status') }}</th>
                                        <th scope="col">{{ t('ai_trial_control.abuse_status') }}</th>
                                        <th scope="col">{{ t('ai_trial_control.abuse_reason') }}</th>
                                        <th scope="col" class="text-end pe-4">{{ t('ai_trial_control.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="5" />

                                    <tr v-else-if="!records.length">
                                        <td colspan="5" class="border-0">
                                            <div class="text-center py-5 text-muted">{{ t('ai_trial_control.empty') }}</div>
                                        </td>
                                    </tr>

                                    <tr v-for="record in records" v-else :key="record.id">
                                        <td>
                                            <span class="d-block fw-semibold">{{ ownerDisplayName(record.owner) }}</span>
                                            <span class="d-block text-muted fs-11">#{{ record.owner?.id }}</span>
                                        </td>
                                        <td>
                                            <select
                                                v-if="drafts[record.id]"
                                                class="form-select form-select-sm w-auto"
                                                v-model="drafts[record.id].trial_status"
                                            >
                                                <option value="eligible">{{ t('ai_trial_control.trial_eligible') }}</option>
                                                <option value="active">{{ t('ai_trial_control.trial_active') }}</option>
                                                <option value="ended">{{ t('ai_trial_control.trial_ended') }}</option>
                                            </select>
                                        </td>
                                        <td>
                                            <select
                                                v-if="drafts[record.id]"
                                                class="form-select form-select-sm w-auto"
                                                v-model="drafts[record.id].abuse_status"
                                            >
                                                <option value="clear">{{ t('ai_trial_control.abuse_clear') }}</option>
                                                <option value="flagged">{{ t('ai_trial_control.abuse_flagged') }}</option>
                                                <option value="blocked">{{ t('ai_trial_control.abuse_blocked') }}</option>
                                            </select>
                                        </td>
                                        <td>
                                            <input
                                                v-if="drafts[record.id]"
                                                type="text"
                                                class="form-control form-control-sm"
                                                v-model="drafts[record.id].abuse_reason"
                                            >
                                        </td>
                                        <td class="text-end pe-4">
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-primary-light"
                                                :disabled="busyId === record.id || ! isDirty(record)"
                                                @click="save(record)"
                                            >
                                                <span v-if="busyId === record.id" class="spinner-border spinner-border-sm me-1"></span>
                                                {{ t('ai_trial_control.save') }}
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <AdminPaginationFooter
                        :pagination="pagination"
                        :current-page="page"
                        :per-page="perPage"
                        :loading="loading"
                        @change-page="onChangePage"
                        @change-per-page="onChangePerPage"
                    />
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import { ownerDisplayName } from '../../../../../../utils/aiOwner';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';

import AdminPaginationFooter from '../../../../../../components/admin/AdminPaginationFooter.vue';
import useAdminPagination from '../../../../../../composables/useAdminPagination';

const { t } = useI18n();
const { showSuccess, showError } = useToast();
const { page, perPage, pagination, paginationParams, applyPagination } = useAdminPagination();

const records = ref([]);
const loading = ref(true);
const busyId = ref(null);

// Root-cause fix - real, observed gap: this screen's Actions column was
// empty (just a leftover header borrowing ai_plans.actions's translation)
// while an unused "save" translation key sat in ai_trial_control's own
// locale file - a dropdown/input change used to fire an update() PUT
// request immediately on every @change, with no explicit confirmation
// step, even though one of the two things being toggled here is BLOCKING
// a real user from ever getting a trial again. drafts holds the
// in-progress edit per row so a changed value only takes effect once the
// admin deliberately clicks Save - isDirty() also keeps that button
// disabled until something has actually changed, so a row no one touched
// can never be "saved" into a no-op request.
const drafts = reactive({});

function seedDraft(record) {
    drafts[record.id] = {
        trial_status: record.trial_status,
        abuse_status: record.abuse_status,
        abuse_reason: record.abuse_reason,
    };
}

function isDirty(record) {
    const draft = drafts[record.id];
    if (! draft) return false;

    return draft.trial_status !== record.trial_status
        || draft.abuse_status !== record.abuse_status
        || draft.abuse_reason !== record.abuse_reason;
}

async function loadRecords() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-trial-control', { params: paginationParams.value });
        records.value = data.data ?? [];
        records.value.forEach(seedDraft);
        applyPagination(data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

async function save(record) {
    const draft = drafts[record.id];
    if (! draft || ! isDirty(record)) return;

    busyId.value = record.id;

    try {
        const response = await adminAxios.put(`/api/admin/v1/ai-trial-control/${record.id}`, draft);
        const updated = response.data?.data;

        Object.assign(record, updated ?? draft);
        seedDraft(record);
        showSuccess(extractApiMessage(response, t('toast.updated')));
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        busyId.value = null;
    }
}

function onChangePage(target) {
    page.value = target;
    loadRecords();
}

function onChangePerPage(value) {
    perPage.value = value;
    page.value = 1;
    loadRecords();
}

onMounted(() => loadRecords());
</script>
