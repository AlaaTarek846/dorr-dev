<template>
    <div>
        <WalletPageHeader :title="t('chat.portals.title')" :section="t('sidebar.chat')" :total="pagination?.total ?? null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="input-group input-group-sm" style="max-width: 260px;">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="filters.search" type="search" class="form-control" :placeholder="t('chat.portals.search')">
                </div>
                <div class="btn-group btn-group-sm ms-auto" role="group">
                    <button v-for="tab in listedTabs" :key="tab.value" type="button" class="btn" :class="filters.listed === tab.value ? 'btn-primary' : 'btn-outline-primary'" @click="filters.listed = tab.value">{{ tab.label }}</button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('chat.portals.portal') }}</th>
                                <th>{{ t('chat.portals.owner') }}</th>
                                <th>{{ t('chat.portals.category') }}</th>
                                <th>{{ t('chat.portals.views') }}</th>
                                <th>{{ t('chat.portals.listed_until') }}</th>
                                <th>{{ t('chat.portals.enabled') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="6" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id">
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="avatar avatar-md avatar-rounded bg-light border">
                                                <img v-if="row.logo" :src="row.logo" alt="" style="object-fit: cover;">
                                                <i v-else class="ri-store-2-line text-muted"></i>
                                            </span>
                                            <div>
                                                <div class="fw-semibold">{{ row.name }}</div>
                                                <a :href="row.website_url" target="_blank" rel="noopener" class="fs-12 text-muted">{{ row.website_url }}</a>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div>{{ row.owner || '—' }}</div>
                                        <div class="fs-12 text-muted" dir="ltr">{{ row.owner_phone }}</div>
                                    </td>
                                    <td>{{ row.category || '—' }}</td>
                                    <td><span class="badge bg-primary-transparent"><i class="ri-eye-line me-1"></i>{{ row.views_count }}</span></td>
                                    <td>
                                        <span v-if="row.is_listed" class="badge bg-success-transparent">{{ formatDate(row.listed_until) }}</span>
                                        <span v-else class="badge bg-secondary-transparent">{{ t('chat.portals.not_listed') }}</span>
                                    </td>
                                    <td>
                                        <div v-if="canUpdate" class="toggle toggle-success mb-0" :class="{ on: row.status }" role="button" @click="toggleStatus(row)"><span></span></div>
                                        <span v-else class="badge" :class="row.status ? 'bg-success-transparent' : 'bg-danger-transparent'">{{ row.status ? t('wallet.common.active') : t('wallet.common.inactive') }}</span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" @change="fetch" />
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import useWalletList from '../../../../../../../composables/useWalletList';
import { usePermission } from '../../../../../../../composables/usePermission';

const { t, locale } = useI18n();
const { can } = usePermission();
const { showError } = useToast();

const canUpdate = computed(() => can('chat-portals.update'));
const { rows, loading, pagination, filters, fetch } = useWalletList('chat-portals', { defaults: { search: '', listed: '' } });
const listedTabs = computed(() => [
    { value: '', label: t('chat.portals.all') },
    { value: 1, label: t('chat.portals.listed') },
    { value: 0, label: t('chat.portals.not_listed') },
]);

const formatDate = (iso) => (iso ? new Date(iso).toLocaleDateString(locale.value) : '—');

/** Switching a portal off takes it off the page at once (its paid time keeps running). */
async function toggleStatus(row) {
    try {
        await adminAxios.patch(`/api/admin/v1/chat-portals/${row.id}/status`, { status: !row.status });
        row.status = !row.status;
        row.is_listed = row.status && row.listed_until && new Date(row.listed_until) > new Date();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

onMounted(() => fetch(1));
</script>
