<template>
    <div>
        <WalletPageHeader :title="t('moving.cases.title')" :section="t('sidebar.moving')" :total="pagination?.total ?? null" />

        <div class="card custom-card">
            <div class="card-header d-flex flex-wrap gap-2 align-items-center py-3">
                <ul class="nav nav-tabs border-0">
                    <li v-for="s in ['open', 'decided']" :key="s" class="nav-item">
                        <a href="#" class="nav-link" :class="{ active: filters.status === s }" @click.prevent="filters.status = s; load(1)">{{ t(`moving.cases.status.${s}`) }}</a>
                    </li>
                </ul>
                <select v-model="filters.kind" class="form-select form-select-sm ms-auto" style="max-width: 180px" @change="load(1)">
                    <option value="">{{ t('moving.cases.all_kinds') }}</option>
                    <option value="report">{{ t('moving.cases.kind.report') }}</option>
                    <option value="exception">{{ t('moving.cases.kind.exception') }}</option>
                </select>
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
                            <tr v-if="loading"><td colspan="6" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="row in rows" v-else :key="row.uuid" role="button" @click="open(row)">
                                <td class="ps-4"><span class="badge" :class="row.kind === 'report' ? 'bg-danger-transparent' : 'bg-warning-transparent'">{{ t(`moving.cases.kind.${row.kind}`) }}</span></td>
                                <td>{{ t(`moving.cases.reasons.${row.reason_code}`) }}</td>
                                <td>{{ money(row.claim_minor, row.request?.currency_code) }}</td>
                                <td>{{ money(row.held_minor, row.request?.currency_code) }}</td>
                                <td>{{ t(`moving.cases.side.${row.opened_by}`) }}</td>
                                <td class="fs-12">{{ new Date(row.created_at).toLocaleString() }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="pagination && pagination.last_page > 1" class="card-footer d-flex justify-content-between align-items-center">
                <button type="button" class="btn btn-sm btn-light" :disabled="pagination.current_page <= 1" @click="load(pagination.current_page - 1)"><i class="ri-arrow-right-s-line"></i></button>
                <span class="fs-13">{{ pagination.current_page }} / {{ pagination.last_page }}</span>
                <button type="button" class="btn btn-sm btn-light" :disabled="pagination.current_page >= pagination.last_page" @click="load(pagination.current_page + 1)"><i class="ri-arrow-left-s-line"></i></button>
            </div>
        </div>

        <WalletModal :show="!!detail" :title="detail ? t(`moving.cases.kind.${detail.case.kind}`) : ''" size="xl" @close="detail = null">
            <template v-if="detail">
                <div class="row g-3">
                    <div class="col-lg-5">
                        <div class="border rounded-3 p-3 mb-3">
                            <div class="fw-semibold mb-1">{{ t(`moving.cases.reasons.${detail.case.reason_code}`) }}</div>
                            <div class="fs-13 text-muted mb-2">{{ detail.case.details || '—' }}</div>
                            <div class="fs-13">
                                {{ t('moving.cases.claim') }}: <b>{{ money(detail.case.claim_minor, detail.request.currency_code) }}</b>
                                · {{ t('moving.cases.held') }}: <b>{{ money(detail.case.held_minor, detail.request.currency_code) }}</b>
                                · {{ t('moving.cases.total') }}: <b>{{ money(detail.request.money?.total_minor, detail.request.currency_code) }}</b>
                            </div>
                            <div v-if="detail.case.evidence.length" class="d-flex flex-wrap gap-2 mt-2">
                                <a v-for="url in detail.case.evidence" :key="url" :href="url" target="_blank"><img :src="url" alt="" class="rounded border" style="width: 72px; height: 56px; object-fit: cover"></a>
                            </div>
                        </div>
                        <div class="border rounded-3 p-3 fs-13">
                            <div><b>{{ detail.request.establishment?.name }}</b> · {{ detail.request.pickup.area }} ← {{ detail.request.dropoff.area }}</div>
                            <div class="text-muted">{{ detail.request.driver?.name || '—' }} · <span dir="ltr">{{ [detail.request.vehicle?.plate_letters, detail.request.vehicle?.plate_number].filter(Boolean).join(' ') }}</span></div>
                        </div>
                    </div>
                    <div class="col-lg-7">
                        <h6 class="fs-13 fw-semibold">{{ t('moving.cases.inventory') }}</h6>
                        <div v-if="!detail.inventory.length" class="text-muted fs-12 mb-3">—</div>
                        <div v-for="item in detail.inventory" :key="item.uuid" class="d-flex align-items-center gap-2 border-top py-2 fs-13">
                            <span class="badge bg-light text-dark">#{{ item.number }}</span>
                            <span class="flex-grow-1">{{ item.label }} <span v-if="item.delivered_note" class="text-danger fs-12">· {{ item.delivered_note }}</span></span>
                            <a v-if="item.before" :href="item.before" target="_blank"><img :src="item.before" alt="" class="rounded border" style="width: 56px; height: 44px; object-fit: cover"></a>
                            <a v-if="item.after" :href="item.after" target="_blank"><img :src="item.after" alt="" class="rounded border" style="width: 56px; height: 44px; object-fit: cover"></a>
                        </div>
                        <h6 class="fs-13 fw-semibold mt-3">{{ t('moving.cases.timeline') }}</h6>
                        <ul class="list-unstyled fs-12 mb-0" style="max-height: 200px; overflow-y: auto">
                            <li v-for="(e, i) in detail.events" :key="i" class="border-start ps-2 mb-1">
                                <b>{{ e.action }}</b> <span class="text-muted">· {{ new Date(e.created_at).toLocaleString() }}</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <form v-if="detail.case.status === 'open' && can('moving-cases.update')" id="moving-case-form" class="border-top mt-3 pt-3" @submit.prevent="decide">
                    <div class="row g-3">
                        <div v-if="detail.case.kind === 'report'" class="col-md-4">
                            <label class="form-label">{{ t('moving.cases.award') }}</label>
                            <input v-model.number="form.award_minor" type="number" min="0" :max="detail.case.held_minor" class="form-control">
                            <div class="form-text">{{ t('moving.cases.award_hint') }}</div>
                        </div>
                        <div v-else class="col-md-4">
                            <label class="form-label">{{ t('moving.cases.outcome') }}</label>
                            <select v-model="form.outcome" class="form-select">
                                <option v-for="o in ['refund_all', 'fee', 'continue']" :key="o" :value="o">{{ t(`moving.cases.outcomes.${o}`) }}</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">{{ t('moving.cases.note') }} *</label>
                            <textarea v-model="form.note" rows="2" class="form-control" required></textarea>
                        </div>
                    </div>
                </form>
                <div v-else-if="detail.case.status === 'decided'" class="alert alert-light border mt-3 mb-0 fs-13">
                    {{ t(`moving.cases.decision.${detail.case.decision}`) }} · {{ detail.case.decision_note }}
                </div>
            </template>
            <template #footer>
                <button type="button" class="btn btn-light" @click="detail = null">{{ t('cancel') }}</button>
                <button v-if="detail?.case.status === 'open' && can('moving-cases.update')" type="submit" form="moving-case-form" class="btn btn-primary" :disabled="busy">{{ t('moving.cases.decide') }}</button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// Moving cases (docs/moving_tasks.md B-14, B-17): customers' reports and the exceptions either side asked for.
// A person decides each one with the whole story: the inventory photos before and after, the evidence, the steps.
const { t } = useI18n();
const { can } = usePermission();
const { showError, showSuccess } = useToast();

const rows = ref([]);
const pagination = ref(null);
const loading = ref(false);
const busy = ref(false);
const detail = ref(null);
const filters = reactive({ status: 'open', kind: '' });
const form = reactive({ award_minor: 0, outcome: 'refund_all', note: '' });

const money = (minor, currency) => (minor === null || minor === undefined ? '—' : `${(minor / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })} ${currency ?? ''}`);

async function load(page = 1) {
    loading.value = true;
    try {
        const params = { page, status: filters.status, kind: filters.kind || undefined };
        const { data } = await adminAxios.get('/api/admin/v1/moving-cases', { params });
        rows.value = data.data ?? [];
        pagination.value = data.pagination ?? null;
    } catch (e) {
        showError(extractApiErrorMessage(e));
    } finally {
        loading.value = false;
    }
}

async function open(row) {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/moving-cases/${row.uuid}`);
        detail.value = data.data;
        Object.assign(form, { award_minor: 0, outcome: 'refund_all', note: '' });
    } catch (e) {
        showError(extractApiErrorMessage(e));
    }
}

async function decide() {
    busy.value = true;
    try {
        const body = detail.value.case.kind === 'report' ? { award_minor: form.award_minor, note: form.note } : { outcome: form.outcome, note: form.note };
        await adminAxios.post(`/api/admin/v1/moving-cases/${detail.value.case.uuid}/decide`, body);
        showSuccess(t('moving.common.saved'));
        detail.value = null;
        load(pagination.value?.current_page ?? 1);
    } catch (e) {
        showError(extractApiErrorMessage(e));
    } finally {
        busy.value = false;
    }
}

onMounted(() => load());
</script>
