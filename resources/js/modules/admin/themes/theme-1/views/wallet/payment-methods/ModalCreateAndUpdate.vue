<template>
    <div
        ref="modalElement"
        class="modal fade"
        tabindex="-1"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header catalog-modal-header">
                    <div class="d-flex align-items-center justify-content-between w-100 gap-3">
                        <h6 class="modal-title mb-0">
                            {{ modalTitle }}
                        </h6>
                        <button
                            type="button"
                            class="btn-close catalog-modal-close"
                            aria-label="Close"
                            @click="close"
                        ></button>
                    </div>
                </div>

                <form @submit.prevent="submit">
                    <div class="modal-body px-4 pb-2">
                        <ul class="nav nav-tabs mb-4">
                            <li class="nav-item">
                                <button type="button" class="nav-link" :class="{ active: tab === 'general' }" @click="tab = 'general'">
                                    {{ t('wallet.methods.tab_general') }}
                                </button>
                            </li>
                            <li v-if="showCredentials" class="nav-item">
                                <button type="button" class="nav-link" :class="{ active: tab === 'credentials' }" @click="tab = 'credentials'">
                                    {{ t('wallet.methods.tab_credentials') }}
                                </button>
                            </li>
                            <li v-if="!form.is_global" class="nav-item">
                                <button type="button" class="nav-link" :class="{ active: tab === 'countries' }" @click="tab = 'countries'">
                                    {{ t('wallet.methods.tab_countries') }}
                                </button>
                            </li>
                        </ul>

                        <div v-show="tab === 'general'">
                            <CatalogTranslationTabs
                                :languages="storableLanguages"
                                :active-locale="activeLocale"
                                :translation-tab-class="translationTabClass"
                                :translation-tab-feedback="translationTabFeedback"
                                @update:active-locale="activeLocale = $event"
                            />

                            <div v-if="translationsServerMessage" class="alert alert-danger py-2 px-3 mb-3">
                                {{ translationsServerMessage }}
                            </div>

                            <template v-if="form.translations[activeLocale]">
                                <div class="mb-3">
                                    <label for="method-name" class="form-label">
                                        {{ t('wallet.methods.name') }}
                                        <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light">
                                            <i class="ri-text"></i>
                                        </span>
                                        <input
                                            id="method-name"
                                            v-model="form.translations[activeLocale].name"
                                            type="text"
                                            maxlength="100"
                                            class="form-control"
                                            :class="fieldInputClass(activeLocale, 'name')"
                                            :placeholder="t('wallet.methods.name_placeholder')"
                                            @input="onTranslationInput(activeLocale, 'name')"
                                        >
                                        <FormFieldFeedback v-bind="fieldFeedbackFor(activeLocale, 'name')" />
                                    </div>
                                    <div v-if="fieldMessage(activeLocale, 'name')" class="invalid-feedback d-block">
                                        {{ fieldMessage(activeLocale, 'name') }}
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="method-description" class="form-label">{{ t('wallet.methods.description') }}</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light align-self-start">
                                            <i class="ri-file-text-line"></i>
                                        </span>
                                        <textarea
                                            id="method-description"
                                            v-model="form.translations[activeLocale].description"
                                            rows="2"
                                            maxlength="255"
                                            class="form-control"
                                            :class="fieldInputClass(activeLocale, 'description')"
                                            :placeholder="t('wallet.methods.description_placeholder')"
                                            @input="onTranslationInput(activeLocale, 'description')"
                                        ></textarea>
                                    </div>
                                    <div v-if="fieldMessage(activeLocale, 'description')" class="invalid-feedback d-block">
                                        {{ fieldMessage(activeLocale, 'description') }}
                                    </div>
                                </div>
                            </template>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="method-code" class="form-label">
                                        {{ t('wallet.methods.code') }}
                                        <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light">
                                            <i class="ri-code-s-slash-line"></i>
                                        </span>
                                        <input
                                            id="method-code"
                                            v-model="form.code"
                                            type="text"
                                            maxlength="64"
                                            dir="ltr"
                                            class="form-control"
                                            :class="codeInputClass"
                                            :placeholder="t('wallet.methods.code_placeholder')"
                                            @input="onFieldInput('code')"
                                        >
                                        <FormFieldFeedback v-bind="codeFeedback" />
                                    </div>
                                    <div v-if="codeMessage" class="invalid-feedback d-block">
                                        {{ codeMessage }}
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label for="method-sort-order" class="form-label">{{ t('wallet.methods.sort_order') }}</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light">
                                            <i class="ri-hashtag"></i>
                                        </span>
                                        <input
                                            id="method-sort-order"
                                            v-model.number="form.sort_order"
                                            type="number"
                                            min="0"
                                            class="form-control"
                                            :class="classOf('sort_order')"
                                            @input="onInput('sort_order')"
                                        >
                                        <FormFieldFeedback v-bind="feedbackOf('sort_order')" />
                                    </div>
                                    <div v-if="messageOf('sort_order')" class="invalid-feedback d-block">{{ messageOf('sort_order') }}</div>
                                </div>

                                <div class="col-md-6">
                                    <label for="method-gateway" class="form-label">{{ t('wallet.methods.gateway') }}</label>
                                    <Select
                                        filter
                                        :filter-placeholder="t('search_placeholder')"
                                        id="method-gateway"
                                        v-model="form.gateway"
                                        :options="gatewayOptions"
                                        option-label="label"
                                        option-value="value"
                                        append-to="self"
                                        class="w-100"
                                        @change="onGatewayChange"
                                    />
                                </div>

                                <div class="col-md-6">
                                    <label for="method-type" class="form-label">{{ t('wallet.methods.type') }}</label>
                                    <Select
                                        filter
                                        :filter-placeholder="t('search_placeholder')"
                                        id="method-type"
                                        v-model="form.type"
                                        :options="typeOptions"
                                        option-label="label"
                                        option-value="value"
                                        append-to="self"
                                        class="w-100"
                                    />
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label d-block mb-2">{{ t('wallet.methods.global') }}</label>
                                    <div
                                        class="toggle toggle-primary mb-0 catalog-modal-toggle"
                                        :class="{ on: form.is_global }"
                                        role="button"
                                        tabindex="0"
                                        @click="form.is_global = !form.is_global"
                                        @keydown.enter.space.prevent="form.is_global = !form.is_global"
                                    >
                                        <span></span>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label d-block mb-2">{{ t('wallet.methods.supports_topup') }}</label>
                                    <div
                                        class="toggle toggle-primary mb-0 catalog-modal-toggle"
                                        :class="{ on: form.supports_topup }"
                                        role="button"
                                        tabindex="0"
                                        @click="form.supports_topup = !form.supports_topup"
                                        @keydown.enter.space.prevent="form.supports_topup = !form.supports_topup"
                                    >
                                        <span></span>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label d-block mb-2">{{ t('wallet.common.active') }}</label>
                                    <div
                                        class="toggle toggle-success mb-0 catalog-modal-toggle"
                                        :class="{ on: form.status }"
                                        role="button"
                                        tabindex="0"
                                        @click="form.status = !form.status"
                                        @keydown.enter.space.prevent="form.status = !form.status"
                                    >
                                        <span></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-if="showCredentials" v-show="tab === 'credentials'">
                            <div class="alert alert-warning fs-13">
                                {{ isEdit ? t('wallet.methods.credentials_keep') : t('wallet.methods.credentials_required') }}
                            </div>
                            <div class="row g-3">
                                <div v-for="field in credentialFields" :key="field" class="col-md-6">
                                    <label :for="`method-credential-${field}`" class="form-label">{{ field }}</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light">
                                            <i :class="isSecret(field) ? 'ri-lock-password-line' : 'ri-key-2-line'"></i>
                                        </span>
                                        <input
                                            :id="`method-credential-${field}`"
                                            v-model="form.credentials[field]"
                                            :type="isSecret(field) ? 'password' : 'text'"
                                            class="form-control"
                                            :class="{ 'is-invalid': invalidOf('credentials') && ! isEdit }"
                                            @input="onInput('credentials')"
                                            dir="ltr"
                                            autocomplete="off"
                                            :placeholder="isEdit ? '••••••••' : ''"
                                        >
                                    </div>
                                </div>
                            </div>
                            <div v-if="messageOf('credentials')" class="invalid-feedback d-block mt-2">
                                {{ messageOf('credentials') }}
                            </div>
                        </div>

                        <div v-if="!form.is_global" v-show="tab === 'countries'">
                            <p class="text-muted fs-13">{{ t('wallet.methods.countries_hint') }}</p>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th style="width: 48px;"></th>
                                            <th>{{ t('wallet.wallets.country') }}</th>
                                            <th>{{ t('wallet.methods.min') }}</th>
                                            <th>{{ t('wallet.methods.max') }}</th>
                                            <th>{{ t('wallet.common.active') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="c in countryOptions" :key="c.id">
                                            <td>
                                                <input v-model="countryState[c.id].enabled" class="form-check-input" type="checkbox">
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <FlagImage :code="resolveCountryFlagCode(c)" :size="40" :width="22" :height="16" />
                                                    <span>{{ c.name || c.code }}</span>
                                                    <span class="text-muted fs-11">{{ c.code }}</span>
                                                </div>
                                            </td>
                                            <td>
                                                <input
                                                    v-model="countryState[c.id].min"
                                                    :disabled="!countryState[c.id].enabled"
                                                    type="text"
                                                    inputmode="decimal"
                                                    class="form-control form-control-sm"
                                                    dir="ltr"
                                                    style="max-width: 110px;"
                                                    :placeholder="t('wallet.settings.no_limit')"
                                                >
                                            </td>
                                            <td>
                                                <input
                                                    v-model="countryState[c.id].max"
                                                    :disabled="!countryState[c.id].enabled"
                                                    type="text"
                                                    inputmode="decimal"
                                                    class="form-control form-control-sm"
                                                    dir="ltr"
                                                    style="max-width: 110px;"
                                                    :placeholder="t('wallet.settings.no_limit')"
                                                >
                                            </td>
                                            <td>
                                                <div
                                                    class="toggle toggle-success toggle-sm mb-0 catalog-modal-toggle"
                                                    :class="{ on: countryState[c.id].status, 'opacity-50': !countryState[c.id].enabled }"
                                                    role="button"
                                                    tabindex="0"
                                                    @click="countryState[c.id].enabled && (countryState[c.id].status = !countryState[c.id].status)"
                                                    @keydown.enter.space.prevent="countryState[c.id].enabled && (countryState[c.id].status = !countryState[c.id].status)"
                                                >
                                                    <span></span>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-if="countriesError || serverErrors.countries?.[0]" class="invalid-feedback d-block mt-2">
                                {{ countriesError || serverErrors.countries[0] }}
                            </div>
                        </div>

                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">
                            {{ t('close') }}
                        </button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">
                            {{ submitting ? t('wallet.methods.saving') : t('save_changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import Select from 'primevue/select';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import CatalogTranslationTabs from '../../../../../../../components/catalog/CatalogTranslationTabs.vue';
import FlagImage from '../../../../../../../components/ui/FlagImage.vue';
import FormFieldFeedback from '../../../../../../../components/ui/FormFieldFeedback.vue';
import useFormFields from '../../../../../../../composables/useFormFields';
import useCatalogTranslationFields from '../../../../../../../composables/useCatalogTranslationFields';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../composables/useToast';
import useValidation from '../../../../../../../composables/useValidation';
import { resolveCountryFlagCode, setupCatalogModalWatcher } from '../../../../../../../utils/catalog';
import { majorFromMinor, parseMajor } from '../../../../../../../utils/walletMoney';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    type: {
        type: String,
        default: 'create',
    },
    record: {
        type: Object,
        default: null,
    },
    resourceUri: {
        type: String,
        default: '/api/admin/v1/payment-methods',
    },
});

const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const { stringFieldRules, numberRules, applyApiErrors, fieldFeedback } = useValidation();

const modalElement = ref(null);
const submitting = ref(false);
const serverErrors = reactive({});
const tab = ref('general');
const countriesError = ref('');
const countryOptions = ref([]);
const countryState = reactive({});
let modalInstance = null;
let v$;

const isEdit = computed(() => props.type === 'edit');

const gateways = ['myfatoorah', 'arb', 'urpay', 'sandbox', 'manual'];
const gatewayOptions = gateways.map((g) => ({ value: g, label: g }));
const typeOptions = [
    { value: 'online', label: 'online' },
    { value: 'manual', label: 'manual' },
];

/** What each gateway's driver reads from `credentials` (see Modules/Wallet gateways). */
const CREDENTIALS = {
    myfatoorah: ['api_url', 'api_key'],
    arb: ['tranportal_id', 'tranportal_password', 'tranportal_resource_key', 'hosted_url'],
    urpay: ['mode', 'payment_url', 'username', 'password', 'client_id', 'terminal_id', 'merchant_wallet_number', 'merchant_id', 'test_consumer_mobile_number'],
};

const emptyForm = () => ({
    code: '',
    gateway: 'myfatoorah',
    type: 'online',
    is_global: false,
    supports_topup: true,
    status: true,
    sort_order: 0,
    translations: {},
    credentials: {},
});

const form = reactive(emptyForm());

const {
    activeLocale,
    storableLanguages,
    translationRules,
    ensureLanguagesLoaded,
    translationTabFeedback,
    translationTabClass,
    fieldFeedbackFor,
    fieldInputClass,
    fieldMessage,
    onTranslationInput,
    resetTranslations,
    fillTranslations,
    buildTranslationsPayload,
    focusInvalidTranslationTab,
} = useCatalogTranslationFields({
    form,
    serverErrors,
    fields: [
        { name: 'name', labelKey: 'wallet.methods.name', max: 100, min: 2 },
        { name: 'description', labelKey: 'wallet.methods.description', max: 255, required: false },
    ],
    getV$: () => v$.value,
});

const rules = computed(() => ({
    translations: translationRules.value,
    code: stringFieldRules('wallet.methods.code', 64),
    sort_order: numberRules('wallet.methods.sort_order', { min: 0, integerOnly: true }),
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

// Only a server-side problem with the translations as a whole is shown as a banner; field errors stay under their fields.
const translationsServerMessage = computed(() => serverErrors.translations?.[0] ?? null);

const { feedbackOf, invalidOf, classOf, messageOf, onInput } = useFormFields({
    getV$: () => v$.value,
    form,
    serverErrors,
});

const credentialFields = computed(() => CREDENTIALS[form.gateway] ?? []);
const showCredentials = computed(() => form.type === 'online' && credentialFields.value.length > 0);
const isSecret = (field) => /password|key|secret/i.test(field);

function recordName(record) {
    const translation = record?.translations?.find((item) => item.name);

    return record?.name || translation?.name || record?.code || '';
}

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t('wallet.methods.add');
    }

    const record = props.record;

    if (! record?.id) {
        return t('wallet.methods.edit');
    }

    const name = recordName(record);

    return name
        ? `${t('wallet.methods.edit')} #${record.id} ${name}`
        : `${t('wallet.methods.edit')} #${record.id}`;
});

const codeFeedback = computed(() => fieldFeedback(v$.value.code, serverErrors.code?.[0], form.code));
const codeInputClass = computed(() => ({
    'is-invalid': codeFeedback.value.show && codeFeedback.value.invalid,
    'is-valid': codeFeedback.value.show && codeFeedback.value.valid,
}));
const codeMessage = computed(() => {
    if (! codeFeedback.value.invalid) {
        return null;
    }

    return v$.value.code.$errors[0]?.$message || serverErrors.code?.[0] || null;
});

function onFieldInput(field) {
    delete serverErrors[field];
    v$.value.$touch();
}

function onGatewayChange() {
    form.credentials = {};
    form.type = form.gateway === 'manual' ? 'manual' : 'online';
}

async function loadCountries() {
    if (countryOptions.value.length) {
        return;
    }

    try {
        const { data } = await adminAxios.get('/api/general/v1/countries/dropdown');

        countryOptions.value = data.data ?? [];
    } catch {
        countryOptions.value = [];
    }
}

function prepareCountries(linked = []) {
    countryOptions.value.forEach((c) => {
        const link = linked.find((l) => Number(l.country_id) === Number(c.id));

        countryState[c.id] = {
            enabled: Boolean(link),
            min: link ? majorFromMinor(link.min_amount_minor) : '',
            max: link ? majorFromMinor(link.max_amount_minor) : '',
            status: link ? Boolean(link.status) : true,
        };
    });
}

function resetValidation() {
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
    countriesError.value = '';
}

function resetForm() {
    Object.assign(form, emptyForm());
    tab.value = 'general';
    resetTranslations();
    prepareCountries();
    resetValidation();
}

function fillForm(record) {
    // The show endpoint may omit the country links the list already carries — keep whichever has them.
    const merged = { ...(props.record ?? {}), ...(record ?? {}) };

    Object.assign(form, emptyForm(), {
        code: merged.code ?? '',
        gateway: merged.gateway ?? 'myfatoorah',
        type: merged.type ?? 'online',
        is_global: Boolean(merged.is_global),
        supports_topup: Boolean(merged.supports_topup),
        status: Boolean(merged.status ?? true),
        sort_order: merged.sort_order ?? 0,
    });
    tab.value = 'general';
    fillTranslations(merged);
    prepareCountries(merged.countries ?? []);
    resetValidation();
}

function buildPayload() {
    const body = {
        code: form.code.trim(),
        gateway: form.gateway,
        type: form.type,
        is_global: form.is_global,
        supports_topup: form.supports_topup,
        status: form.status,
        sort_order: form.sort_order || 0,
        translations: buildTranslationsPayload().map((item) => ({
            ...item,
            description: item.description || null,
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

        if (! state?.enabled) {
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

function openModal() {
    if (! modalElement.value) {
        return;
    }

    modalInstance ??= new window.bootstrap.Modal(modalElement.value, { focus: false });
    modalInstance.show();
}

function closeModal() {
    modalInstance?.hide();
}

function close() {
    closeModal();
    emit('close');
}

function onModalHidden() {
    emit('close');
}

async function submit() {
    if (! (await v$.value.$validate())) {
        focusInvalidTranslationTab();
        tab.value = 'general';
        showWarning(t('toast.validation_error'));

        return;
    }

    const links = form.is_global ? [] : countryLinks();

    if (links === null) {
        countriesError.value = t('wallet.common.invalid_amount');
        tab.value = 'countries';

        return;
    }

    submitting.value = true;
    applyApiErrors(serverErrors, {});
    countriesError.value = '';

    try {
        const url = isEdit.value && props.record?.id ? `${props.resourceUri}/${props.record.id}` : props.resourceUri;
        const response = await adminAxios[isEdit.value && props.record?.id ? 'put' : 'post'](url, buildPayload());
        const id = response.data.data.id;

        // Country links are a separate endpoint (it replaces the whole set).
        await adminAxios.put(`${props.resourceUri}/${id}/countries`, { countries: links });

        showSuccess(extractApiMessage(response, t('wallet.methods.saved')));
        closeModal();
        emit('saved');
    } catch (error) {
        if (error.response?.status === 422) {
            const bag = error.response.data.errors ?? {};

            applyApiErrors(serverErrors, bag);
            focusInvalidTranslationTab();

            if (bag.credentials) {
                tab.value = 'credentials';
            } else if (bag.countries) {
                tab.value = 'countries';
            } else {
                tab.value = 'general';
            }

            showWarning(t('toast.validation_error'));
        } else {
            showError(extractApiErrorMessage(error, t('toast.error')));
        }
    } finally {
        submitting.value = false;
    }
}

setupCatalogModalWatcher({
    props,
    fillForm,
    resetForm,
    openModal,
    closeModal,
    resourceUri: props.resourceUri,
    onOpen: async () => {
        await Promise.all([ensureLanguagesLoaded(), loadCountries()]);
    },
});

onMounted(async () => {
    await ensureLanguagesLoaded();
    modalElement.value?.addEventListener('hidden.bs.modal', onModalHidden);
});

onUnmounted(() => {
    modalElement.value?.removeEventListener('hidden.bs.modal', onModalHidden);
    modalInstance?.dispose();
});
</script>

<style scoped>
.catalog-modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--default-border, #dee2e6);
}

.catalog-modal-header .modal-title {
    font-size: 1rem;
    font-weight: 600;
    line-height: 1.4;
}

.catalog-modal-close {
    margin: 0 !important;
    padding: 0.625rem;
    flex-shrink: 0;
    opacity: 0.65;
    background-size: 0.65rem;
}

.catalog-modal-close:hover {
    opacity: 1;
}

.catalog-modal-footer {
    padding: 1rem 1.5rem 1.25rem;
    gap: 0.5rem;
}

.catalog-modal-toggle.toggle-primary.on {
    background-color: rgb(var(--primary-rgb, 132, 90, 223)) !important;
}
</style>
