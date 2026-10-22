<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('freelance.policies.title') }}
                <span v-if="ownCount" class="badge bg-primary-transparent ms-2 fs-12 align-middle">{{ t('freelance.policies.own_count', { n: ownCount }) }}</span>
            </h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item">{{ t('sidebar.freelance') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('freelance.policies.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="alert alert-light border mb-3 fs-13">{{ t('freelance.policies.hint') }}</div>

        <!-- The scope: a country, optionally one specialty. -->
        <div class="card custom-card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2 catalog-toolbar-filters">
                    <Select
                        v-model="scope.country_id"
                        :options="countryOptions"
                        option-label="label"
                        option-value="value"
                        :placeholder="t('discover.catalog.country')"
                        filter
                        :filter-placeholder="t('search_placeholder')"
                        class="wallet-filter-select"
                    />
                    <Select
                        v-if="options.specialties.length"
                        v-model="scope.specialty_id"
                        :options="specialtyOptions"
                        option-label="label"
                        option-value="value"
                        filter
                        :filter-placeholder="t('search_placeholder')"
                        class="wallet-filter-select"
                    />
                    <span v-if="currency.code" class="badge bg-light text-dark border fs-12">
                        <i class="ri-money-dollar-circle-line me-1"></i>{{ t('freelance.policies.currency_of_country', { code: currency.code, symbol: currency.symbol }) }}
                    </span>
                </div>
                <div class="catalog-toolbar-actions d-flex flex-wrap align-items-center gap-2">
                    <span v-for="s in scopesOfCountry" :key="s.id" class="badge bg-info-transparent" role="button" @click="scope.specialty_id = s.specialty_id">
                        {{ s.specialty_id ? specialtyName(s.specialty_id) : t('freelance.policies.all_specialties') }} · {{ s.keys.length }}
                    </span>
                    <button type="button" class="btn btn-outline-secondary btn-sm btn-wave" :disabled="downloading || !scope.country_id" @click="downloadGuide">
                        <span v-if="downloading" class="spinner-border spinner-border-sm me-1"></span><i v-else class="ri-file-word-2-line me-1 align-middle"></i>{{ t('freelance.switches.guide') }}
                    </button>
                    <button v-if="canUpdate" type="button" class="btn btn-outline-primary btn-sm btn-wave" :disabled="!otherCountries.length" @click="openCopy">
                        <i class="ri-file-copy-line me-1 align-middle"></i>{{ t('freelance.policies.copy') }}
                    </button>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <!-- The numbers, by group: what this scope inherits, and its own value when it sets one. -->
            <div class="col-xxl-8">
                <div v-if="loading" class="card custom-card"><div class="card-body p-0"><table class="table mb-0"><tbody><TableSkeleton :rows="10" :layout="['two-lines', 'text', 'toggle']" /></tbody></table></div></div>
                <div v-else class="row g-3">
                    <div v-for="group in groups" :key="group.name" class="col-lg-6">
                        <WalletSection :title="t(`freelance.policies.groups.${group.name}`)" :icon="groupIcon(group.name)" flush visible>
                            <table class="table mb-0 align-middle fs-13">
                                <tbody>
                                    <tr v-for="key in group.keys" :key="key" :class="{ 'policy-own': isOwn(key) }">
                                        <td class="ps-3">
                                            <div class="fw-semibold">{{ label(key) }}</div>
                                            <div class="text-muted fs-11">{{ t('freelance.policies.inherits') }}: {{ display(key, inherited[key], inherited) }}</div>
                                        </td>
                                        <td class="pe-3 text-end" style="width: 52%">
                                            <div class="d-flex align-items-center justify-content-end gap-2">
                                                <template v-if="isOwn(key)">
                                                    <div
                                                        v-if="meta(key).kind === 'bool'"
                                                        class="toggle toggle-success mb-0 catalog-status-toggle"
                                                        :class="{ on: own[key] }"
                                                        role="button"
                                                        tabindex="0"
                                                        @click="canUpdate && (own[key] = !own[key])"
                                                        @keydown.enter.space.prevent="canUpdate && (own[key] = !own[key])"
                                                    ><span></span></div>
                                                    <Select
                                                        v-else-if="meta(key).kind === 'enum'"
                                                        v-model="own[key]"
                                                        :options="meta(key).options.map((o) => ({ value: o, label: t(`freelance.policies.choice.${o}`) }))"
                                                        option-label="label"
                                                        option-value="value"
                                                        :disabled="!canUpdate"
                                                        append-to="body"
                                                        class="policy-select"
                                                    />
                                                    <div v-else class="input-group input-group-sm policy-input">
                                                        <input
                                                            :value="toForm(key, own[key])"
                                                            type="number"
                                                            min="0"
                                                            :step="step(key)"
                                                            class="form-control"
                                                            :placeholder="meta(key).kind === 'money' ? t('freelance.policies.no_limit') : ''"
                                                            :disabled="!canUpdate"
                                                            dir="ltr"
                                                            @input="own[key] = fromForm(key, $event.target.value)"
                                                        >
                                                        <span class="input-group-text">{{ unit(key) }}</span>
                                                    </div>
                                                </template>
                                                <span v-else class="text-muted">{{ display(key, inherited[key], inherited) }}</span>
                                                <button
                                                    v-if="canUpdate"
                                                    type="button"
                                                    class="btn btn-sm btn-icon"
                                                    :class="isOwn(key) ? 'btn-danger-light' : 'btn-primary-light'"
                                                    :title="isOwn(key) ? t('freelance.policies.inherit') : t('freelance.policies.override')"
                                                    @click="toggleOwn(key)"
                                                ><i :class="isOwn(key) ? 'ri-arrow-go-back-line' : 'ri-pencil-line'"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </WalletSection>
                    </div>
                </div>
            </div>

            <!-- What the numbers do to an amount, live, before saving. -->
            <div class="col-xxl-4">
                <div class="card custom-card policy-preview">
                    <div class="card-body">
                        <h6 class="fw-semibold mb-1"><i class="ri-calculator-line me-1 text-primary"></i>{{ t('freelance.policies.preview') }}</h6>
                        <p class="text-muted fs-12 mb-3">{{ t('freelance.policies.preview_hint') }}</p>
                        <label class="form-label">{{ t('freelance.policies.preview_amount') }}</label>
                        <div class="input-group mb-3">
                            <input v-model.number="previewAmount" type="number" min="0" :step="moneyStep" class="form-control" dir="ltr">
                            <span class="input-group-text">{{ currency.symbol || currency.code }}</span>
                        </div>
                        <div v-if="previewError" class="alert alert-danger fs-12 py-2">{{ previewError }}</div>
                        <ul v-else-if="preview" class="list-unstyled mb-0 fs-13">
                            <li class="d-flex justify-content-between py-1"><span>{{ t('freelance.policies.p_amount') }}</span><strong dir="ltr">{{ money(preview.amount_minor) }}</strong></li>
                            <li class="d-flex justify-content-between py-1"><span>{{ t('freelance.policies.p_client_fee') }}</span><span dir="ltr">+ {{ money(preview.client_fee_minor) }}</span></li>
                            <li class="d-flex justify-content-between py-1 border-bottom"><span class="fw-semibold">{{ t('freelance.policies.p_client_total') }}</span><strong dir="ltr">{{ money(preview.client_total_minor) }}</strong></li>
                            <li class="d-flex justify-content-between py-1 pt-2"><span>{{ t('freelance.policies.p_commission') }}</span><span dir="ltr" class="text-danger">− {{ money(preview.commission_minor) }}</span></li>
                            <li class="d-flex justify-content-between py-1"><span class="fw-semibold">{{ t('freelance.policies.p_net') }}</span><strong dir="ltr" class="text-success">{{ money(preview.freelancer_net_minor) }}</strong></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="canUpdate && !loading" class="policy-bar">
            <span class="fs-13 text-muted">{{ t('freelance.policies.own_count', { n: ownCount }) }}</span>
            <button type="button" class="btn btn-light" :disabled="saving || !dirty" @click="load">{{ t('cancel') }}</button>
            <button type="button" class="btn btn-primary" :disabled="saving || !dirty" @click="save">
                <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}
            </button>
        </div>

        <WalletModal :show="showCopy" :title="t('freelance.policies.copy')" size="md" @close="showCopy = false">
            <form id="freelance-policy-copy-form" novalidate @submit.prevent="copy">
                <div class="alert alert-light border fs-13 mb-3">{{ t('freelance.policies.copy_hint') }}</div>
                <label class="form-label">{{ t('freelance.switches.copy_from') }} <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="ri-earth-line"></i></span>
                    <Select v-model="copyFrom" :options="otherCountryOptions" option-label="label" option-value="value" filter :filter-placeholder="t('search_placeholder')" append-to="body" class="flex-grow-1" />
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" @click="showCopy = false">{{ t('cancel') }}</button>
                <button type="submit" form="freelance-policy-copy-form" class="btn btn-primary" :disabled="!copyFrom || copying">
                    <span v-if="copying" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}
                </button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import Select from 'primevue/select';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletSection from '../../../../../../../components/wallet/WalletSection.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// Prices & rules of freelance work (FL-008), one scope at a time: a country, or a country + specialty.
