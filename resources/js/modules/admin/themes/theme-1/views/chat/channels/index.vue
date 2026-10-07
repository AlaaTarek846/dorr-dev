<template>
    <div>
        <WalletPageHeader :title="t('chat.channels.title')" :section="t('sidebar.chat')" :total="pagination?.total ?? null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="input-group input-group-sm" style="max-width: 260px;">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="filters.search" type="search" class="form-control" :placeholder="t('chat.channels.search')">
                </div>
                <div class="btn-group btn-group-sm ms-auto" role="group">
                    <button v-for="tab in verifiedTabs" :key="tab.value" type="button" class="btn" :class="filters.verified === tab.value ? 'btn-primary' : 'btn-outline-primary'" @click="filters.verified = tab.value">{{ tab.label }}</button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('chat.channels.channel') }}</th>
                                <th>{{ t('chat.portals.category') }}</th>
                                <th>{{ t('chat.channels.followers') }}</th>
                                <th>{{ t('chat.channels.paid_until') }}</th>
                                <th>{{ t('chat.channels.verified_by_admin') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="5" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="5" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id">
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="avatar avatar-md avatar-rounded bg-light">
                                                <img v-if="row.avatar" :src="row.avatar" alt="">
                                                <i v-else class="ri-broadcast-line text-muted"></i>
                                            </span>
                                            <div>
                                                <div class="fw-semibold">
                                                    {{ row.name }}
                                                    <i v-if="row.is_verified" class="ri-verified-badge-fill text-success ms-1" :title="t('chat.channels.verified')"></i>
                                                </div>
                                                <div class="fs-12 text-muted">
                                                    <span v-if="row.handle" dir="ltr">@{{ row.handle }}</span>
                                                    <span class="badge ms-1" :class="row.is_public ? 'bg-info-transparent' : 'bg-secondary-transparent'">{{ row.is_public ? t('chat.channels.public') : t('chat.channels.private') }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>{{ row.category || '—' }}</td>
                                    <td><span class="badge bg-primary-transparent"><i class="ri-user-follow-line me-1"></i>{{ row.followers_count }}</span></td>
                                    <td>
                                        <span v-if="row.verified_until && new Date(row.verified_until) > new Date()" class="badge bg-success-transparent">{{ formatDate(row.verified_until) }}</span>
                                        <span v-else class="text-muted">—</span>
                                    </td>
                                    <td>
                                        <div v-if="canUpdate" class="toggle toggle-success mb-0" :class="{ on: row.verified_by_admin }" role="button" @click="toggleVerified(row)"><span></span></div>
                                        <span v-else class="badge" :class="row.verified_by_admin ? 'bg-success-transparent' : 'bg-secondary-transparent'">{{ row.verified_by_admin ? t('wallet.common.active') : t('wallet.common.inactive') }}</span>
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

const canUpdate = computed(() => can('chat-channels.update'));
const { rows, loading, pagination, filters, fetch } = useWalletList('chat-channels', { defaults: { search: '', verified: '' } });
const verifiedTabs = computed(() => [
    { value: '', label: t('chat.portals.all') },
    { value: 1, label: t('chat.channels.verified') },
    { value: 0, label: t('chat.channels.not_verified') },
]);

const formatDate = (iso) => (iso ? new Date(iso).toLocaleDateString(locale.value) : '—');

/** The admin's own ✔ — a paid verification keeps running regardless. */
async function toggleVerified(row) {
    try {
        const { data } = await adminAxios.patch(`/api/admin/v1/chat-channels/${row.id}/verify`, { verified: !row.verified_by_admin });
        row.verified_by_admin = data.data?.verified_by_admin ?? !row.verified_by_admin;
        row.is_verified = data.data?.is_verified ?? row.is_verified;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

onMounted(() => fetch(1));
</script>
