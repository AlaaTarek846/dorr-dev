<template>
    <div>
        <WalletPageHeader :title="t('wallet.wallets.title')" :total="pagination?.total ?? null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2 catalog-toolbar-filters">
                <div class="input-group input-group-sm catalog-toolbar-search">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="filters.search" type="search" class="form-control" :placeholder="t('wallet.wallets.search')">
                    <button
                        v-if="filters.search"
                        type="button"
                        class="btn btn-light border"
                        :title="t('wallet.common.clear_search')"
                        @click="filters.search = ''"
                    >
                        <i class="ri-close-line"></i>
                    </button>
                </div>
                <Select
                    filter
                    :filter-placeholder="t('search_placeholder')"
                    v-model="filters.owner_type"
                    :options="ownerFilterOptions"
                    option-label="label"
                    option-value="value"
                    class="wallet-filter-select"
                />
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('wallet.common.owner') }}</th>
                                <th>{{ t('wallet.wallets.number') }}</th>
                                <th>{{ t('wallet.wallets.country') }}</th>
                                <th>{{ t('wallet.wallets.total') }}</th>
                                <th>{{ t('wallet.bucket.withdrawable') }}</th>
                                <th>{{ t('wallet.bucket.spend_only') }}</th>
                                <th>{{ t('wallet.wallets.held') }}</th>
                                <th class="text-end pe-4">{{ t('wallet.common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="8" :columns="8" />
                            <tr v-else-if="!rows.length">
                                <td colspan="8" class="border-0">
                                    <div class="text-center py-5">
                                        <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                            <i class="ri-wallet-3-line fs-2 text-primary"></i>
                                        </span>
                                        <p class="fw-semibold mb-1">{{ t('wallet.wallets.empty_title') }}</p>
                                        <p class="text-muted mb-0">{{ t('wallet.common.empty') }}</p>
                                    </div>
                                </td>
                            </tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id" class="crm-contact" @click="open(row)">
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="avatar avatar-sm avatar-rounded bg-primary-transparent">
                                                <i :class="row.owner_type === 'provider' ? 'ri-store-2-line' : 'ri-user-line'" class="text-primary"></i>
                                            </span>
                                            <div>
                                                <button type="button" class="btn btn-link p-0 text-start fw-semibold text-default" @click.stop="open(row)">
                                                    {{ row.owner?.name || `#${row.owner_id}` }}
                                                </button>
                                                <span class="d-block text-muted fs-11">
                                                    #{{ row.id }}
                                                    <span class="badge bg-secondary-transparent ms-1">{{ t(`wallet.owner.${row.owner_type}`) }}</span>
                                                    <span v-if="row.owner?.phone || row.owner?.email" class="ms-1" dir="ltr">{{ row.owner?.phone || row.owner?.email }}</span>
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="badge bg-secondary-transparent font-monospace" dir="ltr">{{ formatWalletNumber(row.wallet_number) }}</span></td>
                                    <td><span class="badge bg-primary-transparent">{{ row.country_code }}</span></td>
                                    <td class="fw-semibold">{{ fmtMinor(row.total_minor, row.currency_code) }}</td>
                                    <td>{{ fmtMinor(row.withdrawable_minor) }}</td>
                                    <td>{{ fmtMinor(row.spend_only_minor) }}</td>
                                    <td>{{ fmtMinor(row.held_withdrawable_minor + row.held_spend_only_minor) }}</td>
                                    <td class="text-end pe-4">
                                        <div class="btn-list justify-content-end">
                                            <button type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('wallet.wallets.statement')" @click.stop="open(row)">
                                                <i class="ri-eye-line"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" @change="fetch" />
        </div>

        <WalletModal :show="showModal" :title="wallet ? `${wallet.owner?.name || '#' + wallet.owner_id} · ${wallet.country_code}` : ''" size="xl" @close="showModal = false">
            <div v-if="wallet" class="d-flex flex-column gap-3">
                <WalletDetailHero
                    icon="ri-wallet-3-line"
                    :label="t('wallet.wallets.total')"
                    :value="fmtMinor(wallet.total_minor, wallet.currency_code)"
                    :subtitle="formatWalletNumber(wallet.wallet_number)"
                >
                    <span class="badge bg-secondary-transparent fs-12">{{ t(`wallet.owner.${wallet.owner_type}`) }}</span>
                    <span class="badge bg-primary-transparent fs-12">{{ wallet.country_code }}</span>
                </WalletDetailHero>

                <div class="row g-3">
                    <div class="col-md-6">
                        <WalletInfoTile icon="ri-user-line" :label="t('wallet.common.owner')">
                            {{ wallet.owner?.name || `#${wallet.owner_id}` }}
                            <div v-if="wallet.owner?.phone || wallet.owner?.email" class="fs-12 fw-normal text-muted" dir="ltr">{{ wallet.owner?.phone || wallet.owner?.email }}</div>
                        </WalletInfoTile>
                    </div>
                    <div class="col-md-6">
                        <WalletInfoTile icon="ri-hashtag" :label="t('wallet.wallets.number')">
                            <span class="font-monospace" dir="ltr">{{ formatWalletNumber(wallet.wallet_number) }}</span>
                        </WalletInfoTile>
                    </div>
                    <div class="col-md-4">
                        <WalletInfoTile icon="ri-bank-card-line" :label="t('wallet.bucket.withdrawable')">
                            <span dir="ltr">{{ fmtMinor(wallet.withdrawable_minor) }}</span>
                        </WalletInfoTile>
                    </div>
                    <div class="col-md-4">
                        <WalletInfoTile icon="ri-shopping-bag-3-line" :label="t('wallet.bucket.spend_only')">
                            <span dir="ltr">{{ fmtMinor(wallet.spend_only_minor) }}</span>
                        </WalletInfoTile>
                    </div>
                    <div class="col-md-4">
                        <WalletInfoTile icon="ri-lock-line" :label="t('wallet.wallets.held')">
                            <span dir="ltr">{{ fmtMinor(wallet.held_withdrawable_minor + wallet.held_spend_only_minor) }}</span>
                        </WalletInfoTile>
                    </div>
                </div>

                <ul class="nav nav-tabs mb-0">
                    <li class="nav-item">
                        <button type="button" class="nav-link" :class="{ active: tab === 'statement' }" @click="tab = 'statement'">
                            <i class="ri-file-list-3-line me-1 align-middle"></i>{{ t('wallet.wallets.statement') }}
                        </button>
                    </li>
                    <li v-if="canAdjust" class="nav-item">
                        <button type="button" class="nav-link" :class="{ active: tab === 'adjust' }" @click="tab = 'adjust'">
                            <i class="ri-edit-box-line me-1 align-middle"></i>{{ t('wallet.wallets.adjust') }}
                        </button>
                    </li>
                </ul>

                <div v-if="tab === 'statement'" class="d-flex flex-column gap-3">
                    <div class="d-flex flex-wrap gap-2">
                        <Select
                            v-model="txFilters.bucket"
                            filter
                            :filter-placeholder="t('search_placeholder')"
                            :options="bucketFilterOptions"
                            option-label="label"
                            option-value="value"
                            append-to="self"
                            class="wallet-filter-select"
                            @change="loadTx(1)"
                        />
                        <Select
                            v-model="txFilters.direction"
                            filter
                            :filter-placeholder="t('search_placeholder')"
                            :options="directionFilterOptions"
                            option-label="label"
                            option-value="value"
                            append-to="self"
                            class="wallet-filter-select"
                            @change="loadTx(1)"
                        />
                    </div>

                    <WalletSection :title="t('wallet.wallets.statement')" icon="ri-file-list-3-line" flush>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover text-nowrap mb-0">
                                <thead>
                                    <tr>
                                        <th class="ps-3">{{ t('wallet.common.date') }}</th>
                                        <th>{{ t('wallet.common.type') }}</th>
                                        <th>{{ t('wallet.common.bucket') }}</th>
                                        <th>{{ t('wallet.common.amount') }}</th>
                                        <th>{{ t('wallet.wallets.balance_after') }}</th>
                                        <th class="pe-3">{{ t('wallet.common.note') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="txLoading"><td colspan="6" class="text-center py-4"><span class="spinner-border spinner-border-sm"></span></td></tr>
                                    <tr v-else-if="!txRows.length">
                                        <td colspan="6" class="border-0">
                                            <div class="text-center py-4">
                                                <span class="avatar avatar-lg avatar-rounded bg-primary-transparent mb-2">
                                                    <i class="ri-file-list-3-line fs-4 text-primary"></i>
                                                </span>
                                                <p class="text-muted mb-0">{{ t('wallet.common.empty') }}</p>
                                            </div>
                                        </td>
                                    </tr>
                                    <template v-else>
                                        <tr v-for="tx in txRows" :key="tx.uuid">
                                            <td class="ps-3">{{ formatDateTime(tx.created_at, locale) }}</td>
                                            <td>{{ tx.type_label }}</td>
                                            <td><span class="badge" :class="tx.bucket === 'spend_only' ? 'bg-pink-transparent' : 'bg-primary-transparent'">{{ t(`wallet.bucket.${tx.bucket}`) }}</span></td>
                                            <td :class="tx.direction === 'credit' ? 'text-success' : 'text-danger'" class="fw-semibold" dir="ltr">
                                                {{ tx.direction === 'credit' ? '+' : '−' }} {{ fmtMinor(tx.amount_minor) }}
                                            </td>
                                            <td dir="ltr">{{ fmtMinor(tx.balance_after_minor) }}</td>
                                            <td class="text-wrap pe-3">{{ tx.counterparty ? `${tx.counterparty.name || ''} ${tx.counterparty.phone || ''}` : (tx.note !== tx.type_label ? tx.note : '') }}</td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="txPagination && txPagination.last_page > 1" class="d-flex justify-content-between align-items-center border-top px-3 py-2">
                            <button type="button" class="btn btn-sm btn-light" :disabled="txPagination.current_page <= 1" @click="loadTx(txPagination.current_page - 1)">{{ t('currencies.previous') }}</button>
                            <span class="text-muted fs-12">{{ txPagination.current_page }} / {{ txPagination.last_page }}</span>
                            <button type="button" class="btn btn-sm btn-light" :disabled="txPagination.current_page >= txPagination.last_page" @click="loadTx(txPagination.current_page + 1)">{{ t('currencies.next') }}</button>
                        </div>
                    </WalletSection>
                </div>

                <WalletSection v-else :title="t('wallet.wallets.adjust')" icon="ri-edit-box-line" visible>
                    <form @submit.prevent="submitAdjustment">
                        <div class="alert alert-warning fs-13">
                            <i class="ri-alert-line me-1 align-middle"></i>{{ t('wallet.wallets.adjust_hint') }}
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">{{ t('wallet.common.direction') }}</label>
                                <Select
                                    v-model="adjust.direction"
                                    filter
                                    :filter-placeholder="t('search_placeholder')"
                                    :options="directionOptions"
                                    option-label="label"
                                    option-value="value"
                                    append-to="self"
                                    class="w-100"
                                />
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ t('wallet.common.bucket') }} <span class="text-danger">*</span></label>
                                <Select
                                    v-model="adjust.bucket"
                                    filter
                                    :filter-placeholder="t('search_placeholder')"
                                    :options="bucketOptions"
                                    option-label="label"
                                    option-value="value"
                                    :placeholder="t('wallet.wallets.choose_bucket')"
                                    :invalid="invalidOf('bucket')"
                                    append-to="self"
                                    class="w-100"
                                    @change="onInput('bucket')"
                                />
                                <div v-if="messageOf('bucket')" class="invalid-feedback d-block">{{ messageOf('bucket') }}</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ t('wallet.common.amount') }} ({{ wallet.currency_code }}) <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-money-dollar-circle-line"></i></span>
                                    <input v-model="adjust.amount" type="text" inputmode="decimal" class="form-control" :class="classOf('amount')" dir="ltr" placeholder="0.00" @input="onInput('amount')">
                                    <FormFieldFeedback v-bind="feedbackOf('amount')" />
                                </div>
                                <div v-if="messageOf('amount')" class="invalid-feedback d-block">{{ messageOf('amount') }}</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">{{ t('wallet.wallets.reason') }} <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-edit-2-line"></i></span>
                                    <input v-model="adjust.reason" type="text" maxlength="200" class="form-control" :class="classOf('reason')" @input="onInput('reason')">
                                    <FormFieldFeedback v-bind="feedbackOf('reason')" />
                                </div>
                                <div v-if="messageOf('reason')" class="invalid-feedback d-block">{{ messageOf('reason') }}</div>
                            </div>
                        </div>
                        <div v-if="adjustGeneralError" class="text-danger mt-2 fs-13">{{ adjustGeneralError }}</div>
                        <button type="submit" class="btn btn-primary btn-wave mt-3" :disabled="adjusting">
                            <span v-if="adjusting" class="spinner-border spinner-border-sm me-1"></span>{{ t('wallet.wallets.apply_adjustment') }}
                        </button>
                    </form>
                </WalletSection>
            </div>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import useVuelidate from '@vuelidate/core';
import Select from 'primevue/select';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import FormFieldFeedback from '../../../../../../../components/ui/FormFieldFeedback.vue';
import WalletDetailHero from '../../../../../../../components/wallet/WalletDetailHero.vue';
import WalletInfoTile from '../../../../../../../components/wallet/WalletInfoTile.vue';
import WalletSection from '../../../../../../../components/wallet/WalletSection.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useFormFields from '../../../../../../../composables/useFormFields';
import useToast from '../../../../../../../composables/useToast';
import useValidation from '../../../../../../../composables/useValidation';
import useWalletList from '../../../../../../../composables/useWalletList';
import { usePermission } from '../../../../../../../composables/usePermission';
import { fmtMinor, formatDateTime, parseMajor } from '../../../../../../../utils/walletMoney';

const { t, locale } = useI18n();
const { can } = usePermission();
const { showSuccess, showWarning } = useToast();
const { requiredField, minString, maxString, moneyFormat, applyApiErrors } = useValidation();

const { rows, loading, pagination, filters, fetch } = useWalletList('wallets', {
    defaults: { search: '', owner_type: '' },
});
fetch(1);

// 11 digits shown as "123 4567 8901" (same grouping as the app).
const formatWalletNumber = (n) => (n ? String(n).replace(/^(\d{3})(\d{4})(\d+)$/, '$1 $2 $3') : '—');

const canAdjust = computed(() => can('wallets.manual-adjustment'));

const showModal = ref(false);
const wallet = ref(null);
const tab = ref('statement');

const txRows = ref([]);
const txLoading = ref(false);
const txPagination = ref(null);
const txFilters = reactive({ bucket: '', direction: '' });

const adjust = reactive({ direction: 'credit', bucket: '', amount: '', reason: '' });
const adjustServerErrors = reactive({});
const adjustGeneralError = ref('');

const adjustRules = computed(() => ({
    bucket: { required: requiredField('wallet.common.bucket') },
    amount: {
        required: requiredField('wallet.common.amount'),
        money: moneyFormat('wallet.common.amount'),
    },
    reason: {
        required: requiredField('wallet.wallets.reason'),
        minLength: minString('wallet.wallets.reason', 3),
        maxLength: maxString('wallet.wallets.reason', 200),
    },
}));

const v$ = useVuelidate(adjustRules, adjust, { $autoDirty: true });
const { feedbackOf, invalidOf, classOf, messageOf, onInput } = useFormFields({
    getV$: () => v$.value,
    form: adjust,
    serverErrors: adjustServerErrors,
    serverKeys: { amount: 'amount_minor' },
});

function resetAdjust() {
    Object.assign(adjust, { direction: 'credit', bucket: '', amount: '', reason: '' });
    applyApiErrors(adjustServerErrors, {});
    adjustGeneralError.value = '';
    v$.value.$reset();
}

const ownerOptions = computed(() => [
    { value: 'user', label: t('wallet.owner.user') },
    { value: 'provider', label: t('wallet.owner.provider') },
]);
const bucketOptions = computed(() => [
    { value: 'withdrawable', label: t('wallet.bucket.withdrawable') },
    { value: 'spend_only', label: t('wallet.bucket.spend_only') },
]);
const directionOptions = computed(() => [
    { value: 'credit', label: t('wallet.direction.credit') },
    { value: 'debit', label: t('wallet.direction.debit') },
]);
const ownerFilterOptions = computed(() => [{ value: '', label: t('wallet.common.all_owners') }, ...ownerOptions.value]);
const bucketFilterOptions = computed(() => [{ value: '', label: t('wallet.common.all_buckets') }, ...bucketOptions.value]);
const directionFilterOptions = computed(() => [{ value: '', label: t('wallet.common.all_directions') }, ...directionOptions.value]);
const adjusting = ref(false);

function open(row) {
    wallet.value = row;
    tab.value = 'statement';
    showModal.value = true;
    resetAdjust();
    loadTx(1);
}

async function loadTx(page) {
    txLoading.value = true;

    try {
        const params = { per_page: 10, page, ...Object.fromEntries(Object.entries(txFilters).filter(([, v]) => v !== '')) };
        const { data } = await adminAxios.get(`/api/admin/v1/wallets/${wallet.value.id}/transactions`, { params });

        txRows.value = data.data ?? [];
        txPagination.value = data.pagination;
    } finally {
        txLoading.value = false;
    }
}

async function submitAdjustment() {
    adjustGeneralError.value = '';

    if (! (await v$.value.$validate())) {
        showWarning(t('toast.validation_error'));

        return;
    }

    adjusting.value = true;

    try {
        await adminAxios.post(`/api/admin/v1/wallets/${wallet.value.id}/adjustments`, {
            direction: adjust.direction,
            bucket: adjust.bucket,
            amount_minor: parseMajor(adjust.amount),
            reason: adjust.reason.trim(),
        });

        showSuccess(t('wallet.wallets.adjusted'));

        const { data } = await adminAxios.get(`/api/admin/v1/wallets/${wallet.value.id}`);

        wallet.value = data.data;
        resetAdjust();
        tab.value = 'statement';
        loadTx(1);
        fetch();
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};

        applyApiErrors(adjustServerErrors, bag);
        adjustGeneralError.value = Object.keys(bag).length ? '' : (error?.response?.data?.message ?? '');
    } finally {
        adjusting.value = false;
    }
}
</script>

<style scoped>
.wallet-filter-select {
    min-width: 180px;
}

.wallet-filter-select :deep(.p-select-label) {
    padding-block: 0.3rem;
    font-size: 0.8125rem;
}
</style>
