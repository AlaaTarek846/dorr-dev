<template>
    <div>
        <WalletPageHeader :title="t('sports.contests.title')" :section="t('sidebar.sports')" :total="meta.total || null" />
        <div class="alert alert-warning fs-13"><i class="ri-scales-3-line me-1"></i>{{ t('sports.contests.legal') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <ul class="nav nav-pills nav-style-3 gap-1">
                    <li v-for="s in ['', 'draft', 'open', 'settled', 'cancelled']" :key="s" class="nav-item">
                        <button type="button" class="nav-link py-1 px-3" :class="{ active: status === s }" @click="status = s; load(1)">{{ s ? t(`sports.contests.s_${s}`) : t('sports.tiers.all') }}</button>
                    </li>
                </ul>
                <span v-if="reviewCount" class="badge bg-warning">{{ t('sports.contests.review_waiting', { n: reviewCount }) }}</span>
                <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave ms-auto" @click="openForm()"><i class="ri-add-line me-1"></i>{{ t('sports.contests.add') }}</button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-hover mb-0">
                        <thead><tr>
                            <th class="ps-4">{{ t('sports.contests.name') }}</th><th>{{ t('sports.contests.scope') }}</th><th>{{ t('sports.contests.rule') }}</th>
                            <th>{{ t('sports.contests.prize') }}</th><th>{{ t('wallet.common.status') }}</th><th>{{ t('sports.contests.winners') }}</th><th class="text-end pe-4"></th>
                        </tr></thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="7" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="7" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="c in rows" v-else :key="c.id">
                                <td class="ps-4"><div class="fw-semibold">{{ c.name }}</div><div class="text-muted fs-12">{{ c.match || c.competition }}<span v-if="c.round"> · {{ c.round }}</span></div></td>
                                <td>{{ t(`sports.contests.scope_${c.scope}`) }}</td>
                                <td>{{ t(`sports.contests.rule_${c.rule}`) }}</td>
                                <td>{{ prizeText(c) }}</td>
                                <td><span class="badge" :class="statusClass(c.status)">{{ t(`sports.contests.s_${c.status}`) }}</span></td>
                                <td><button type="button" class="btn btn-sm btn-light" @click="openDetail(c)">{{ c.winners_count }}</button></td>
                                <td class="text-end pe-4">
                                    <div class="btn-list justify-content-end">
                                        <button v-if="canUpdate && c.status === 'draft'" type="button" class="btn btn-sm btn-success-light" @click="act(c, 'open')">{{ t('sports.contests.open') }}</button>
                                        <button v-if="canUpdate && c.status === 'open'" type="button" class="btn btn-sm btn-primary-light" @click="act(c, 'settle')">{{ t('sports.contests.settle') }}</button>
                                        <button v-if="canUpdate && ['draft', 'open'].includes(c.status)" type="button" class="btn btn-sm btn-info-light btn-icon" @click="openForm(c)"><i class="ri-pencil-line"></i></button>
                                        <button v-if="canUpdate && ['draft', 'open'].includes(c.status)" type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('sports.contests.cancel')" @click="act(c, 'cancel')"><i class="ri-close-line"></i></button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- add / edit -->
        <WalletModal :show="showForm" :title="editing ? t('sports.contests.edit') : t('sports.contests.add')" size="lg" @close="showForm = false">
            <form id="sports-contest-form" @submit.prevent="save">
                <div class="row g-3">
                    <div v-for="lang in languages" :key="lang.code" class="col-md-6">
                        <label class="form-label">{{ t('sports.contests.name') }} ({{ lang.code }})</label>
                        <input v-model="form.names[lang.code]" type="text" maxlength="160" class="form-control">
                        <textarea v-model="form.terms[lang.code]" rows="2" class="form-control mt-1" :placeholder="t('sports.contests.terms')"></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ t('sports.contests.scope') }}</label>
                        <select v-model="form.scope" class="form-select">
                            <option v-for="s in ['match', 'round', 'competition']" :key="s" :value="s">{{ t(`sports.contests.scope_${s}`) }}</option>
                        </select>
                    </div>
                    <div v-if="form.scope === 'match'" class="col-md-8">
                        <label class="form-label">{{ t('sports.contests.match_id') }}</label>
                        <input v-model="form.match_id" type="text" dir="ltr" class="form-control" placeholder="uuid">
                    </div>
                    <template v-else>
                        <div class="col-md-4">
                            <label class="form-label">{{ t('sports.contests.competition') }}</label>
                            <select v-model="form.competition_id" class="form-select"><option v-for="c in competitions" :key="c.id" :value="c.id">{{ c.display_name }}</option></select>
                        </div>
                        <div v-if="form.scope === 'round'" class="col-md-4">
                            <label class="form-label">{{ t('sports.contests.round') }}</label>
                            <input v-model="form.round" type="text" class="form-control" placeholder="Regular Season - 8">
                        </div>
                        <div v-else class="col-md-4">
                            <label class="form-label">{{ t('sports.contests.ends_at') }}</label>
                            <input v-model="form.ends_at" type="datetime-local" dir="ltr" class="form-control">
                        </div>
                    </template>
                    <div class="col-md-4">
                        <label class="form-label">{{ t('sports.contests.rule') }}</label>
                        <select v-model="form.rule" class="form-select"><option v-for="r in ['exact', 'winner', 'points']" :key="r" :value="r">{{ t(`sports.contests.rule_${r}`) }}</option></select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ t('sports.contests.prize') }}</label>
                        <select v-model="form.prize_type" class="form-select"><option v-for="p in ['wallet', 'coupon', 'badge']" :key="p" :value="p">{{ t(`sports.contests.prize_${p}`) }}</option></select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ t('sports.contests.distribution') }}</label>
                        <select v-model="form.distribution" class="form-select"><option v-for="d in ['each', 'split', 'first_n']" :key="d" :value="d">{{ t(`sports.contests.dist_${d}`) }}</option></select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ t('sports.contests.countries') }}</label>
                        <MultiSelect v-model="form.countries" :options="countries" option-label="name" option-value="id" filter display="chip" class="w-100" :placeholder="t('sports.settings.everywhere')" />
                    </div>
                    <div v-if="form.prize_type === 'coupon'" class="col-md-4">
                        <label class="form-label">{{ t('sports.contests.coupon_kind') }}</label>
                        <select v-model="form.coupon.kind" class="form-select"><option value="fixed">{{ t('sports.contests.coupon_fixed') }}</option><option value="percent">{{ t('sports.contests.coupon_percent') }}</option></select>
                        <input v-if="form.coupon.kind === 'percent'" v-model.number="form.coupon.value" type="number" min="1" max="100" class="form-control mt-1" placeholder="%">
                        <input v-model.number="form.coupon.valid_days" type="number" min="1" class="form-control mt-1" :placeholder="t('sports.contests.valid_days')">
                    </div>
                    <div v-if="form.prize_type !== 'badge' && !(form.prize_type === 'coupon' && form.coupon.kind === 'percent')" class="col-md-8">
                        <label class="form-label">{{ t('sports.contests.amounts') }}</label>
                        <div class="row g-1">
                            <div v-for="cid in (form.countries.length ? form.countries : countries.map((c) => c.id)).slice(0, 12)" :key="cid" class="col-6">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">{{ countryName(cid) }}</span>
                                    <input v-model.number="form.amounts[cid]" type="number" min="0" step="0.01" dir="ltr" class="form-control">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3"><label class="form-label">{{ t('sports.contests.max_winners') }}</label><input v-model.number="form.max_winners" type="number" min="1" class="form-control"></div>
                    <div class="col-md-3"><label class="form-label">{{ t('sports.contests.budget') }}</label><input v-model.number="form.budget" type="number" min="0" step="0.01" class="form-control"></div>
                    <div class="col-md-3"><label class="form-label">{{ t('sports.contests.min_days') }}</label><input v-model.number="form.min_account_days" type="number" min="0" class="form-control"></div>
                    <div class="col-md-3"><label class="form-label">{{ t('sports.contests.review_above') }}</label><input v-model.number="form.review_above" type="number" min="0" step="0.01" class="form-control"></div>
                    <div class="col-12"><div class="form-check form-switch"><input id="sc-auto" v-model="form.auto_pay" class="form-check-input" type="checkbox"><label class="form-check-label" for="sc-auto">{{ t('sports.contests.auto_pay') }}</label></div></div>
                </div>
                <div v-if="error" class="text-danger fs-13 mt-2">{{ error }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" @click="showForm = false">{{ t('cancel') }}</button>
                <button type="submit" form="sports-contest-form" class="btn btn-primary" :disabled="saving"><span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}</button>
            </template>
        </WalletModal>

        <!-- winners -->
        <WalletModal :show="!!detail" :title="detail?.name || ''" size="lg" @close="detail = null">
            <div v-if="detail">
                <p class="text-muted fs-12 mb-2">{{ t('sports.contests.seed', { seed: detail.seed || '—' }) }}</p>
                <table class="table table-sm">
                    <thead><tr><th>#</th><th>{{ t('sports.contests.account') }}</th><th>{{ t('sports.contests.points') }}</th><th>{{ t('sports.contests.prize') }}</th><th>{{ t('wallet.common.status') }}</th><th></th></tr></thead>
                    <tbody>
                        <tr v-if="!detail.winners?.length"><td colspan="6" class="text-center text-muted py-3">{{ t('sports.contests.no_winners') }}</td></tr>
                        <tr v-for="w in detail.winners" :key="w.id">
                            <td>{{ w.rank }}</td>
                            <td>{{ w.account?.name }} <span class="text-muted fs-11" dir="ltr">{{ w.account?.phone }}</span></td>
                            <td>{{ w.points }}</td>
                            <td dir="ltr">{{ w.amount_minor != null ? (w.amount_minor / 100).toFixed(2) : '—' }} {{ w.currency_code }}</td>
                            <td><span class="badge" :class="winnerClass(w.status)">{{ t(`sports.contests.w_${w.status}`) }}</span><div v-if="w.note" class="fs-11 text-muted">{{ w.note }}</div></td>
                            <td class="text-end">
                                <template v-if="canUpdate && w.status === 'review'">
                                    <button type="button" class="btn btn-sm btn-success-light" @click="decide(w, 'paid')">{{ t('sports.contests.pay') }}</button>
                                    <button type="button" class="btn btn-sm btn-danger-light ms-1" @click="decide(w, 'rejected')">{{ t('sports.contests.refuse') }}</button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MultiSelect from 'primevue/multiselect';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';
