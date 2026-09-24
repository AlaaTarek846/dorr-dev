<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_plans.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_plans.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_plans.title') }}</li>
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
                            {{ t('ai_plans.add_short') }}
                        </button>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table text-nowrap table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ t('ai_plans.name') }}</th>
                                        <th scope="col">{{ t('ai_plans.code') }}</th>
                                        <th scope="col">{{ t('ai_plans.usage_minutes') }}</th>
                                        <th scope="col">{{ t('ai_plans.price') }}</th>
                                        <th scope="col">{{ t('ai_plans.is_trial') }}</th>
                                        <th scope="col">{{ t('ai_plans.status') }}</th>
                                        <th scope="col" class="text-end pe-4">{{ t('ai_plans.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="7" />

                                    <tr v-else-if="!plans.length">
                                        <td colspan="7" class="border-0">
                                            <div class="text-center py-5">
                                                <p class="fw-semibold mb-1">{{ t('ai_plans.empty_title') }}</p>
                                                <p class="text-muted mb-0">{{ t('ai_plans.empty') }}</p>
                                            </div>
                                        </td>
                                    </tr>

                                    <tr v-for="plan in plans" v-else :key="plan.id">
                                        <td>
                                            <button type="button" class="btn btn-link p-0 text-start fw-semibold text-default" @click="openEdit(plan)">
                                                {{ plan.name }}
                                            </button>
                                        </td>
                                        <td><span class="badge bg-primary-transparent">{{ plan.code }}</span></td>
                                        <td>{{ plan.usage_minutes }}</td>
                                        <td>{{ plan.price }} {{ plan.currency }}</td>
                                        <td>
                                            <span v-if="plan.is_trial" class="badge bg-warning-transparent">{{ t('ai_plans.is_trial') }}</span>
                                            <span v-else class="text-muted">-</span>
                                        </td>
                                        <td>
                                            <div
                                                class="toggle toggle-success mb-0"
                                                :class="{ on: plan.is_active }"
                                                role="button"
                                                tabindex="0"
                                                @click="toggleActive(plan)"
                                                @keydown.enter.space.prevent="toggleActive(plan)"
                                            >
                                                <span></span>
                                            </div>
                                        </td>
                                        <td class="text-end pe-4">
                                            <div class="btn-list justify-content-end">
                                                <button type="button" class="btn btn-sm btn-info-light btn-icon" @click="openEdit(plan)">
                                                    <i class="ri-pencil-line"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-danger-light btn-icon" @click="remove(plan)">
                                                    <i class="ri-delete-bin-line"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
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

const { t } = useI18n();
const { showSuccess, showError } = useToast();

const plans = ref([]);
const loading = ref(true);
const modalShow = ref(false);
const modalType = ref('create');
const selectedRecord = ref(null);

async function loadPlans() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-plans');
        plans.value = data.data ?? [];
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

function openEdit(plan) {
    modalType.value = 'edit';
    selectedRecord.value = { ...plan };
    modalShow.value = true;
}

async function toggleActive(plan) {
    const previous = plan.is_active;
    plan.is_active = ! plan.is_active;

    try {
        const response = await adminAxios.put(`/api/admin/v1/ai-plans/${plan.id}`, { is_active: plan.is_active });
        showSuccess(extractApiMessage(response, t('toast.status_changed')));
    } catch (error) {
        plan.is_active = previous;
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

async function remove(plan) {
    if (! window.confirm(t('ai_plans.confirm_delete'))) {
        return;
    }

    try {
        const response = await adminAxios.delete(`/api/admin/v1/ai-plans/${plan.id}`);
        showSuccess(extractApiMessage(response, t('toast.deleted')));
        await loadPlans();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

function onSaved() {
    modalShow.value = false;
    loadPlans();
}

onMounted(() => loadPlans());
</script>
