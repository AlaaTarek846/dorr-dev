<template>
    <div>
        <WalletPageHeader :title="t('wallet.rules.title')" :total="pagination?.total ?? null" />

        <div class="alert alert-info fs-13">{{ t('wallet.rules.intro') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="input-group input-group-sm" style="max-width: 260px;">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="filters.search" type="search" class="form-control" :placeholder="t('wallet.rules.search')">
                </div>
                <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave ms-auto" @click="openCreate">
                    <i class="ri-add-line me-1 align-middle"></i>{{ t('wallet.rules.add') }}
                </button>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('wallet.rules.name') }}</th>
                                <th>{{ t('wallet.rules.percent') }}</th>
                                <th>{{ t('wallet.rules.applies_to') }}</th>
                                <th>{{ t('wallet.rules.period') }}</th>
                                <th>{{ t('wallet.rules.budget') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="7" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="7" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id">
                                    <td class="ps-4">
                                        <div class="fw-semibold">{{ row.name || `#${row.id}` }}</div>
                                        <div class="fs-12 text-muted">{{ t('wallet.rules.priority') }}: {{ row.priority }}</div>
                                    </td>
                                    <td>
                                        <span class="badge" :class="row.kind === 'bonus' ? 'bg-pink-transparent' : 'bg-primary-transparent'">
                                            {{ row.kind === 'bonus' ? t('wallet.rules.bonus') : t('wallet.rules.fee') }}
                                        </span>
                                        <span class="fw-semibold ms-1" dir="ltr">{{ Number(row.percent) }}%</span>
                                    </td>
                                    <td class="fs-13">
                                        {{ row.country_id ? countryName(row.country_id) : t('wallet.rules.any_country') }} ·
                                        {{ row.payment_method_id ? methodName(row.payment_method_id) : t('wallet.rules.any_method') }} ·
                                        {{ row.owner_type ? t(`wallet.owner.${row.owner_type}`) : t('wallet.rules.any_owner') }}
                                    </td>
                                    <td class="fs-12">{{ period(row) }}</td>
                                    <td class="fs-12">
                                        <template v-if="row.budget_total_minor != null">
                                            {{ fmtMinor(row.budget_used_minor) }} / {{ fmtMinor(row.budget_total_minor) }}
                                        </template>
                                        <span v-else class="text-muted">—</span>
                                    </td>
                                    <td>
                                        <div v-if="canChangeStatus" class="toggle toggle-success mb-0" :class="{ on: row.status }" role="button" @click="toggleStatus(row)"><span></span></div>
                                        <span v-else class="badge" :class="row.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">{{ row.status ? t('wallet.common.active') : t('wallet.common.inactive') }}</span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="btn-list justify-content-end">
                                            <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" @click="openEdit(row)"><i class="ri-pencil-line"></i></button>
                                            <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" @click="remove(row)"><i class="ri-delete-bin-line"></i></button>
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

        <WalletModal :show="showModal" :title="editingId ? t('wallet.rules.edit') : t('wallet.rules.add')" size="xl" @close="showModal = false">
            <form id="rule-form" @submit.prevent="save">
                <div v-for="lang in languages" :key="lang.code" class="row g-2 mb-2">
                    <div class="col-md-5">
                        <label class="form-label">{{ t('wallet.rules.name') }} ({{ lang.name || lang.code }}) <span class="text-danger">*</span></label>
                        <input v-model="form.translations[lang.code].name" type="text" maxlength="100" class="form-control">
                    </div>
                    <div class="col-md-7">
                        <label class="form-label">{{ t('wallet.methods.description') }} ({{ lang.code }})</label>
                        <input v-model="form.translations[lang.code].description" type="text" maxlength="255" class="form-control">
                    </div>
                </div>
                <div v-if="errors.translations" class="text-danger fs-13 mb-2">{{ errors.translations }}</div>

                <div class="card bg-light border-0 my-3">
                    <div class="card-body">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label">{{ t('wallet.rules.percent') }} (%) <span class="text-danger">*</span></label>
                                <input v-model="form.percent" type="number" step="0.01" min="-100" max="100" class="form-control" dir="ltr" :class="{ 'is-invalid': errors.percent }">
                                <div v-if="errors.percent" class="invalid-feedback">{{ errors.percent }}</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ t('wallet.rules.percent_confirm') }}</label>
                                <input v-model="form.percent_confirmation" type="number" step="0.01" class="form-control" dir="ltr" :class="{ 'is-invalid': errors.percent_confirmation }">
                                <div v-if="errors.percent_confirmation" class="invalid-feedback">{{ errors.percent_confirmation }}</div>
                            </div>
                            <div class="col-md-4">
                                <span v-if="Number(form.percent) > 0" class="badge bg-primary-transparent">{{ t('wallet.rules.hint_fee') }}</span>
                                <span v-else-if="Number(form.percent) < 0" class="badge bg-pink-transparent">{{ t('wallet.rules.hint_bonus') }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">{{ t('wallet.wallets.country') }}</label>
                        <select v-model="form.country_id" class="form-select">
                            <option :value="null">{{ t('wallet.rules.any_country') }}</option>
                            <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name || c.code }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ t('wallet.rules.method') }}</label>
                        <select v-model="form.payment_method_id" class="form-select">
                            <option :value="null">{{ t('wallet.rules.any_method') }}</option>
                            <option v-for="m in methods" :key="m.id" :value="m.id">{{ m.name || m.code }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ t('wallet.common.owner') }}</label>
                        <select v-model="form.owner_type" class="form-select">
                            <option :value="null">{{ t('wallet.rules.any_owner') }}</option>
                            <option value="user">{{ t('wallet.owner.user') }}</option>
                            <option value="provider">{{ t('wallet.owner.provider') }}</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">{{ t('wallet.rules.min_amount') }}</label>
                        <input v-model="form.min_amount" type="text" inputmode="decimal" class="form-control" dir="ltr" :class="{ 'is-invalid': errors.min_amount_minor }">
                        <div v-if="errors.min_amount_minor" class="invalid-feedback">{{ errors.min_amount_minor }}</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ t('wallet.rules.max_amount') }} <span v-if="Number(form.percent) < 0" class="text-danger">*</span></label>
                        <input v-model="form.max_amount" type="text" inputmode="decimal" class="form-control" dir="ltr" :class="{ 'is-invalid': errors.max_amount_minor }">
                        <div v-if="errors.max_amount_minor" class="invalid-feedback">{{ errors.max_amount_minor }}</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ t('wallet.rules.budget_total') }}</label>
                        <input v-model="form.budget_total" type="text" inputmode="decimal" class="form-control" dir="ltr" :class="{ 'is-invalid': errors.budget_total_minor }">
                        <div v-if="errors.budget_total_minor" class="invalid-feedback">{{ errors.budget_total_minor }}</div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">{{ t('wallet.rules.starts_at') }}</label>
                        <input v-model="form.starts_at" type="datetime-local" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ t('wallet.rules.ends_at') }}</label>
                        <input v-model="form.ends_at" type="datetime-local" class="form-control" :class="{ 'is-invalid': errors.ends_at }">
                        <div v-if="errors.ends_at" class="invalid-feedback">{{ errors.ends_at }}</div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">{{ t('wallet.rules.max_uses') }}</label>
                        <input v-model.number="form.max_uses_per_owner" type="number" min="1" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">{{ t('wallet.rules.priority') }}</label>
                        <input v-model.number="form.priority" type="number" min="0" class="form-control">
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch"><input id="r-status" v-model="form.status" class="form-check-input" type="checkbox"><label class="form-check-label" for="r-status">{{ t('wallet.common.active') }}</label></div>
                    </div>
                </div>
                <div v-if="errors.general" class="text-danger mt-3 fs-13">{{ errors.general }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="saving" @click="showModal = false">{{ t('cancel') }}</button>
                <button type="submit" form="rule-form" class="btn btn-primary" :disabled="saving">
                    <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}
                </button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import useWalletList from '../../../../../../../composables/useWalletList';
