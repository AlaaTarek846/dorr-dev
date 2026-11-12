<template>
    <div>
        <WalletPageHeader :title="t('moving.cases.title')" :section="t('sidebar.moving')" :total="pagination?.total ?? null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="btn-group btn-group-sm" role="group">
                    <button v-for="s in ['open', 'decided']" :key="s" type="button" class="btn" :class="filters.status === s ? 'btn-primary' : 'btn-outline-primary'" @click="filters.status = s">
                        {{ t(`moving.cases.status.${s}`) }}
                    </button>
                </div>
                <Select
                    v-model="filters.kind"
                    :options="kindOptions"
                    option-label="label"
                    option-value="value"
                    :placeholder="t('moving.cases.all_kinds')"
                    show-clear
                    class="wallet-filter-select ms-auto"
                />
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('moving.cases.kind_label') }}</th>
                                <th>{{ t('moving.cases.reason') }}</th>
                                <th>{{ t('moving.cases.claim') }}</th>
                                <th>{{ t('moving.cases.held') }}</th>
                                <th>{{ t('moving.cases.opened_by') }}</th>
                                <th>{{ t('moving.cases.opened_at') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="6" :layout="['badge', 'text', 'text', 'text', 'text', 'text']" />
                            <tr v-else-if="!rows.length">
                                <td colspan="6" class="text-center text-muted py-5"><i class="ri-scales-3-line fs-24 d-block mb-1"></i>{{ t('wallet.common.empty') }}</td>
                            </tr>
                            <tr v-for="row in rows" v-else :key="row.uuid" role="button" @click="open(row)">
                                <td class="ps-4"><span class="badge" :class="row.kind === 'report' ? 'bg-danger-transparent' : 'bg-warning-transparent'">{{ t(`moving.cases.kind.${row.kind}`) }}</span></td>
                                <td>{{ t(`moving.cases.reasons.${row.reason_code}`) }}</td>
                                <td>{{ money(row.claim_minor, row.request?.currency_code) }}</td>
                                <td>{{ money(row.held_minor, row.request?.currency_code) }}</td>
                                <td>{{ t(`moving.cases.side.${row.opened_by}`) }}</td>
                                <td class="fs-12">{{ formatDateTime(row.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" @change="fetch" />
        </div>

        <WalletModal :show="!!detail" :title="detail ? t(`moving.cases.kind.${detail.case.kind}`) : ''" size="xl" smooth @close="detail = null">
            <template v-if="detail">
                <div class="row g-3">
                    <div class="col-lg-5">
                        <div class="border rounded-3 p-3 mb-3">
                            <div class="fw-semibold mb-1">{{ t(`moving.cases.reasons.${detail.case.reason_code}`) }}</div>
                            <div class="fs-13 text-muted mb-2">{{ detail.case.details || '—' }}</div>
                            <div class="fs-13">
                                {{ t('moving.cases.claim') }}: <b>{{ money(detail.case.claim_minor, currencyCode) }}</b>
                                · {{ t('moving.cases.held') }}: <b>{{ money(detail.case.held_minor, currencyCode) }}</b>
                                · {{ t('moving.cases.total') }}: <b>{{ money(detail.request.money?.total_minor, currencyCode) }}</b>
                            </div>
                            <div v-if="detail.case.evidence.length" class="d-flex flex-wrap gap-2 mt-2">
                                <a v-for="url in detail.case.evidence" :key="url" :href="url" target="_blank" rel="noopener noreferrer"><img :src="url" alt="" class="rounded border" style="width: 72px; height: 56px; object-fit: cover"></a>
                            </div>
                        </div>
                        <div class="border rounded-3 p-3 fs-13">
                            <div><b>{{ detail.request.establishment?.name || '—' }}</b> · {{ detail.request.pickup.area || '—' }} {{ arrow }} {{ detail.request.dropoff.area || '—' }}</div>
                            <div class="text-muted">{{ detail.request.driver?.name || '—' }} · <span dir="ltr">{{ [detail.request.vehicle?.plate_letters, detail.request.vehicle?.plate_number].filter(Boolean).join(' ') }}</span></div>
                        </div>
                    </div>
                    <div class="col-lg-7">
                        <h6 class="fs-13 fw-semibold">{{ t('moving.cases.inventory') }}</h6>
                        <div v-if="!detail.inventory.length" class="text-muted fs-12 mb-3">{{ t('wallet.common.empty') }}</div>
                        <div v-for="item in detail.inventory" :key="item.uuid" class="d-flex align-items-center gap-2 border-top py-2 fs-13">
                            <span class="badge bg-light text-dark">#{{ item.number }}</span>
                            <span class="flex-grow-1">{{ item.label }} <span v-if="item.delivered_note" class="text-danger fs-12">· {{ item.delivered_note }}</span></span>
                            <a v-if="item.before" :href="item.before" target="_blank" rel="noopener noreferrer"><img :src="item.before" alt="" class="rounded border" style="width: 56px; height: 44px; object-fit: cover"></a>
                            <a v-if="item.after" :href="item.after" target="_blank" rel="noopener noreferrer"><img :src="item.after" alt="" class="rounded border" style="width: 56px; height: 44px; object-fit: cover"></a>
                        </div>
                        <h6 class="fs-13 fw-semibold mt-3">{{ t('moving.cases.timeline') }}</h6>
                        <div v-if="!detail.events?.length" class="text-muted fs-12">{{ t('wallet.common.empty') }}</div>
                        <ul v-else class="list-unstyled fs-12 mb-0" style="max-height: 200px; overflow-y: auto">
                            <li v-for="(e, i) in detail.events" :key="i" class="border-start ps-2 mb-1">
                                <b>{{ eventLabel(e.action) }}</b> <span class="text-muted">· {{ formatDateTime(e.created_at) }}</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <form v-if="canDecide" id="moving-case-form" class="border-top mt-3 pt-3" @submit.prevent="decide">
                    <div class="row g-3">
                        <div v-if="detail.case.kind === 'report'" class="col-md-4">
                            <label class="form-label" for="moving-case-award">{{ t('moving.cases.award') }} <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input
                                    id="moving-case-award"
                                    v-model="form.award"
                                    type="text"
                                    inputmode="decimal"
                                    dir="ltr"
                                    class="form-control"
                                    :class="{ 'is-invalid': errorOf('award_minor') }"
                                    placeholder="0.00"
                                    @input="clearError('award_minor')"
                                >
                                <span class="input-group-text">{{ currencyCode }}</span>
                            </div>
                            <div v-if="errorOf('award_minor')" class="invalid-feedback d-block">{{ errorOf('award_minor') }}</div>
                            <div v-else class="form-text">{{ t('moving.cases.award_hint') }} ({{ t('moving.cases.held') }}: {{ money(detail.case.held_minor, currencyCode) }})</div>
                        </div>
                        <div v-else class="col-md-4">
                            <label class="form-label" for="moving-case-outcome">{{ t('moving.cases.outcome') }} <span class="text-danger">*</span></label>
                            <select id="moving-case-outcome" v-model="form.outcome" class="form-select" :class="{ 'is-invalid': errorOf('outcome') }" @change="clearError('outcome')">
                                <option v-for="o in ['refund_all', 'fee', 'continue']" :key="o" :value="o">{{ t(`moving.cases.outcomes.${o}`) }}</option>
                            </select>
                            <div v-if="errorOf('outcome')" class="invalid-feedback d-block">{{ errorOf('outcome') }}</div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="moving-case-note">{{ t('moving.cases.note') }} <span class="text-danger">*</span></label>
                            <textarea id="moving-case-note" v-model="form.note" rows="2" maxlength="1000" class="form-control" :class="{ 'is-invalid': errorOf('note') }" required @input="clearError('note')"></textarea>
                            <div v-if="errorOf('note')" class="invalid-feedback d-block">{{ errorOf('note') }}</div>
                        </div>
                    </div>
                </form>
                <div v-else-if="detail.case.status === 'decided'" class="alert alert-light border mt-3 mb-0 fs-13">
                    {{ t(`moving.cases.decision.${detail.case.decision}`) }} · {{ detail.case.decision_note }}
                </div>
            </template>
            <template #footer>
                <button type="button" class="btn btn-light" @click="detail = null">{{ canDecide ? t('cancel') : t('close') }}</button>
                <button v-if="canDecide" type="submit" form="moving-case-form" class="btn btn-primary" :disabled="busy">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('moving.cases.decide') }}
                </button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import Select from 'primevue/select';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import useWalletList from '../../../../../../../composables/useWalletList';
import { usePermission } from '../../../../../../../composables/usePermission';
import { fmtMinor, formatDateTime as walletDateTime, parseMajor } from '../../../../../../../utils/walletMoney';

// Moving cases (docs/moving_tasks.md B-14, B-17): customers' reports and the exceptions either side asked for.
// A person decides each one with the whole story: the inventory photos before and after, the evidence, the steps.
const { t, te, locale } = useI18n();
const { can } = usePermission();
const { showError, showSuccess } = useToast();

const { rows, loading, pagination, filters, fetch } = useWalletList('moving-cases', { defaults: { status: 'open', kind: '' }, perPage: 20 });
const kindOptions = computed(() => ['report', 'exception'].map((k) => ({ value: k, label: t(`moving.cases.kind.${k}`) })));
const busy = ref(false);
const detail = ref(null);
// The award is typed in the currency (12.50) and sent in minor units (award_minor), like the wallet screens.
const form = reactive({ award: '', outcome: 'refund_all', note: '' });
const errors = reactive({});

const canDecide = computed(() => detail.value?.case.status === 'open' && can('moving-cases.update'));
const currencyCode = computed(() => detail.value?.request.currency_code ?? '');
const arrow = computed(() => (locale.value === 'ar' ? '←' : '→'));
const money = (minor, currency) => (minor === null || minor === undefined ? '—' : fmtMinor(minor, currency ?? ''));
const formatDateTime = (iso) => (iso ? walletDateTime(iso, locale.value) : '—');
const eventLabel = (action) => (te(`moving.requests.events.${action}`) ? t(`moving.requests.events.${action}`) : action);
const errorOf = (key) => errors[key]?.[0] ?? null;
const clearError = (key) => delete errors[key];
const clearErrors = () => Object.keys(errors).forEach((key) => delete errors[key]);

async function open(row) {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/moving-cases/${row.uuid}`);
        detail.value = data.data;
        Object.assign(form, { award: '', outcome: 'refund_all', note: '' });
        clearErrors();
    } catch (e) {
        showError(extractApiErrorMessage(e));
    }
}

async function decide() {
    clearErrors();
    const isReport = detail.value.case.kind === 'report';
    let awardMinor = null;
    if (isReport) {
        awardMinor = parseMajor(form.award) ?? 0;
        if (Number.isNaN(awardMinor)) {
            errors.award_minor = [t('wallet.common.invalid_amount')];
            return;
        }
        if (awardMinor > (detail.value.case.held_minor ?? 0)) {
            errors.award_minor = [t('moving.cases.award_over_held')];
            return;
        }
    }
    busy.value = true;
    try {
        const body = isReport ? { award_minor: awardMinor, note: form.note } : { outcome: form.outcome, note: form.note };
        await adminAxios.post(`/api/admin/v1/moving-cases/${detail.value.case.uuid}/decide`, body);
        showSuccess(t('moving.common.saved'));
        detail.value = null;
        fetch(pagination.value?.current_page ?? 1);
    } catch (e) {
        const bag = e?.response?.data?.errors ?? {};
        Object.assign(errors, bag);
        if (!Object.keys(bag).length) {
            showError(extractApiErrorMessage(e));
        }
    } finally {
        busy.value = false;
    }
}

onMounted(() => fetch(1));
</script>
