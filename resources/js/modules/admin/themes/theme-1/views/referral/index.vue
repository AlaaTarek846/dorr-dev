<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('referrals.title') }}
                <span v-if="pagination?.total != null" class="badge bg-primary-transparent ms-2 fs-12 align-middle">
                    {{ pagination.total }}
                </span>
            </h1>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="input-group input-group-sm catalog-toolbar-search">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="search" type="search" class="form-control" :placeholder="t('referrals.search')">
                </div>
                <Select v-model="statusFilter" :options="statusOptions" option-label="label" option-value="value" class="wallet-filter-select" />
                <Select v-model="referrerTypeFilter" :options="typeOptions" option-label="label" option-value="value" class="wallet-filter-select" />
                <Select v-model="referredTypeFilter" :options="referredOptions" option-label="label" option-value="value" class="wallet-filter-select" />
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('referrals.code') }}</th>
                                <th>{{ t('referrals.referrer') }}</th>
                                <th>{{ t('referrals.referred') }}</th>
                                <th>{{ t('referrals.status') }}</th>
                                <th>{{ t('referrals.registered') }}</th>
                                <th>{{ t('referrals.completed') }}</th>
                                <th class="text-end pe-4">{{ t('referrals.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="8" :columns="7" />
                            <tr v-else-if="!referrals.length">
                                <td colspan="7" class="border-0">
                                    <div class="text-center py-5">
                                        <p class="fw-semibold mb-1">{{ t('referrals.empty_title') }}</p>
                                        <p class="text-muted mb-0">{{ t('referrals.empty') }}</p>
                                    </div>
                                </td>
                            </tr>
                            <tr v-for="row in referrals" v-else :key="row.id">
                                <td class="ps-4 font-monospace">{{ row.referral_code }}</td>
                                <td>{{ row.referrer?.name || `#${row.referrer?.id}` }} ({{ row.referrer?.type }})</td>
                                <td>{{ row.referred?.name || `#${row.referred?.id}` }} ({{ row.referred?.type }})</td>
                                <td>{{ row.status }}</td>
                                <td>{{ formatDate(row.registered_at) }}</td>
                                <td>{{ formatDate(row.completed_at) }}</td>
                                <td class="text-end pe-4">
                                    <button type="button" class="btn btn-sm btn-light" @click="showRecord(row.id)">
                                        <i class="ri-eye-line"></i>
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
import { useReferrals } from '../../../../../../composables/useReferrals';
import ModalShow from './ModalShow.vue';

const { t, locale } = useI18n();
const api = useReferrals();
const {
    referrals, loading, pagination, search, statusFilter, referrerTypeFilter, referredTypeFilter, fetchReferrals, showRecord, selectedRecord, modalShow, closeModal,
} = api;

const statusOptions = computed(() => [
    { value: 'all', label: t('referrals.filter_all_status') },
    { value: 'registered', label: t('referrals.status_registered') },
    { value: 'completed', label: t('referrals.status_completed') },
    { value: 'cancelled', label: t('referrals.status_cancelled') },
]);

const typeOptions = computed(() => [
    { value: 'all', label: t('referrals.filter_all_referrers') },
    { value: 'user', label: t('referral_codes.type_user') },
    { value: 'provider', label: t('referral_codes.type_provider') },
]);

const referredOptions = computed(() => [
    { value: 'all', label: t('referrals.filter_all_referred') },
    { value: 'user', label: t('referral_codes.type_user') },
    { value: 'provider', label: t('referral_codes.type_provider') },
]);

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        year: 'numeric', month: 'short', day: 'numeric',
    });
}

onMounted(() => fetchReferrals(1));
</script>