import { usePermission } from '../../../../../../../composables/usePermission';
import { useAvailableLanguagesStore } from '../../../../../../../stores/availableLanguages';
import { fmtMinor, majorFromMinor, parseMajor } from '../../../../../../../utils/walletMoney';

const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const languagesStore = useAvailableLanguagesStore();

const canCreate = computed(() => can('wallet-fee-rules.create'));
const canUpdate = computed(() => can('wallet-fee-rules.update'));
const canDelete = computed(() => can('wallet-fee-rules.delete'));
const canChangeStatus = computed(() => can('wallet-fee-rules.change-status'));

const { rows, loading, pagination, filters, fetch } = useWalletList('wallet-fee-rules', { defaults: { search: '' } });

const languages = computed(() => languagesStore.items);
const countries = ref([]);
const methods = ref([]);

const showModal = ref(false);
const editingId = ref(null);
const saving = ref(false);
const errors = reactive({});

const emptyForm = () => ({
    percent: '', percent_confirmation: '', country_id: null, payment_method_id: null, owner_type: null,
    min_amount: '', max_amount: '', budget_total: '', starts_at: '', ends_at: '',
    max_uses_per_owner: null, priority: 0, status: true, translations: {},
});
const form = reactive(emptyForm());

const countryName = (id) => countries.value.find((c) => c.id === id)?.code ?? `#${id}`;
const methodName = (id) => methods.value.find((m) => m.id === id)?.name ?? `#${id}`;

