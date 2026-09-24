<template>
    <div>
        <WalletPageHeader :title="t('wallet.methods.title')" :total="pagination?.total ?? null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="input-group input-group-sm" style="max-width: 260px;">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="filters.search" type="search" class="form-control" :placeholder="t('wallet.methods.search')">
                </div>
                <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave ms-auto" @click="openCreate">
                    <i class="ri-add-line me-1 align-middle"></i>{{ t('wallet.methods.add') }}
                </button>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('wallet.methods.name') }}</th>
                                <th>{{ t('wallet.methods.gateway') }}</th>
                                <th>{{ t('wallet.methods.availability') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="5" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="5" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id">
                                    <td class="ps-4">
                                        <div class="fw-semibold">{{ row.name || row.code }}</div>
                                        <div class="fs-12 text-muted">{{ row.code }}</div>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary-transparent">{{ row.gateway }}</span>
                                        <span class="badge bg-light text-default ms-1">{{ row.type }}</span>
                                    </td>
                                    <td>
                                        <span v-if="row.is_global" class="badge bg-info-transparent">{{ t('wallet.methods.global') }}</span>
                                        <template v-else>
                                            <span v-for="c in row.countries" :key="c.country_id" class="badge me-1" :class="c.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">{{ c.country_code }}</span>
                                            <span v-if="!row.countries?.length" class="text-muted fs-12">{{ t('wallet.methods.no_countries') }}</span>
                                        </template>
                                    </td>
                                    <td>
                                        <div
                                            v-if="canChangeStatus"
                                            class="toggle toggle-success mb-0"
                                            :class="{ on: row.status }"
                                            role="button"
                                            @click="toggleStatus(row)"
                                        ><span></span></div>
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

        <WalletModal :show="showModal" :title="editingId ? t('wallet.methods.edit') : t('wallet.methods.add')" size="xl" @close="showModal = false">
            <form id="method-form" @submit.prevent="save">
                <ul class="nav nav-tabs mb-3">
                    <li class="nav-item"><button type="button" class="nav-link" :class="{ active: tab === 'general' }" @click="tab = 'general'">{{ t('wallet.methods.tab_general') }}</button></li>
                    <li v-if="showCredentials" class="nav-item"><button type="button" class="nav-link" :class="{ active: tab === 'credentials' }" @click="tab = 'credentials'">{{ t('wallet.methods.tab_credentials') }}</button></li>
                    <li v-if="!form.is_global" class="nav-item"><button type="button" class="nav-link" :class="{ active: tab === 'countries' }" @click="tab = 'countries'">{{ t('wallet.methods.tab_countries') }}</button></li>
                </ul>

                <div v-show="tab === 'general'">
                    <div v-for="lang in languages" :key="lang.code" class="row g-2 mb-2">
                        <div class="col-md-5">
                            <label class="form-label">{{ t('wallet.methods.name') }} ({{ lang.name || lang.code }}) <span class="text-danger">*</span></label>
                            <input v-model="form.translations[lang.code].name" type="text" maxlength="100" class="form-control" :class="{ 'is-invalid': errors[`translations.${lang.code}.name`] }">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label">{{ t('wallet.methods.description') }} ({{ lang.code }})</label>
                            <input v-model="form.translations[lang.code].description" type="text" maxlength="255" class="form-control">
                        </div>
                    </div>
                    <div v-if="errors.translations" class="text-danger fs-13 mb-2">{{ errors.translations }}</div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-4">
                            <label class="form-label">{{ t('wallet.methods.code') }} <span class="text-danger">*</span></label>
                            <input v-model="form.code" type="text" maxlength="64" class="form-control" dir="ltr" :class="{ 'is-invalid': errors.code }">
                            <div v-if="errors.code" class="invalid-feedback">{{ errors.code }}</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ t('wallet.methods.gateway') }}</label>
                            <select v-model="form.gateway" class="form-select" @change="onGatewayChange">
                                <option v-for="g in gateways" :key="g" :value="g">{{ g }}</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ t('wallet.methods.sort_order') }}</label>
                            <input v-model.number="form.sort_order" type="number" min="0" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ t('wallet.methods.type') }}</label>
                            <select v-model="form.type" class="form-select">
                                <option value="online">online</option>
                                <option value="manual">manual</option>
                            </select>
                        </div>
                        <div class="col-md-8 d-flex align-items-end gap-4 flex-wrap">
                            <div class="form-check form-switch"><input id="m-global" v-model="form.is_global" class="form-check-input" type="checkbox"><label class="form-check-label" for="m-global">{{ t('wallet.methods.global') }}</label></div>
                            <div class="form-check form-switch"><input id="m-topup" v-model="form.supports_topup" class="form-check-input" type="checkbox"><label class="form-check-label" for="m-topup">{{ t('wallet.methods.supports_topup') }}</label></div>
                            <div class="form-check form-switch"><input id="m-status" v-model="form.status" class="form-check-input" type="checkbox"><label class="form-check-label" for="m-status">{{ t('wallet.common.active') }}</label></div>
                        </div>
                    </div>
                </div>

                <div v-if="showCredentials" v-show="tab === 'credentials'">
                    <div class="alert alert-warning fs-13">
                        {{ editingId ? t('wallet.methods.credentials_keep') : t('wallet.methods.credentials_required') }}
                    </div>
                    <div class="row g-3">
                        <div v-for="field in credentialFields" :key="field" class="col-md-6">
                            <label class="form-label">{{ field }}</label>
                            <input
                                v-model="form.credentials[field]"
                                :type="isSecret(field) ? 'password' : 'text'"
                                class="form-control"
                                dir="ltr"
                                autocomplete="off"
                                :placeholder="editingId ? '••••••••' : ''"
                            >
                        </div>
                    </div>
                    <div v-if="errors.credentials" class="text-danger fs-13 mt-2">{{ errors.credentials }}</div>
                </div>

                <div v-if="!form.is_global" v-show="tab === 'countries'">
                    <p class="text-muted fs-13">{{ t('wallet.methods.countries_hint') }}</p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th></th><th>{{ t('wallet.wallets.country') }}</th><th>{{ t('wallet.methods.min') }}</th><th>{{ t('wallet.methods.max') }}</th><th>{{ t('wallet.common.active') }}</th></tr></thead>
                            <tbody>
                                <tr v-for="c in countryOptions" :key="c.id">
                                    <td><input v-model="countryState[c.id].enabled" class="form-check-input" type="checkbox"></td>
                                    <td>{{ c.name || c.code }} <span class="text-muted fs-11">{{ c.code }}</span></td>
                                    <td><input v-model="countryState[c.id].min" :disabled="!countryState[c.id].enabled" type="text" class="form-control form-control-sm" dir="ltr" style="max-width: 110px;" :placeholder="t('wallet.settings.no_limit')"></td>
                                    <td><input v-model="countryState[c.id].max" :disabled="!countryState[c.id].enabled" type="text" class="form-control form-control-sm" dir="ltr" style="max-width: 110px;" :placeholder="t('wallet.settings.no_limit')"></td>
                                    <td><input v-model="countryState[c.id].status" :disabled="!countryState[c.id].enabled" class="form-check-input" type="checkbox"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="errors.countries" class="text-danger fs-13 mt-2">{{ errors.countries }}</div>
                </div>

                <div v-if="errors.general" class="text-danger mt-3 fs-13">{{ errors.general }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="saving" @click="showModal = false">{{ t('cancel') }}</button>
                <button type="submit" form="method-form" class="btn btn-primary" :disabled="saving">
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
import { majorFromMinor, parseMajor } from '../../../../../../../utils/walletMoney';

const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const languagesStore = useAvailableLanguagesStore();

const canCreate = computed(() => can('payment-methods.create'));
const canUpdate = computed(() => can('payment-methods.update'));
const canDelete = computed(() => can('payment-methods.delete'));
const canChangeStatus = computed(() => can('payment-methods.change-status'));

const { rows, loading, pagination, filters, fetch } = useWalletList('payment-methods', { defaults: { search: '' } });

const gateways = ['myfatoorah', 'arb', 'urpay', 'sandbox', 'manual'];

/** What each gateway's driver reads from `credentials` (see Modules/Wallet gateways). */
const CREDENTIALS = {
    myfatoorah: ['api_url', 'api_key'],
    arb: ['tranportal_id', 'tranportal_password', 'tranportal_resource_key', 'hosted_url'],
    urpay: ['mode', 'payment_url', 'username', 'password', 'client_id', 'terminal_id', 'merchant_wallet_number', 'merchant_id', 'test_consumer_mobile_number'],
};

const showModal = ref(false);
const editingId = ref(null);
const saving = ref(false);
const tab = ref('general');
const errors = reactive({});
const languages = computed(() => languagesStore.items);
const countryOptions = ref([]);
const countryState = reactive({});

const form = reactive({
    code: '', gateway: 'myfatoorah', type: 'online', is_global: false, supports_topup: true, status: true, sort_order: 0,
    translations: {}, credentials: {},
});

const credentialFields = computed(() => CREDENTIALS[form.gateway] ?? []);
const showCredentials = computed(() => form.type === 'online' && credentialFields.value.length > 0);

const isSecret = (field) => /password|key|secret/i.test(field);

function onGatewayChange() {
    form.credentials = {};
    form.type = form.gateway === 'manual' ? 'manual' : 'online';
}

function ensureTranslations() {
    languages.value.forEach((lang) => {
        form.translations[lang.code] ??= { name: '', description: '' };
    });
}

function prepareCountries(linked = []) {
    countryOptions.value.forEach((c) => {
        const link = linked.find((l) => Number(l.country_id) === Number(c.id));

        countryState[c.id] = {
            enabled: !!link,
            min: link ? majorFromMinor(link.min_amount_minor) : '',
            max: link ? majorFromMinor(link.max_amount_minor) : '',
            status: link ? !!link.status : true,
        };
    });
}

function resetErrors() {
    Object.keys(errors).forEach((key) => delete errors[key]);
}

function openCreate() {
    editingId.value = null;
    resetErrors();
    tab.value = 'general';
    Object.assign(form, { code: '', gateway: 'myfatoorah', type: 'online', is_global: false, supports_topup: true, status: true, sort_order: 0, translations: {}, credentials: {} });
    ensureTranslations();
    prepareCountries();
    showModal.value = true;
}

function openEdit(row) {
    editingId.value = row.id;
    resetErrors();
    tab.value = 'general';
    Object.assign(form, {
        code: row.code, gateway: row.gateway, type: row.type, is_global: !!row.is_global, supports_topup: !!row.supports_topup,
        status: !!row.status, sort_order: row.sort_order ?? 0, translations: {}, credentials: {},
    });
    ensureTranslations();
    (row.translations ?? []).forEach((tr) => {
        if (form.translations[tr.locale]) {
            form.translations[tr.locale] = { name: tr.name ?? '', description: tr.description ?? '' };
        }
    });
    prepareCountries(row.countries ?? []);
    showModal.value = true;
}

function payload() {
    const body = {
        code: form.code.trim(),
        gateway: form.gateway,
        type: form.type,
        is_global: form.is_global,
        supports_topup: form.supports_topup,
        status: form.status,
        sort_order: form.sort_order || 0,
        translations: languages.value.map((lang) => ({
            locale: lang.code,
            name: form.translations[lang.code].name,
            description: form.translations[lang.code].description || null,
        })),
    };

    // Only send the credentials the admin actually typed: an empty form on edit keeps the stored ones.
    const typed = Object.fromEntries(Object.entries(form.credentials).filter(([, v]) => v !== '' && v != null));

    if (Object.keys(typed).length) {
        body.credentials = typed;
    }

    return body;
}

function countryLinks() {
    const links = [];

    for (const c of countryOptions.value) {
        const state = countryState[c.id];

        if (! state.enabled) {
            continue;
        }

        const min = parseMajor(state.min);
        const max = parseMajor(state.max);

        if (Number.isNaN(min) || Number.isNaN(max)) {
            return null;
        }

        links.push({ country_id: c.id, min_amount_minor: min, max_amount_minor: max, status: state.status });
    }

    return links;
}

async function save() {
    resetErrors();

    const links = form.is_global ? [] : countryLinks();

    if (links === null) {
        errors.countries = t('wallet.common.invalid_amount');
        tab.value = 'countries';

        return;
    }

    saving.value = true;

    try {
        const url = editingId.value ? `/api/admin/v1/payment-methods/${editingId.value}` : '/api/admin/v1/payment-methods';
        const { data } = await adminAxios[editingId.value ? 'put' : 'post'](url, payload());
        const id = data.data.id;

        // Country links are a separate endpoint (replaces the whole set).
        await adminAxios.put(`/api/admin/v1/payment-methods/${id}/countries`, { countries: links });

        showSuccess(t('wallet.methods.saved'));
        showModal.value = false;
        fetch();
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};

        Object.entries(bag).forEach(([key, messages]) => { errors[key] = messages[0]; });
        errors.general = Object.keys(bag).length ? '' : (error?.response?.data?.message ?? '');

        if (bag.credentials) {
            tab.value = 'credentials';
        }
    } finally {
        saving.value = false;
    }
}

async function toggleStatus(row) {
    try {
        await adminAxios.patch(`/api/admin/v1/payment-methods/${row.id}/status`, { status: ! row.status });
        row.status = ! row.status;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function remove(row) {
    if (! window.confirm(t('wallet.methods.confirm_delete', { name: row.name || row.code }))) {
        return;
    }

    try {
        await adminAxios.delete(`/api/admin/v1/payment-methods/${row.id}`);
        showSuccess(t('wallet.methods.deleted'));
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

    try {
        const { data } = await adminAxios.get('/api/general/v1/countries/dropdown');

        countryOptions.value = data.data ?? [];
        // The modal renders its slot even while closed, so every country needs a state row up front.
        prepareCountries();
    } catch {
        countryOptions.value = [];
    }
});
</script>
