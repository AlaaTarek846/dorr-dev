<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('referral_codes.title') }}
                <span v-if="pagination?.total != null" class="badge bg-primary-transparent ms-2 fs-12 align-middle">
                    {{ pagination.total }}
                </span>
            </h1>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="input-group input-group-sm catalog-toolbar-search">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="search" type="search" class="form-control" :placeholder="t('referral_codes.search')">
                </div>
                <Select
                    v-model="ownerTypeFilter"
                    :options="ownerTypeOptions"
                    option-label="label"
                    option-value="value"
                    class="wallet-filter-select"
                />
                <Select
                    v-model="statusFilter"
                    :options="statusOptions"
                    option-label="label"
                    option-value="value"
                    class="wallet-filter-select"
                />
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('referral_codes.code') }}</th>
                                <th>{{ t('referral_codes.owner') }}</th>
                                <th>{{ t('referral_codes.owner_type') }}</th>
                                <th>{{ t('referral_codes.status') }}</th>
                                <th>{{ t('referral_codes.count') }}</th>
                                <th>{{ t('referral_codes.created') }}</th>
                                <th class="text-end pe-4">{{ t('referral_codes.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="8" :columns="7" />
                            <tr v-else-if="!codes.length">
                                <td colspan="7" class="border-0">
                                    <div class="text-center py-5">
                                        <p class="fw-semibold mb-1">{{ t('referral_codes.empty_title') }}</p>
                                        <p class="text-muted mb-0">{{ t('referral_codes.empty') }}</p>
                                    </div>
                                </td>
                            </tr>
                            <tr v-for="row in codes" v-else :key="row.id">
                                <td class="ps-4 font-monospace">{{ row.code }}</td>
                                <td>{{ row.owner?.name || `#${row.owner?.id}` }}</td>
                                <td>{{ ownerLabel(row.owner?.type) }}</td>
                                <td>
                                    <span class="badge" :class="row.is_active ? 'bg-success-transparent' : 'bg-danger-transparent'">
                                        {{ row.is_active ? t('referral_codes.active') : t('referral_codes.inactive') }}
                                    </span>
                                </td>
                                <td>{{ row.referrals_count ?? 0 }}</td>
                                <td>{{ formatDate(row.created_at) }}</td>
                                <td class="text-end pe-4">
                                    <button type="button" class="btn btn-sm btn-light" :title="t('referral_codes.view')" @click="openShow(row.id)">
                                        <i class="ri-eye-line"></i>
                                    </button>
                                    <button
                                        v-if="canChangeStatus && row.is_active"
                                        type="button"
                                        class="btn btn-sm btn-light ms-1"
                                        :title="t('referral_codes.deactivate')"
                                        @click="changeStatus(row.id, false)"
                                    >
                                        <i class="ri-forbid-line"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <ModalShow :show="modalShow" :record="selectedRecord" @close="closeModal" />
    </div>
</template>

<script setup>
import Select from 'primevue/select';
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import { useCatalogPermissions } from '../../../../../../composables/useCatalogPermissions';
import { useReferralCodes } from '../../../../../../composables/useReferralCodes';
import ModalShow from './ModalShow.vue';

const { t, locale } = useI18n();
const { canChangeStatus } = useCatalogPermissions('referral-codes');
const api = useReferralCodes();
const {
    codes, loading, pagination, search, ownerTypeFilter, statusFilter, fetchCodes, changeStatus, showRecord, selectedRecord, modalShow, closeModal,
} = api;

const ownerTypeOptions = computed(() => [
    { value: 'all', label: t('referral_codes.filter_all_owners') },
    { value: 'user', label: t('referral_codes.type_user') },
    { value: 'provider', label: t('referral_codes.type_provider') },
]);

const statusOptions = computed(() => [
    { value: 'all', label: t('referral_codes.filter_all_status') },
    { value: 'active', label: t('referral_codes.active') },
    { value: 'inactive', label: t('referral_codes.inactive') },
]);

function ownerLabel(type) {
    if (type === 'user') return t('referral_codes.type_user');
    if (type === 'provider') return t('referral_codes.type_provider');
    return type || '—';
}

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        year: 'numeric', month: 'short', day: 'numeric',
    });
}

async function openShow(id) {
    await showRecord(id);
}

onMounted(() => fetchCodes(1));
</script>