function period(row) {
    if (! row.starts_at && ! row.ends_at) {
        return t('wallet.rules.always');
    }

    const d = (v) => (v ? new Date(v).toLocaleDateString() : '…');

    return `${d(row.starts_at)} → ${d(row.ends_at)}`;
}

/** ISO from the API → value for <input type="datetime-local">. */
function toLocalInput(iso) {
    if (! iso) {
        return '';
    }

    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function ensureTranslations() {
    languages.value.forEach((lang) => {
        form.translations[lang.code] ??= { name: '', description: '' };
    });
}

function resetErrors() {
    Object.keys(errors).forEach((key) => delete errors[key]);
}

function openCreate() {
    editingId.value = null;
    resetErrors();
    Object.assign(form, emptyForm());
    ensureTranslations();
    showModal.value = true;
}

function openEdit(row) {
    editingId.value = row.id;
    resetErrors();
    Object.assign(form, emptyForm(), {
        percent: String(Number(row.percent)),
        percent_confirmation: String(Number(row.percent)), // already confirmed once; asked again only if changed
        country_id: row.country_id, payment_method_id: row.payment_method_id, owner_type: row.owner_type,
        min_amount: majorFromMinor(row.min_amount_minor), max_amount: majorFromMinor(row.max_amount_minor),
        budget_total: majorFromMinor(row.budget_total_minor),
        starts_at: toLocalInput(row.starts_at), ends_at: toLocalInput(row.ends_at),
        max_uses_per_owner: row.max_uses_per_owner, priority: row.priority, status: !!row.status,
    });
    ensureTranslations();
    (row.translations ?? []).forEach((tr) => {
        if (form.translations[tr.locale]) {
            form.translations[tr.locale] = { name: tr.name ?? '', description: tr.description ?? '' };
        }
    });
    showModal.value = true;
}

function moneyField(text, key) {
    const minor = parseMajor(text);

    if (Number.isNaN(minor)) {
        errors[key] = t('wallet.common.invalid_amount');
    }

    return minor;
}

async function save() {
    resetErrors();

    const min = moneyField(form.min_amount, 'min_amount_minor');
    const max = moneyField(form.max_amount, 'max_amount_minor');
    const budget = moneyField(form.budget_total, 'budget_total_minor');

    if (Object.keys(errors).length) {
        return;
    }

    saving.value = true;

    const body = {
        percent: form.percent === '' ? null : Number(form.percent),
        percent_confirmation: form.percent_confirmation === '' ? null : Number(form.percent_confirmation),
        country_id: form.country_id,
        payment_method_id: form.payment_method_id,
        owner_type: form.owner_type,
        min_amount_minor: min,
        max_amount_minor: max,
        budget_total_minor: budget,
        starts_at: form.starts_at ? new Date(form.starts_at).toISOString() : null,
        ends_at: form.ends_at ? new Date(form.ends_at).toISOString() : null,
        max_uses_per_owner: form.max_uses_per_owner || null,
        priority: form.priority || 0,
        status: form.status,
        translations: languages.value.map((lang) => ({
            locale: lang.code,
            name: form.translations[lang.code].name,
            description: form.translations[lang.code].description || null,
        })),
    };

    try {
        const url = editingId.value ? `/api/admin/v1/wallet-fee-rules/${editingId.value}` : '/api/admin/v1/wallet-fee-rules';

        await adminAxios[editingId.value ? 'put' : 'post'](url, body);

        showSuccess(t('wallet.rules.saved'));
        showModal.value = false;
        fetch();
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};

        Object.entries(bag).forEach(([key, messages]) => { errors[key] = messages[0]; });
        errors.general = Object.keys(bag).length ? '' : (error?.response?.data?.message ?? '');
    } finally {
        saving.value = false;
    }
}

async function toggleStatus(row) {
    try {
        await adminAxios.patch(`/api/admin/v1/wallet-fee-rules/${row.id}/status`, { status: ! row.status });
        row.status = ! row.status;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function remove(row) {
    if (! window.confirm(t('wallet.rules.confirm_delete', { name: row.name || `#${row.id}` }))) {
        return;
    }

    try {
        await adminAxios.delete(`/api/admin/v1/wallet-fee-rules/${row.id}`);
        showSuccess(t('wallet.rules.deleted'));
        fetch();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

// The modal renders its slot even while closed, so every language needs an entry as soon as the list loads.
watch(languages, () => ensureTranslations(), { immediate: true });

onMounted(async () => {
    fetch(1);
    await languagesStore.fetch();

    const [c, m] = await Promise.allSettled([
        adminAxios.get('/api/general/v1/countries/dropdown'),
        adminAxios.get('/api/admin/v1/payment-methods/dropdown'),
    ]);

    countries.value = c.status === 'fulfilled' ? (c.value.data.data ?? []) : [];
    methods.value = m.status === 'fulfilled' ? (m.value.data.data ?? []) : [];
});
</script>
