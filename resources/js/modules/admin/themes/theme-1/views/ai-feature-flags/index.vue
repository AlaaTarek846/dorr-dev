<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_feature_flags.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_feature_flags.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_feature_flags.title') }}</li>
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
                            {{ t('ai_feature_flags.add_short') }}
                        </button>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table text-nowrap table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ t('ai_feature_flags.key') }}</th>
                                        <th scope="col">{{ t('ai_feature_flags.target') }}</th>
                                        <th scope="col">{{ t('ai_feature_flags.scope') }}</th>
                                        <th scope="col">{{ t('ai_feature_flags.environment') }}</th>
                                        <th scope="col">{{ t('ai_feature_flags.status') }}</th>
                                        <th scope="col" class="text-end pe-4">{{ t('ai_feature_flags.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="6" />

                                    <tr v-else-if="!flags.length">
                                        <td colspan="6" class="border-0">
                                            <div class="text-center py-5 text-muted">{{ t('ai_feature_flags.empty') }}</div>
                                        </td>
                                    </tr>

                                    <tr v-for="flag in flags" v-else :key="flag.id">
                                        <td>
                                            <button type="button" class="btn btn-link p-0 text-start fw-semibold text-default" @click="openEdit(flag)">
                                                {{ flag.key }}
                                            </button>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary-transparent">{{ flag.target_type }}</span>
                                            <span class="d-block text-muted fs-11 mt-1">
                                                {{ flag.provider?.name || flag.model_key || flag.tool_key || '-' }}
                                            </span>
                                        </td>
                                        <td>{{ flag.country_code || t('ai_feature_flags.all_countries') }} / {{ flag.domain || t('ai_feature_flags.all_domains') }}</td>
                                        <td>{{ flag.environment }}</td>
                                        <td>
                                            <div
                                                class="toggle toggle-success mb-0"
                                                :class="{ on: flag.is_enabled }"
                                                role="button"
                                                tabindex="0"
                                                @click="toggleEnabled(flag)"
                                                @keydown.enter.space.prevent="toggleEnabled(flag)"
                                            >
                                                <span></span>
                                            </div>
                                        </td>
                                        <td class="text-end pe-4">
                                            <div class="btn-list justify-content-end">
                                                <button type="button" class="btn btn-sm btn-info-light btn-icon" @click="openEdit(flag)">
                                                    <i class="ri-pencil-line"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-danger-light btn-icon" @click="remove(flag)">
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

const flags = ref([]);
const loading = ref(true);
const modalShow = ref(false);
const modalType = ref('create');
const selectedRecord = ref(null);

async function loadFlags() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-feature-flags');
        flags.value = data.data ?? [];
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

function openEdit(flag) {
    modalType.value = 'edit';
    selectedRecord.value = { ...flag };
    modalShow.value = true;
}

async function toggleEnabled(flag) {
    const previous = flag.is_enabled;
    flag.is_enabled = ! flag.is_enabled;

    try {
        const response = await adminAxios.put(`/api/admin/v1/ai-feature-flags/${flag.id}`, { is_enabled: flag.is_enabled });
        showSuccess(extractApiMessage(response, t('toast.status_changed')));
    } catch (error) {
        flag.is_enabled = previous;
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

async function remove(flag) {
    if (! window.confirm(t('ai_feature_flags.confirm_delete'))) {
        return;
    }

    try {
        const response = await adminAxios.delete(`/api/admin/v1/ai-feature-flags/${flag.id}`);
        showSuccess(extractApiMessage(response, t('toast.deleted')));
        await loadFlags();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

function onSaved() {
    modalShow.value = false;
    loadFlags();
}

onMounted(() => loadFlags());
</script>
