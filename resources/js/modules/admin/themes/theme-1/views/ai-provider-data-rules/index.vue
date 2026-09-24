<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_provider_data_rules.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_provider_data_rules.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_provider_data_rules.title') }}</li>
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
                            {{ t('ai_provider_data_rules.add_short') }}
                        </button>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table text-nowrap table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ t('ai_provider_data_rules.provider') }}</th>
                                        <th scope="col">{{ t('ai_provider_data_rules.sanitize_pii') }}</th>
                                        <th scope="col">{{ t('ai_provider_data_rules.sanitize_secrets') }}</th>
                                        <th scope="col">{{ t('ai_provider_data_rules.status') }}</th>
                                        <th scope="col" class="text-end pe-4">{{ t('ai_provider_data_rules.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="5" />

                                    <tr v-else-if="!rules.length">
                                        <td colspan="5" class="border-0">
                                            <div class="text-center py-5">
                                                <p class="fw-semibold mb-1">{{ t('ai_provider_data_rules.empty_title') }}</p>
                                                <p class="text-muted mb-0">{{ t('ai_provider_data_rules.empty') }}</p>
                                            </div>
                                        </td>
                                    </tr>

                                    <tr v-for="rule in rules" v-else :key="rule.id">
                                        <td>
                                            <button type="button" class="btn btn-link p-0 text-start fw-semibold text-default" @click="openEdit(rule)">
                                                {{ rule.provider?.name || '#' + rule.provider_id }}
                                            </button>
                                        </td>
                                        <td><i class="fs-16" :class="boolIconClass(rule.sanitize_pii)"></i></td>
                                        <td><i class="fs-16" :class="boolIconClass(rule.sanitize_secrets)"></i></td>
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

const rules = ref([]);
const loading = ref(true);
const modalShow = ref(false);
const modalType = ref('create');
const selectedRecord = ref(null);

function boolIconClass(value) {
    return value ? 'ri-checkbox-circle-fill text-success' : 'ri-close-circle-line text-muted';
}

async function loadRules() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-provider-data-rules');
        rules.value = data.data ?? [];
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
        const response = await adminAxios.put(`/api/admin/v1/ai-provider-data-rules/${rule.id}`, { is_active: rule.is_active });
        showSuccess(extractApiMessage(response, t('toast.status_changed')));
    } catch (error) {
        rule.is_active = previous;
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

async function remove(rule) {
    if (! window.confirm(t('ai_provider_data_rules.confirm_delete'))) {
        return;
    }

    try {
        const response = await adminAxios.delete(`/api/admin/v1/ai-provider-data-rules/${rule.id}`);
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

onMounted(() => loadRules());
</script>
