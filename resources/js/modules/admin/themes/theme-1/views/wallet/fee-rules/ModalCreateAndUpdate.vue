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
                        <CatalogTranslationTabs
                            :languages="storableLanguages"
                            :active-locale="activeLocale"
                            :translation-tab-class="translationTabClass"
                            :translation-tab-feedback="translationTabFeedback"
                            @update:active-locale="activeLocale = $event"
                        />

                        <div v-if="translationsGroupMessage" class="alert alert-danger py-2 px-3 mb-3">
                            {{ translationsGroupMessage }}
                        </div>

                        <template v-if="form.translations[activeLocale]">
                            <div class="mb-3">
                                <label for="rule-name" class="form-label">
                                    {{ t('wallet.rules.name') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-text"></i>
                                    </span>
                                    <input
                                        id="rule-name"
                                        v-model="form.translations[activeLocale].name"
                                        type="text"
                                        maxlength="100"
                                        class="form-control"
                                        :class="fieldInputClass(activeLocale, 'name')"
                                        :placeholder="t('wallet.rules.name_placeholder')"
                                        @input="onTranslationInput(activeLocale, 'name')"
                                    >
                                    <FormFieldFeedback v-bind="fieldFeedbackFor(activeLocale, 'name')" />
                                </div>
                                <div v-if="fieldMessage(activeLocale, 'name')" class="invalid-feedback d-block">
                                    {{ fieldMessage(activeLocale, 'name') }}
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="rule-description" class="form-label">{{ t('wallet.methods.description') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light align-self-start">
                                        <i class="ri-file-text-line"></i>
                                    </span>
                                    <textarea
                                        id="rule-description"
                                        v-model="form.translations[activeLocale].description"
                                        rows="2"
                                        maxlength="255"
                                        class="form-control"
                                        :class="fieldInputClass(activeLocale, 'description')"
                                        :placeholder="t('wallet.rules.description_placeholder')"
                                        @input="onTranslationInput(activeLocale, 'description')"
                                    ></textarea>
                                </div>
                                <div v-if="fieldMessage(activeLocale, 'description')" class="invalid-feedback d-block">
                                    {{ fieldMessage(activeLocale, 'description') }}
                                </div>
                            </div>
                        </template>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="rule-percent" class="form-label">
                                    {{ t('wallet.rules.percent') }} (%)
                                    <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-percent-line"></i></span>
                                    <input
                                        id="rule-percent"
                                        v-model="form.percent"
                                        type="number"
                                        step="0.01"
                                        min="-100"
                                        max="100"
                                        dir="ltr"
                                        class="form-control"
                                        :class="percentInputClass"
                                        @input="onFieldInput('percent')"
                                    >
                                    <FormFieldFeedback v-bind="percentFeedback" />
                                </div>
                                <div v-if="percentMessage" class="invalid-feedback d-block">{{ percentMessage }}</div>
                            </div>

                            <div class="col-md-4">
                                <label for="rule-percent-confirmation" class="form-label">{{ t('wallet.rules.percent_confirm') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-shield-check-line"></i></span>
                                    <input
                                        id="rule-percent-confirmation"
                                        v-model="form.percent_confirmation"
                                        type="number"
                                        step="0.01"
                                        dir="ltr"
                                        class="form-control"
                                        :class="{ 'is-invalid': serverErrors.percent_confirmation?.[0] }"
                                        @input="clearError('percent_confirmation')"
                                    >
                                </div>
                                <div v-if="serverErrors.percent_confirmation?.[0]" class="invalid-feedback d-block">
                                    {{ serverErrors.percent_confirmation[0] }}
                                </div>
                            </div>

                            <div class="col-md-4 d-flex align-items-end">
                                <span v-if="Number(form.percent) > 0" class="badge bg-primary-transparent">{{ t('wallet.rules.hint_fee') }}</span>
                                <span v-else-if="Number(form.percent) < 0" class="badge bg-pink-transparent">{{ t('wallet.rules.hint_bonus') }}</span>
                            </div>

                            <div class="col-md-4">
                                <label for="rule-country" class="form-label">{{ t('wallet.wallets.country') }}</label>
                                <Select
                                    :filter-placeholder="t('search_placeholder')"
                                    id="rule-country"
                                    v-model="form.country_id"
                                    :options="countryChoices"
                                    option-label="label"
                                    option-value="value"
                                    :placeholder="t('wallet.rules.any_country')"
                                    show-clear
                                    filter
                                    append-to="self"
                                    class="w-100"
                                />
                            </div>

                            <div class="col-md-4">
                                <label for="rule-method" class="form-label">{{ t('wallet.methods.title') }}</label>
                                <Select
                                    filter
                                    :filter-placeholder="t('search_placeholder')"
                                    id="rule-method"
                                    v-model="form.payment_method_id"
                                    :options="methodChoices"
                                    option-label="label"
                                    option-value="value"
                                    :placeholder="t('wallet.rules.any_method')"
                                    show-clear
                                    append-to="self"
                                    class="w-100"
                                />
                            </div>

                            <div class="col-md-4">
                                <label for="rule-owner" class="form-label">{{ t('wallet.common.owner') }}</label>
                                <Select
                                    filter
                                    :filter-placeholder="t('search_placeholder')"
                                    id="rule-owner"
                                    v-model="form.owner_type"
                                    :options="ownerChoices"
                                    option-label="label"
                                    option-value="value"
                                    :placeholder="t('wallet.rules.any_owner')"
                                    show-clear
                                    append-to="self"
                                    class="w-100"
                                />
                            </div>

                            <div class="col-md-4">
                                <label for="rule-min" class="form-label">{{ t('wallet.rules.min_amount') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-money-dollar-circle-line"></i></span>
                                    <input
                                        id="rule-min"
                                        v-model="form.min_amount"
                                        type="text"
                                        inputmode="decimal"
                                        dir="ltr"
                                        class="form-control"
                                        :class="{ 'is-invalid': serverErrors.min_amount_minor?.[0] }"
                                        @input="clearError('min_amount_minor')"
                                    >
                                </div>
                                <div v-if="serverErrors.min_amount_minor?.[0]" class="invalid-feedback d-block">{{ serverErrors.min_amount_minor[0] }}</div>
                            </div>

                            <div class="col-md-4">
                                <label for="rule-max" class="form-label">
                                    {{ t('wallet.rules.max_amount') }}
                                    <span v-if="Number(form.percent) < 0" class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-money-dollar-circle-line"></i></span>
                                    <input
                                        id="rule-max"
                                        v-model="form.max_amount"
                                        type="text"
                                        inputmode="decimal"
                                        dir="ltr"
                                        class="form-control"
                                        :class="{ 'is-invalid': serverErrors.max_amount_minor?.[0] }"
                                        @input="clearError('max_amount_minor')"
                                    >
                                </div>
                                <div v-if="serverErrors.max_amount_minor?.[0]" class="invalid-feedback d-block">{{ serverErrors.max_amount_minor[0] }}</div>
                            </div>

                            <div class="col-md-4">
                                <label for="rule-budget" class="form-label">{{ t('wallet.rules.budget_total') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-wallet-3-line"></i></span>
                                    <input
                                        id="rule-budget"
                                        v-model="form.budget_total"
                                        type="text"
                                        inputmode="decimal"
                                        dir="ltr"
                                        class="form-control"
                                        :class="{ 'is-invalid': serverErrors.budget_total_minor?.[0] }"
                                        @input="clearError('budget_total_minor')"
                                    >
                                </div>
                                <div v-if="serverErrors.budget_total_minor?.[0]" class="invalid-feedback d-block">{{ serverErrors.budget_total_minor[0] }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="rule-starts-at" class="form-label">{{ t('wallet.rules.starts_at') }}</label>
                                <AdminDatePicker v-model="form.starts_at" input-id="rule-starts-at" show-time class="w-100" />
                            </div>

                            <div class="col-md-6">
                                <label for="rule-ends-at" class="form-label">{{ t('wallet.rules.ends_at') }}</label>
                                <AdminDatePicker
                                    v-model="form.ends_at"
                                    input-id="rule-ends-at"
                                    show-time
                                    :invalid="Boolean(serverErrors.ends_at?.[0])"
                                    class="w-100"
                                    @update:model-value="clearError('ends_at')"
                                />
                                <div v-if="serverErrors.ends_at?.[0]" class="invalid-feedback d-block">{{ serverErrors.ends_at[0] }}</div>
                            </div>

                            <div class="col-md-4">
                                <label for="rule-max-uses" class="form-label">{{ t('wallet.rules.max_uses') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-repeat-line"></i></span>
                                    <input id="rule-max-uses" v-model.number="form.max_uses_per_owner" type="number" min="1" class="form-control">
                                </div>
                            </div>

                            <div class="col-md-4">
                                <label for="rule-priority" class="form-label">{{ t('wallet.rules.priority') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-hashtag"></i></span>
                                    <input id="rule-priority" v-model.number="form.priority" type="number" min="0" class="form-control">
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

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">
                            {{ t('close') }}
                        </button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">
                            {{ submitting ? t('wallet.rules.saving') : t('save_changes') }}
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
import AdminDatePicker from '../../../../../../../components/ui/AdminDatePicker.vue';
import FormFieldFeedback from '../../../../../../../components/ui/FormFieldFeedback.vue';
import useCatalogTranslationFields from '../../../../../../../composables/useCatalogTranslationFields';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../composables/useToast';
import useValidation from '../../../../../../../composables/useValidation';
import { setupCatalogModalWatcher } from '../../../../../../../utils/catalog';
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
        default: '/api/admin/v1/wallet-fee-rules',
    },
});

const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const { requiredField, applyApiErrors, fieldFeedback } = useValidation();

const modalElement = ref(null);
const submitting = ref(false);
const serverErrors = reactive({});
const countries = ref([]);
const methods = ref([]);
let modalInstance = null;
let v$;

const isEdit = computed(() => props.type === 'edit');

const emptyForm = () => ({
    percent: '',
    percent_confirmation: '',
    country_id: null,
    payment_method_id: null,
    owner_type: null,
    min_amount: '',
    max_amount: '',
    budget_total: '',
    starts_at: '',
    ends_at: '',
    max_uses_per_owner: null,
    priority: 0,
    status: true,
    translations: {},
});

const form = reactive(emptyForm());

const {
    activeLocale,
    storableLanguages,
    translationRules,
    ensureLanguagesLoaded,
    translationTabFeedback,
    translationTabClass,
    translationsGroupMessage,
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
        { name: 'name', labelKey: 'wallet.rules.name', max: 100, min: 1 },
        { name: 'description', labelKey: 'wallet.methods.description', max: 255, required: false },
    ],
    getV$: () => v$.value,
});

const rules = computed(() => ({
    translations: translationRules.value,
    percent: { required: requiredField('wallet.rules.percent') },
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

const countryChoices = computed(() => countries.value.map((c) => ({ value: c.id, label: c.name || c.code })));
const methodChoices = computed(() => methods.value.map((m) => ({ value: m.id, label: m.name || m.code })));
const ownerChoices = computed(() => [
    { value: 'user', label: t('wallet.owner.user') },
    { value: 'provider', label: t('wallet.owner.provider') },
]);

const percentFeedback = computed(() => fieldFeedback(v$.value.percent, serverErrors.percent?.[0], form.percent));
const percentInputClass = computed(() => ({
    'is-invalid': percentFeedback.value.show && percentFeedback.value.invalid,
    'is-valid': percentFeedback.value.show && percentFeedback.value.valid,
}));
const percentMessage = computed(() => {
    if (! percentFeedback.value.invalid) {
        return null;
    }

    return v$.value.percent.$errors[0]?.$message || serverErrors.percent?.[0] || null;
});

function recordName(record) {
    return record?.name || record?.translations?.find((item) => item.name)?.name || '';
}

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t('wallet.rules.add');
    }

    const record = props.record;

    if (! record?.id) {
        return t('wallet.rules.edit');
    }

    const name = recordName(record);

    return name
        ? `${t('wallet.rules.edit')} #${record.id} ${name}`
        : `${t('wallet.rules.edit')} #${record.id}`;
});

function clearError(field) {
    delete serverErrors[field];
}

function onFieldInput(field) {
    clearError(field);
    v$.value.$touch();
}

async function loadOptions() {
    if (countries.value.length && methods.value.length) {
        return;
    }

    const [c, m] = await Promise.allSettled([
        adminAxios.get('/api/general/v1/countries/dropdown'),
        adminAxios.get('/api/admin/v1/payment-methods/dropdown'),
    ]);

    countries.value = c.status === 'fulfilled' ? (c.value.data.data ?? []) : [];
    methods.value = m.status === 'fulfilled' ? (m.value.data.data ?? []) : [];
}

/** ISO from the API → "YYYY-MM-DDTHH:mm" string used by AdminDatePicker (show-time). */
function toLocalInput(iso) {
    if (! iso) {
        return '';
    }

    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function resetValidation() {
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function resetForm() {
    Object.assign(form, emptyForm());
    resetTranslations();
    resetValidation();
}

function fillForm(record) {
    const row = { ...(props.record ?? {}), ...(record ?? {}) };

    Object.assign(form, emptyForm(), {
        percent: row.percent == null ? '' : String(Number(row.percent)),
        // already confirmed once; asked again only if it is changed
        percent_confirmation: row.percent == null ? '' : String(Number(row.percent)),
        country_id: row.country_id ?? null,
        payment_method_id: row.payment_method_id ?? null,
        owner_type: row.owner_type ?? null,
        min_amount: majorFromMinor(row.min_amount_minor),
        max_amount: majorFromMinor(row.max_amount_minor),
        budget_total: majorFromMinor(row.budget_total_minor),
        starts_at: toLocalInput(row.starts_at),
        ends_at: toLocalInput(row.ends_at),
        max_uses_per_owner: row.max_uses_per_owner ?? null,
        priority: row.priority ?? 0,
        status: Boolean(row.status ?? true),
    });
    fillTranslations(row);
    resetValidation();
}

function moneyField(text, key) {
    const minor = parseMajor(text);

    if (Number.isNaN(minor)) {
        serverErrors[key] = [t('wallet.common.invalid_amount')];
    }

    return minor;
}

function buildPayload(min, max, budget) {
    return {
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
        translations: buildTranslationsPayload().map((item) => ({
            ...item,
            description: item.description || null,
        })),
    };
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
    v$.value.$touch();

    if (v$.value.$invalid) {
        focusInvalidTranslationTab();
        showWarning(t('toast.validation_error'));

        return;
    }

    applyApiErrors(serverErrors, {});

    const min = moneyField(form.min_amount, 'min_amount_minor');
    const max = moneyField(form.max_amount, 'max_amount_minor');
    const budget = moneyField(form.budget_total, 'budget_total_minor');

    if (Object.keys(serverErrors).length) {
        showWarning(t('toast.validation_error'));

        return;
    }

    submitting.value = true;

    try {
        const isUpdate = isEdit.value && props.record?.id;
        const url = isUpdate ? `${props.resourceUri}/${props.record.id}` : props.resourceUri;
        const response = await adminAxios[isUpdate ? 'put' : 'post'](url, buildPayload(min, max, budget));

        showSuccess(extractApiMessage(response, t('wallet.rules.saved')));
        closeModal();
        emit('saved');
    } catch (error) {
        if (error.response?.status === 422) {
            applyApiErrors(serverErrors, error.response.data.errors ?? {});
            focusInvalidTranslationTab();
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
        await Promise.all([ensureLanguagesLoaded(), loadOptions()]);
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
</style>