import { useAvailableLanguagesStore } from '../../../../../../../stores/availableLanguages';

const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const languagesStore = useAvailableLanguagesStore();
const languages = computed(() => languagesStore.items);
const canCreate = computed(() => can('sports-contests.create'));
const canUpdate = computed(() => can('sports-contests.update'));

const rows = ref([]);
const meta = ref({});
const reviewCount = ref(0);
const loading = ref(false);
const status = ref('');
const countries = ref([]);
const competitions = ref([]);
const showForm = ref(false);
const editing = ref(null);
const saving = ref(false);
const error = ref('');
const detail = ref(null);
const blank = () => ({ names: {}, terms: {}, scope: 'match', match_id: '', competition_id: null, round: '', ends_at: '', rule: 'exact', prize_type: 'wallet', distribution: 'each', countries: [], amounts: {}, coupon: { kind: 'fixed', value: null, valid_days: 30 }, max_winners: null, budget: null, min_account_days: 7, review_above: null, auto_pay: true });
const form = reactive(blank());

const statusClass = (s) => ({ draft: 'bg-secondary-transparent', open: 'bg-success-transparent', settled: 'bg-primary-transparent', cancelled: 'bg-danger-transparent' }[s]);
const winnerClass = (s) => ({ pending: 'bg-light text-dark', review: 'bg-warning', paid: 'bg-success', rejected: 'bg-danger' }[s]);
const countryName = (id) => countries.value.find((c) => c.id === Number(id))?.name || id;