// Each key shows what the scope inherits; the pencil makes it the scope's own. Rates are typed in
// percent (basis points are stored), money in the country's own currency (its minor unit is stored).
const { t, te } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const canUpdate = computed(() => can('freelance-policies.update'));

const options = reactive({ countries: [], specialties: [], keys: {}, defaults: {} });
const scopes = ref([]);
const scope = reactive({ country_id: null, specialty_id: null });
const own = ref({});
const saved = ref('{}');
const inherited = ref({});
const loading = ref(false);
const saving = ref(false);
const showCopy = ref(false);
const copyFrom = ref(null);
const copying = ref(false);
const previewAmount = ref(1000);
const preview = ref(null);
const previewError = ref('');

const country = computed(() => options.countries.find((c) => c.id === scope.country_id));
const currency = computed(() => country.value?.currency ?? { code: null, symbol: null, decimal_places: 2 });
const factor = computed(() => 10 ** (currency.value.decimal_places ?? 2));
const moneyStep = computed(() => 1 / factor.value);
const countryLabel = (c) => `${c.name} (${c.code})`;
const countryOptions = computed(() => options.countries.map((c) => ({ value: c.id, label: countryLabel(c) })));
const otherCountries = computed(() => options.countries.filter((c) => c.id !== scope.country_id));
const otherCountryOptions = computed(() => otherCountries.value.map((c) => ({ value: c.id, label: `${countryLabel(c)} · ${c.currency?.code ?? ''}` })));
const specialtyOptions = computed(() => [{ value: null, label: t('freelance.policies.all_specialties') }, ...options.specialties.map((s) => ({ value: s.id, label: s.name }))]);
const specialtyName = (id) => options.specialties.find((s) => s.id === id)?.name ?? `#${id}`;
const scopesOfCountry = computed(() => scopes.value.filter((s) => s.country_id === scope.country_id));
const ownCount = computed(() => Object.keys(own.value).length);
const dirty = computed(() => JSON.stringify(own.value) !== saved.value);

