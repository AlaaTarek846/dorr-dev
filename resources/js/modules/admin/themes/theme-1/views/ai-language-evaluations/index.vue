<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_language_evaluations.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_language_evaluations.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_language_evaluations.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-12">
                <div class="card custom-card">
                    <div class="card-header d-flex align-items-center justify-content-end py-3">
                        <button type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                            <i class="ri-add-line me-1 align-middle"></i>
                            {{ t('ai_language_evaluations.add_short') }}
                        </button>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table text-nowrap table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ t('ai_language_evaluations.name') }}</th>
                                        <th scope="col">{{ t('ai_language_evaluations.language') }}</th>
                                        <th scope="col">{{ t('ai_language_evaluations.test_case_count') }}</th>
                                        <th scope="col">{{ t('ai_language_evaluations.pass_rate') }}</th>
                                        <th scope="col">{{ t('ai_language_evaluations.status') }}</th>
                                        <th scope="col" class="text-end pe-4">{{ t('ai_language_evaluations.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="6" />

                                    <tr v-else-if="!evaluations.length">
                                        <td colspan="6" class="border-0">
                                            <div class="text-center py-5">
                                                <p class="fw-semibold mb-1">{{ t('ai_language_evaluations.empty_title') }}</p>
                                                <p class="text-muted mb-0">{{ t('ai_language_evaluations.empty') }}</p>
                                            </div>
                                        </td>
                                    </tr>

                                    <tr v-for="evaluation in evaluations" v-else :key="evaluation.id">
                                        <td>
                                            <button type="button" class="btn btn-link p-0 text-start fw-semibold text-default" @click="openEdit(evaluation)">
                                                {{ evaluation.name }}
                                            </button>
                                        </td>
                                        <td>{{ evaluation.language?.name || '-' }}</td>
                                        <td>{{ evaluation.test_case_count }}</td>
                                        <td>{{ evaluation.pass_rate != null ? `${evaluation.pass_rate}%` : '-' }}</td>
                                        <td>
                                            <span class="badge" :class="statusBadgeClass(evaluation.status)">{{ evaluation.status }}</span>
                                        </td>
                                        <td class="text-end pe-4">
                                            <div class="btn-list justify-content-end">
                                                <button type="button" class="btn btn-sm btn-info-light btn-icon" @click="openEdit(evaluation)">
                                                    <i class="ri-pencil-line"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-danger-light btn-icon" @click="remove(evaluation)">
                                                    <i class="ri-delete-bin-line"></i>
                                                </button>
                                            </div>
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

        <ModalCreateAndUpdate
            :show="modalShow"
            :type="modalType"
            :record="selectedRecord"
            @close="modalShow = false"
            @saved="onSaved"
        />
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import AdminPaginationFooter from '../../../../../../components/admin/AdminPaginationFooter.vue';
import useAdminPagination from '../../../../../../composables/useAdminPagination';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

const { t } = useI18n();
const { showSuccess, showError } = useToast();

const evaluations = ref([]);
const loading = ref(true);
const modalShow = ref(false);
const modalType = ref('create');
const selectedRecord = ref(null);
const { page, perPage, pagination, paginationParams, applyPagination } = useAdminPagination();

function statusBadgeClass(status) {
    return {
        pending: 'bg-secondary-transparent',
        running: 'bg-info-transparent',
        passed: 'bg-success-transparent',
        failed: 'bg-danger-transparent',
    }[status] ?? 'bg-secondary-transparent';
}

async function loadEvaluations() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-language-evaluations', { params: paginationParams.value });
        evaluations.value = data.data ?? [];
        applyPagination(data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

function openCreate() {
    modalType.value = 'create';
    selectedRecord.value = null;
    modalShow.value = true;
}

function openEdit(evaluation) {
    modalType.value = 'edit';
    selectedRecord.value = { ...evaluation };
    modalShow.value = true;
}

async function remove(evaluation) {
    if (! window.confirm(t('ai_language_evaluations.confirm_delete'))) {
        return;
    }

    try {
        const response = await adminAxios.delete(`/api/admin/v1/ai-language-evaluations/${evaluation.id}`);
        showSuccess(extractApiMessage(response, t('toast.deleted')));
        await loadEvaluations();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

function onSaved() {
    modalShow.value = false;
    loadEvaluations();
}

function onChangePage(target) {
    page.value = target;
    loadEvaluations();
}

function onChangePerPage(value) {
    perPage.value = value;
    page.value = 1;
    loadEvaluations();
}

onMounted(() => loadEvaluations());
</script>