function prizeText(c) {
    if (c.prize_type === 'badge') return t('sports.contests.prize_badge');
    if (c.prize_type === 'coupon' && c.coupon?.kind === 'percent') return `${t('sports.contests.prize_coupon')} ${c.coupon.value}%`;
    const first = Object.entries(c.prize_amounts || {})[0];
    return `${t(`sports.contests.prize_${c.prize_type}`)}${first ? ` · ${(first[1] / 100).toFixed(2)} (${countryName(first[0])})` : ''}`;
}

function openForm(c = null) {
    editing.value = c;
    error.value = '';
    Object.assign(form, blank());
    if (c) {
        Object.assign(form, {
            scope: c.scope, match_id: c.match_id || '', competition_id: c.competition_id, round: c.round || '', ends_at: c.ends_at ? c.ends_at.slice(0, 16) : '',
            rule: c.rule, prize_type: c.prize_type, distribution: c.distribution, countries: [...(c.countries || [])], coupon: { kind: 'fixed', value: null, valid_days: 30, ...(c.coupon || {}) },
            max_winners: c.max_winners, budget: c.budget_minor != null ? c.budget_minor / 100 : null, min_account_days: c.min_account_days,
            review_above: c.review_above_minor != null ? c.review_above_minor / 100 : null, auto_pay: c.auto_pay,
            amounts: Object.fromEntries(Object.entries(c.prize_amounts || {}).map(([k, v]) => [k, v / 100])),
        });
        (c.translations || []).forEach((tr) => { form.names[tr.locale] = tr.name; form.terms[tr.locale] = tr.terms || ''; });
    }
    showForm.value = true;
}