const groups = computed(() => {
    const byGroup = {};
    Object.entries(options.keys).forEach(([key, m]) => (byGroup[m.group] ??= []).push(key));
    return Object.entries(byGroup).map(([name, keys]) => ({ name, keys }));
});

const meta = (key) => options.keys[key] ?? {};
const isOwn = (key) => Object.prototype.hasOwnProperty.call(own.value, key);
const effective = computed(() => ({ ...inherited.value, ...own.value }));
const isMoney = (key, values = effective.value) => meta(key).kind === 'money' || (meta(key).kind === 'amount' && values[meta(key).type_key] === 'fixed');
const isPercent = (key, values = effective.value) => meta(key).kind === 'amount' && values[meta(key).type_key] !== 'fixed';
const isDays = (key) => key.endsWith('_days') || key === 'review.days';
const label = (key) => {
    const k = `freelance.policies.keys.${key.replaceAll('.', '__')}`;
    return te(k) ? t(k) : key;
};
const unit = (key) => (isPercent(key) ? '%' : isMoney(key) ? currency.value.symbol || currency.value.code : isDays(key) ? t('freelance.policies.days') : '');
const step = (key) => (isPercent(key) ? 0.01 : isMoney(key) ? moneyStep.value : 1);
const toForm = (key, v) => {
    if (v === null || v === undefined) return '';
    if (isPercent(key)) return v / 100;
    if (isMoney(key)) return v / factor.value;
    return v;
};
const fromForm = (key, text) => {
    if (text === '' && meta(key).kind === 'money') return null;
    const n = Number(text);
    if (text === '' || Number.isNaN(n)) return text;
    if (isPercent(key)) return Math.round(n * 100);
    if (isMoney(key)) return Math.round(n * factor.value);
    return Math.round(n);
};
const money = (minor) => (minor / factor.value).toLocaleString(undefined, { minimumFractionDigits: currency.value.decimal_places, maximumFractionDigits: currency.value.decimal_places }) + ` ${currency.value.symbol || currency.value.code || ''}`;
const display = (key, v, values) => {
    if (meta(key).kind === 'bool') return v ? t('freelance.switches.on') : t('freelance.switches.off');
    if (meta(key).kind === 'enum') return t(`freelance.policies.choice.${v}`);
    if (v === null || v === undefined) return t('freelance.policies.no_limit');
    if (isPercent(key, values)) return `${v / 100}%`;
    if (isMoney(key, values)) return money(v);
    return isDays(key) ? `${v} ${t('freelance.policies.days')}` : String(v);
};
const groupIcon = (name) => ({ commission: 'ri-percent-line', client_fee: 'ri-user-received-2-line', offers: 'ri-auction-line', review: 'ri-checkbox-circle-line', milestones: 'ri-flag-2-line', project: 'ri-briefcase-4-line', earnings: 'ri-wallet-3-line' }[name] ?? 'ri-settings-3-line');

