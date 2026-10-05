<template>
    <div>
        <WalletPageHeader :title="t('wallet.settings.title')" :total="rows.length || null" />

        <div class="alert alert-info fs-13">{{ t('wallet.settings.intro') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2 catalog-toolbar-filters">
                    <div class="input-group input-group-sm catalog-toolbar-search">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="search" type="search" class="form-control" :placeholder="t('wallet.settings.search')">
                        <button
                            v-if="search"
                            type="button"
                            class="btn btn-light border"
                            :title="t('wallet.common.clear_search')"
                            @click="search = ''"
                        >
                            <i class="ri-close-line"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('wallet.wallets.country') }}</th>
                                <th>{{ t('wallet.settings.withdrawal_range') }}</th>
                                <th>{{ t('wallet.settings.transfers') }}</th>
                                <th>{{ t('wallet.settings.provider_debt') }}</th>
                                <th>{{ t('wallet.settings.user_debt') }}</th>
                                <th class="text-end pe-4">{{ t('wallet.common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="8" :columns="6" />
                            <tr v-else-if="!visibleRows.length">
                                <td colspan="6" class="border-0">
                                    <div class="text-center py-5">
                                        <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                            <i class="ri-settings-3-line fs-2 text-primary"></i>
                                        </span>
                                        <p class="fw-semibold mb-1">{{ t('wallet.common.empty_title') }}</p>
                                        <p class="text-muted mb-0">{{ t('wallet.common.empty') }}</p>
                                    </div>
                                </td>
                            </tr>
                            <template v-else>
                                <tr v-for="row in visibleRows" :key="row.country_id" class="crm-contact">
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="avatar avatar-sm avatar-rounded bg-primary-transparent">
                                                <i class="ri-global-line text-primary"></i>
                                            </span>
                                            <button v-if="canUpdate" type="button" class="btn btn-link p-0 text-start fw-semibold text-default" @click="edit(row)">{{ row.country_code }}</button>
                                            <span v-else class="fw-semibold text-default">{{ row.country_code }}</span>
                                        </div>
                                    </td>
                                    <td>{{ range(row.min_withdrawal_minor, row.max_withdrawal_minor) }}</td>
                                    <td>
                                        <span class="badge" :class="row.transfers_enabled ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                                            {{ row.transfers_enabled ? t('wallet.settings.enabled') : t('wallet.settings.disabled') }}
                                        </span>
                                        <span v-if="Number(row.transfer_fee_percent) > 0" class="fs-11 text-muted d-block mt-1">
                                            {{ t('wallet.settings.fee_summary', { percent: row.transfer_fee_percent, payer: t(`wallet.settings.fee_payer_${row.transfer_fee_payer}`) }) }}
                                        </span>
                                    </td>
                                    <td>{{ debt(row.min_allowed_balance_provider_minor) }}</td>
                                    <td>{{ debt(row.min_allowed_balance_user_minor) }}</td>
                                    <td class="text-end pe-4">
                                        <div class="btn-list justify-content-end">
                                            <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('wallet.settings.edit')" @click="edit(row)"><i class="ri-pencil-line"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <ModalCreateAndUpdate
            :show="modalShow"
            :record="selectedRecord"
            @close="modalShow = false"
            @saved="onSaved"
        />
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import { usePermission } from '../../../../../../../composables/usePermission';
import { fmtMinor } from '../../../../../../../utils/walletMoney';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

const { t } = useI18n();
const { can } = usePermission();

const canUpdate = computed(() => can('wallet-settings.update'));

const rows = ref([]);
const search = ref('');
const loading = ref(false);

const modalShow = ref(false);
const selectedRecord = ref(null);

/** 200+ countries: filter by code so the admin isn't scrolling for one row. */
const visibleRows = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return needle ? rows.value.filter((row) => (row.country_code || '').toLowerCase().includes(needle)) : rows.value;
});

function range(min, max) {
    if (min == null && max == null) {
        return t('wallet.settings.no_limit');
    }

    return `${min == null ? '…' : fmtMinor(min)} – ${max == null ? '…' : fmtMinor(max)}`;
}

/** Stored as a negative minor amount; shown as a positive "debt allowed". */
function debt(minor) {
    return minor ? fmtMinor(Math.abs(minor)) : t('wallet.settings.no_debt');
}

async function load() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/wallet-settings');

        rows.value = data.data ?? [];
    } finally {
        loading.value = false;
    }
}

function edit(row) {
    selectedRecord.value = { ...row };
    modalShow.value = true;
}

function onSaved() {
    modalShow.value = false;
    load();
}

onMounted(load);
</script>