async function save() {
    saving.value = true;
    error.value = '';
    const minor = (v) => (v === null || v === '' || v === undefined ? null : Math.round(Number(v) * 100));
    const body = {
        scope: form.scope, rule: form.rule, prize_type: form.prize_type, distribution: form.distribution, countries: form.countries,
        match_id: form.scope === 'match' ? form.match_id : null, competition_id: form.scope !== 'match' ? form.competition_id : null,
        round: form.scope === 'round' ? form.round : null, ends_at: form.scope === 'competition' ? form.ends_at || null : null,
        prize_amounts: Object.fromEntries(Object.entries(form.amounts).filter(([, v]) => v !== null && v !== '').map(([k, v]) => [k, minor(v)])),
        coupon: form.prize_type === 'coupon' ? { kind: form.coupon.kind, value: form.coupon.kind === 'percent' ? form.coupon.value : null, valid_days: form.coupon.valid_days || 30 } : null,
        max_winners: form.max_winners || null, budget_minor: minor(form.budget), min_account_days: form.min_account_days ?? 7, review_above_minor: minor(form.review_above), auto_pay: form.auto_pay,
        translations: languages.value.filter((l) => form.names[l.code]).map((l) => ({ locale: l.code, name: form.names[l.code], terms: form.terms[l.code] || null })),
    };
    try {
        if (editing.value) await adminAxios.patch(`/api/admin/v1/sports-contests/${editing.value.id}`, body);
        else await adminAxios.post('/api/admin/v1/sports-contests', body);
        showSuccess(t('sports.common.saved'));
        showForm.value = false;
        load(1);
    } catch (e) {
        error.value = extractApiErrorMessage(e);
    } finally {
        saving.value = false;
    }
}

async function act(c, what) {
    if (what === 'cancel' && !window.confirm(t('sports.contests.confirm_cancel'))) return;
    try {
        await adminAxios.post(`/api/admin/v1/sports-contests/${c.id}/${what}`);
        showSuccess(t('sports.common.saved'));
        load(meta.value.current_page || 1);
    } catch (e) {
        showError(extractApiErrorMessage(e));
    }
}

async function openDetail(c) {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/sports-contests/${c.id}`);
        detail.value = data.data;
    } catch (e) {
        showError(extractApiErrorMessage(e));
    }
}

async function decide(w, status) {
    const note = status === 'rejected' ? window.prompt(t('sports.contests.refuse_note')) : null;
    if (status === 'rejected' && note === null) return;
    try {
        const { data } = await adminAxios.patch(`/api/admin/v1/sports-contests/${detail.value.id}/winners/${w.id}`, { status, note });
        detail.value = data.data;
        load(meta.value.current_page || 1);
    } catch (e) {
        showError(extractApiErrorMessage(e));
    }
}

async function load(page = 1) {
    loading.value = true;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/sports-contests', { params: { page, status: status.value || undefined } });
        rows.value = data.data ?? [];
        meta.value = data.pagination ?? {};
        reviewCount.value = data.review_count ?? 0;
    } catch (e) {
        showError(extractApiErrorMessage(e));
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    load(1);
    languagesStore.fetch();
    const [cs, comps] = await Promise.all([
        adminAxios.get('/api/admin/v1/countries/dropdown').catch(() => ({ data: { data: [] } })),
        adminAxios.get('/api/admin/v1/sports-competitions', { params: { tier: 'active', per_page: 200 } }).catch(() => ({ data: { data: [] } })),
    ]);
    countries.value = cs.data?.data ?? [];
    competitions.value = comps.data?.data ?? [];
});
</script>