function toggleOwn(key) {
    const next = { ...own.value };
    if (isOwn(key)) delete next[key];
    else next[key] = inherited.value[key] ?? options.defaults[key] ?? null;
    own.value = next;
}

function apply(data) {
    own.value = { ...(data?.own ?? {}) };
    saved.value = JSON.stringify(own.value);
    inherited.value = data?.inherited ?? {};
}

async function load() {
    if (!scope.country_id) return;
    loading.value = true;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/freelance-policies', { params: { ...scope } });
        apply(data.data);
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;
    try {
        const { data } = await adminAxios.put('/api/admin/v1/freelance-policies', { ...scope, data: own.value });
        apply(data.data);
        showSuccess(t('freelance.policies.saved'));
        loadScopes();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        saving.value = false;
    }
}

let previewTimer = null;
function schedulePreview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(runPreview, 300);
}

async function runPreview() {
    if (!scope.country_id || previewAmount.value === '' || previewAmount.value === null) return;
    try {
        const { data } = await adminAxios.post('/api/admin/v1/freelance-policies/preview', {
            ...scope,
            amount_minor: Math.max(0, Math.round(Number(previewAmount.value) * factor.value)),
            data: own.value,
        });
        preview.value = data.data;
        previewError.value = '';
    } catch (error) {
        previewError.value = extractApiErrorMessage(error);
    }
}

const downloading = ref(false);

/** The Word file that explains this page (always Arabic), with the chosen country's numbers. */
async function downloadGuide() {
    downloading.value = true;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/freelance-policies/guide', { params: { country_id: scope.country_id }, responseType: 'blob' });
        const url = URL.createObjectURL(data);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'freelance-prices-and-rules.docx';
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        downloading.value = false;
    }
}

function openCopy() {
    copyFrom.value = otherCountries.value[0]?.id ?? null;
    showCopy.value = true;
}

async function copy() {
    copying.value = true;
    try {
        const { data } = await adminAxios.post('/api/admin/v1/freelance-policies/copy', { from_country_id: copyFrom.value, to_country_id: scope.country_id });
        showCopy.value = false;
        const skipped = data.data?.skipped ?? [];
        showSuccess(skipped.length ? t('freelance.policies.copied_skipped', { keys: skipped.map(label).join('، ') }) : t('freelance.policies.copied'));
        await load();
        loadScopes();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        copying.value = false;
    }
}

async function loadScopes() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/freelance-policies/scopes');
        scopes.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

watch(scope, load);
watch([own, inherited, previewAmount], schedulePreview, { deep: true });

onMounted(async () => {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/freelance-policies/options');
        Object.assign(options, data.data ?? {});
        scope.country_id = options.countries[0]?.id ?? null;
        loadScopes();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
});
</script>

<style scoped>
.policy-own td {
    background: rgba(var(--primary-rgb, 132, 90, 223), 0.05);
}

.policy-input {
    max-width: 170px;
}

.policy-select {
    min-width: 150px;
}

.policy-preview {
    position: sticky;
    top: 5rem;
}

.policy-bar {
    position: sticky;
    bottom: 0;
    z-index: 5;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.75rem;
    margin-top: 1rem;
    padding: 0.75rem 1rem;
    border-radius: 0.75rem;
    background: var(--custom-white, #fff);
    box-shadow: 0 -4px 18px rgba(0, 27, 83, 0.08);
}
</style>
