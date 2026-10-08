<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_routing_rules.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_routing_rules.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_routing_rules.title') }}</li>
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
                            {{ t('ai_routing_rules.add_short') }}
                        </button>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table text-nowrap table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ t('ai_routing_rules.policy') }}</th>
                                        <th scope="col">{{ t('ai_routing_rules.intent') }}</th>
                                        <th scope="col">{{ t('ai_routing_rules.provider') }}</th>
                                        <th scope="col">{{ t('ai_routing_rules.model_key') }}</th>
                                        <th scope="col">{{ t('ai_routing_rules.priority') }}</th>
                                        <th scope="col">{{ t('ai_routing_rules.status') }}</th>
                                        <th scope="col" class="text-end pe-4">{{ t('ai_routing_rules.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="7" />

                                    <tr v-else-if="!rules.length">
                                        <td colspan="7" class="border-0">
                                            <div class="text-center py-5">
                                                <p class="fw-semibold mb-1">{{ t('ai_routing_rules.empty_title') }}</p>
                                                <p class="text-muted mb-0">{{ t('ai_routing_rules.empty') }}</p>
                                            </div>
                                        </td>
                                    </tr>

                                    <tr v-for="rule in rules" v-else :key="rule.id">
                                        <td>
                                            <button type="button" class="btn btn-link p-0 text-start fw-semibold text-default" @click="openEdit(rule)">
                                                {{ rule.policy?.name || '#' + rule.routing_policy_id }}
                                            </button>
                                        </td>
                                        <td>{{ rule.intent?.name || '-' }}</td>
                                        <td>{{ rule.provider?.name || '-' }}</td>
                                        <td>{{ rule.model_key || '-' }}</td>
                                        <td>{{ rule.priority }}</td>
                                        <td>
                                            <div
                                                class="toggle toggle-success mb-0"
                                                :class="{ on: rule.is_active }"
                                                role="button"
                                                tabindex="0"
                                                @click="toggleActive(rule)"
                                                @keydown.enter.space.prevent="toggleActive(rule)"
                                            >
                                                <span></span>
                                            </div>
                                        </td>
                                        <td class="text-end pe-4">
                                            <div class="btn-list justify-content-end">
                                                <button type="button" class="btn btn-sm btn-info-light btn-icon" @click="openEdit(rule)">
                                                    <i class="ri-pencil-line"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-danger-light btn-icon" @click="remove(rule)">
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
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

import AdminPaginationFooter from '../../../../../../components/admin/AdminPaginationFooter.vue';
import useAdminPagination from '../../../../../../composables/useAdminPagination';

const { t } = useI18n();
const { showSuccess, showError } = useToast();
const { page, perPage, pagination, paginationParams, applyPagination } = useAdminPagination();

const rules = ref([]);
const loading = ref(true);
const modalShow = ref(false);
const modalType = ref('create');
const selectedRecord = ref(null);

async function loadRules() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-routing-rules', { params: paginationParams.value });
        rules.value = data.data ?? [];
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

function openEdit(rule) {
    modalType.value = 'edit';
    selectedRecord.value = { ...rule };
    modalShow.value = true;
}

async function toggleActive(rule) {
    const previous = rule.is_active;
    rule.is_active = ! rule.is_active;

    try {
        const response = await adminAxios.put(`/api/admin/v1/ai-routing-rules/${rule.id}`, { is_active: rule.is_active });
        showSuccess(extractApiMessage(response, t('toast.status_changed')));
    } catch (error) {
        rule.is_active = previous;
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

async function remove(rule) {
    if (! window.confirm(t('ai_routing_rules.confirm_delete'))) {
        return;
    }

    try {
        const response = await adminAxios.delete(`/api/admin/v1/ai-routing-rules/${rule.id}`);
        showSuccess(extractApiMessage(response, t('toast.deleted')));
        await loadRules();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

function onSaved() {
    modalShow.value = false;
    loadRules();
}

function onChangePage(target) {
    page.value = target;
    loadRules();
}

function onChangePerPage(value) {
    perPage.value = value;
    page.value = 1;
    loadRules();
}

onMounted(() => loadRules());
</script>
