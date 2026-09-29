<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('sms.whatsapp.title') }}
            </h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('sms.whatsapp.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-12">
                <form @submit.prevent="save">
                <div class="card custom-card">
                    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                        <h6 class="card-title fw-semibold mb-0">{{ t('sms.whatsapp.connection') }}</h6>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <button
                                v-if="canTest"
                                type="button"
                                class="btn btn-outline-primary btn-sm btn-wave"
                                :disabled="testing"
                                @click="testConnection"
                            >
                                <span v-if="testing" class="spinner-border spinner-border-sm me-1"></span>
                                {{ t('sms.whatsapp.test_connection') }}
                            </button>
                            <button
                                type="submit"
                                class="btn btn-primary btn-sm btn-wave"
                                :disabled="saving"
                            >
                                {{ t('save_changes') }}
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="whatsapp-name" class="form-label">
                                    {{ t('sms.whatsapp.name') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <input
                                    id="whatsapp-name"
                                    v-model="form.name"
                                    type="text"
                                    maxlength="150"
                                    class="form-control"
                                    :class="nameUi.inputClass"
                                    :placeholder="t('sms.whatsapp.name_placeholder')"
                                    @blur="v$.name.$touch()"
                                >
                                <div v-if="nameUi.message" class="invalid-feedback d-block">
                                    {{ nameUi.message }}
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="whatsapp-access-token" class="form-label">
                                    {{ t('sms.whatsapp.access_token') }}
                                    <span v-if="! hasAccessToken" class="text-danger">*</span>
                                </label>
                                <input
                                    id="whatsapp-access-token"
                                    v-model="form.access_token"
                                    type="password"
                                    class="form-control"
                                    :class="accessTokenUi.inputClass"
                                    autocomplete="off"
                                    :placeholder="hasAccessToken
                                        ? t('sms.whatsapp.access_token_stored')
                                        : t('sms.whatsapp.access_token_placeholder')"
                                    @blur="v$.access_token.$touch()"
                                >
                                <div
                                    v-if="accessTokenUi.message"
                                    class="invalid-feedback d-block"
                                >
                                    {{ accessTokenUi.message }}
                                </div>
                                <div
                                    v-else-if="hasAccessToken && ! form.access_token"
                                    class="form-text"
                                >
                                    {{ t('sms.whatsapp.access_token_stored_hint') }}
                                </div>
                            </div>
                            <div class="col-md-6">
                                <PhoneCountryInput
                                    v-model:country-id="form.phone_country_id"
                                    v-model:phone="form.phone"
                                    input-id="whatsapp-phone"
                                    :label="t('sms.whatsapp.phone_number')"
                                    :placeholder="phonePlaceholder"
                                    :error="phoneUi.message ?? ''"
                                    :invalid="phoneUi.feedback.show && phoneUi.feedback.invalid"
                                    load-on-show
                                    @country-change="onPhoneCountryChange"
                                    @countries-loaded="onCountriesLoaded"
                                />
                            </div>
                            <div class="col-md-6">
                                <label for="whatsapp-phone-number-id" class="form-label">
                                    {{ t('sms.whatsapp.phone_number_id') }}
                                    <span v-if="! isConfigured" class="text-danger">*</span>
                                </label>
                                <input
                                    id="whatsapp-phone-number-id"
                                    v-model="form.phone_number_id"
                                    type="text"
                                    maxlength="100"
                                    class="form-control"
                                    :class="phoneNumberIdUi.inputClass"
                                    autocomplete="off"
                                    :placeholder="t('sms.whatsapp.phone_number_id_placeholder')"
                                    @blur="v$.phone_number_id.$touch()"
                                >
                                <div v-if="phoneNumberIdUi.message" class="invalid-feedback d-block">
                                    {{ phoneNumberIdUi.message }}
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="whatsapp-business-account-id" class="form-label">
                                    {{ t('sms.whatsapp.business_account_id') }}
                                    <span v-if="! isConfigured" class="text-danger">*</span>
                                </label>
                                <input
                                    id="whatsapp-business-account-id"
                                    v-model="form.business_account_id"
                                    type="text"
                                    maxlength="100"
                                    class="form-control"
                                    :class="businessAccountIdUi.inputClass"
                                    autocomplete="off"
                                    :placeholder="t('sms.whatsapp.business_account_id_placeholder')"
                                    @blur="v$.business_account_id.$touch()"
                                >
                                <div v-if="businessAccountIdUi.message" class="invalid-feedback d-block">
                                    {{ businessAccountIdUi.message }}
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="whatsapp-api-version" class="form-label">{{ t('sms.whatsapp.api_version') }}</label>
                                <input
                                    id="whatsapp-api-version"
                                    v-model="form.api_version"
                                    type="text"
                                    maxlength="20"
                                    class="form-control"
                                    :class="apiVersionUi.inputClass"
                                    :placeholder="t('sms.whatsapp.api_version_placeholder')"
                                    @blur="v$.api_version.$touch()"
                                >
                                <div v-if="apiVersionUi.message" class="invalid-feedback d-block">
                                    {{ apiVersionUi.message }}
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('sms.whatsapp.status') }}</label>
                                <div
                                    class="toggle toggle-success mb-0 catalog-modal-toggle"
                                    :class="{ on: form.is_active }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.is_active = !form.is_active"
                                    @keydown.enter.space.prevent="form.is_active = !form.is_active"
                                >
                                    <span></span>
                                </div>
                            </div>
                        </div>

                        <hr class="my-4">

                        <h6 class="fw-semibold mb-3">{{ t('sms.whatsapp.countries') }}</h6>
                        <MultiSelect
                            v-model="form.countries"
                            :options="countries"
                            option-label="name"
                            option-value="id"
                            filter
                            filter-fields="['name', 'code', 'dial_code']"
                            :placeholder="t('sms.whatsapp.countries_placeholder')"
                            :loading="loadingCountries"
                            :disabled="loadingCountries"
                            display="chip"
                            :max-selected-labels="4"
                            append-to="self"
                            class="w-100 whatsapp-countries-select"
                        >
                            <template #option="{ option }">
                                <div class="d-flex align-items-center gap-2">
                                    <FlagImage
                                        :code="resolveCountryFlagCode(option)"
                                        :size="40"
                                        :width="24"
                                        :height="18"
                                    />
                                    <span class="flex-1 text-truncate">{{ option.name || option.code }}</span>
                                    <span class="text-muted fs-12">{{ option.dial_code }}</span>
                                </div>
                            </template>
                            <template #chip="{ value, removeCallback }">
                                <div class="d-flex align-items-center gap-1">
                                    <FlagImage
                                        :code="resolveCountryFlagCode(countryById(value))"
                                        :size="40"
                                        :width="18"
                                        :height="14"
                                    />
                                    <span class="me-1">{{ countryById(value)?.name || countryById(value)?.code }}</span>
                                    <i
                                        class="ri-close-line whatsapp-chip-remove"
                                        role="button"
                                        :title="t('remove')"
                                        @click.stop="removeCallback($event)"
                                    ></i>
                                </div>
                            </template>
                        </MultiSelect>
                    </div>
                </div>
                </form>

                <div class="card custom-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                            <h6 class="fw-semibold mb-0">{{ t('sms.whatsapp.otp_template') }}</h6>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <button
                                    v-if="canTest"
                                    type="button"
                                    class="btn btn-outline-secondary btn-sm btn-wave"
                                    :disabled="importing"
                                    @click="importTemplates"
                                >
                                    <span v-if="importing" class="spinner-border spinner-border-sm me-1"></span>
                                    {{ t('sms.whatsapp.import_from_meta') }}
                                </button>
                                <button
                                    v-if="canCreate"
                                    type="button"
                                    class="btn btn-primary btn-sm btn-wave"
                                    @click="openCreateTemplate"
                                >
                                    <i class="ri-add-line me-1 align-middle"></i>
                                    {{ t('sms.whatsapp.add_template') }}
                                </button>
                            </div>
                        </div>

                        <div v-if="!templates.length" class="text-center py-4">
                            <p class="text-muted mb-0">{{ t('sms.whatsapp.no_templates') }}</p>
                        </div>

                        <div v-else class="table-responsive">
                            <table class="table text-nowrap table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>{{ t('sms.whatsapp.col_template') }}</th>
                                        <th>{{ t('sms.whatsapp.col_language') }}</th>
                                        <th>{{ t('sms.whatsapp.col_category') }}</th>
                                        <th>{{ t('sms.whatsapp.col_status') }}</th>
                                        <th>{{ t('sms.whatsapp.col_active') }}</th>
                                        <th>{{ t('sms.whatsapp.col_last_sync') }}</th>
                                        <th class="text-end">{{ t('sms.whatsapp.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="tpl in templates" :key="tpl.id">
                                        <td class="fw-semibold">
                                            {{ tpl.template_name }}
                                            <div
                                                v-if="tpl.last_sync_error"
                                                class="text-danger fs-12 fw-normal"
                                                :title="tpl.last_sync_error"
                                            >
                                                {{ tpl.last_sync_error }}
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-info-transparent">{{ tpl.language?.code ?? '—' }}</span>
                                        </td>
                                        <td>{{ categoryLabel(tpl.category) }}</td>
                                        <td>
                                            <span
                                                class="badge"
                                                :class="metaStatusClass(tpl.meta_status)"
                                            >
                                                {{ metaStatusLabel(tpl.meta_status) }}
                                            </span>
                                        </td>
                                        <td>
                                            <span
                                                class="badge"
                                                :class="tpl.is_active ? 'bg-success-transparent' : 'bg-secondary-transparent'"
                                            >
                                                {{ tpl.is_active ? t('sms.whatsapp.yes') : t('sms.whatsapp.no') }}
                                            </span>
                                        </td>
                                        <td class="text-muted fs-12">
                                            {{ tpl.last_synced_at ?? '—' }}
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex align-items-center justify-content-end gap-1">
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-primary"
                                                    :title="t('sms.whatsapp.submit_to_meta')"
                                                    :disabled="submittingId === tpl.id"
                                                    @click="submitTemplate(tpl.id)"
                                                >
                                                    <span
                                                        v-if="submittingId === tpl.id"
                                                        class="spinner-border spinner-border-sm"
                                                    ></span>
                                                    <i v-else class="ri-upload-cloud-2-line"></i>
                                                </button>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-info"
                                                    :title="t('sms.whatsapp.sync')"
                                                    :disabled="syncingId === tpl.id"
                                                    @click="syncTemplate(tpl.id)"
                                                >
                                                    <i class="ri-refresh-line"></i>
                                                </button>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-primary"
                                                    :title="t('sms.whatsapp.edit')"
                                                    @click="openEditTemplate(tpl)"
                                                >
                                                    <i class="ri-pencil-line"></i>
                                                </button>
                                                <button
                                                    v-if="canDelete"
                                                    type="button"
                                                    class="btn btn-sm btn-outline-danger"
                                                    :title="t('sms.whatsapp.delete')"
                                                    @click="deleteTemplate(tpl.id)"
                                                >
                                                    <i class="ri-delete-bin-line"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Template Modal -->
        <div
            ref="templateModalElement"
            class="modal fade"
            tabindex="-1"
            aria-hidden="true"
        >
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title mb-0">
                            {{ isEditTemplate ? t('sms.whatsapp.edit_template') : t('sms.whatsapp.add_template') }}
                        </h6>
                        <button
                            type="button"
                            class="btn-close"
                            aria-label="Close"
                            @click="closeTemplateModal"
                        ></button>
                    </div>
                    <form @submit.prevent="saveTemplate">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">
                                    {{ t('sms.whatsapp.language') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <Select
                                    v-model="templateForm.language_id"
                                    :options="languages"
                                    option-label="name"
                                    option-value="id"
                                    filter
                                    filter-fields="['name', 'code']"
                                    :placeholder="t('sms.whatsapp.language_placeholder')"
                                    :class="languageUi.inputClass"
                                    append-to="self"
                                    class="w-100"
                                    @blur="templateV$.language_id.$touch()"
                                />
                                <div v-if="languageUi.message" class="invalid-feedback d-block">
                                    {{ languageUi.message }}
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">
                                    {{ t('sms.whatsapp.template_name') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <input
                                    v-model="templateForm.template_name"
                                    type="text"
                                    maxlength="120"
                                    class="form-control"
                                    :class="templateNameUi.inputClass"
                                    :placeholder="t('sms.whatsapp.template_name_placeholder')"
                                    @blur="templateV$.template_name.$touch()"
                                >
                                <div v-if="templateNameUi.message" class="invalid-feedback d-block">
                                    {{ templateNameUi.message }}
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">
                                    {{ t('sms.whatsapp.category') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <Select
                                    v-model="templateForm.category"
                                    :options="categoryOptions"
                                    option-label="label"
                                    option-value="value"
                                    append-to="self"
                                    class="w-100"
                                    :class="categoryUi.inputClass"
                                    @blur="templateV$.category.$touch()"
                                />
                                <div v-if="categoryUi.message" class="invalid-feedback d-block">
                                    {{ categoryUi.message }}
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('sms.whatsapp.active') }}</label>
                                <div
                                    class="toggle toggle-success mb-0 catalog-modal-toggle"
                                    :class="{ on: templateForm.is_active }"
                                    role="button"
                                    tabindex="0"
                                    @click="templateForm.is_active = !templateForm.is_active"
                                    @keydown.enter.space.prevent="templateForm.is_active = !templateForm.is_active"
                                >
                                    <span></span>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">
                                    {{ t('sms.whatsapp.body') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <textarea
                                    v-model="templateForm.body"
                                    class="form-control"
                                    :class="bodyUi.inputClass"
                                    rows="3"
                                    maxlength="1024"
                                    :placeholder="t('sms.whatsapp.body_placeholder')"
                                    @blur="templateV$.body.$touch()"
                                ></textarea>
                                <div v-if="bodyUi.message" class="invalid-feedback d-block">
                                    {{ bodyUi.message }}
                                </div>
                                <div v-else class="form-text">
                                    {{ t('sms.whatsapp.otp_variable_hint') }}
                                </div>
                            </div>
                        </div>

                        <!-- WhatsApp Preview -->
                        <div class="mt-4">
                            <label class="form-label fw-semibold">{{ t('sms.whatsapp.preview') }}</label>
                            <div class="whatsapp-preview">
                                <div class="whatsapp-preview-header">
                                    <i class="ri-shield-check-line me-1"></i>
                                    {{ t('sms.whatsapp.verification') }}
                                </div>
                                <div class="whatsapp-preview-body">
                                    {{ previewBody }}
                                </div>
                                <div class="whatsapp-preview-footer">
                                    {{ t('sms.whatsapp.do_not_share') }}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" @click="closeTemplateModal">
                            {{ t('cancel') }}
                        </button>
                        <button
                            type="submit"
                            class="btn btn-primary btn-wave"
                            :disabled="savingTemplate"
                        >
                            {{ t('save_changes') }}
                        </button>
                    </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import useVuelidate from '@vuelidate/core';
import { helpers } from '@vuelidate/validators';
import Select from 'primevue/select';
import MultiSelect from 'primevue/multiselect';
import adminAxios from '../../../../../../api/adminAxios';
import FlagImage from '../../../../../../components/ui/FlagImage.vue';
import PhoneCountryInput from '../../../../../../components/catalog/PhoneCountryInput.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import { usePermission } from '../../../../../../composables/usePermission';
import useValidation from '../../../../../../composables/useValidation';
import { combinePhoneNumber, resolveCountryFlagCode, splitPhoneNumber } from '../../../../../../utils/catalog';

const { t } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const { can } = usePermission();
const { requiredField, maxString, fieldFeedback, applyApiErrors, firstError } = useValidation();

const canTest = computed(() => can('whatsapp.test'));
const canCreate = computed(() => can('whatsapp.create'));
const canDelete = computed(() => can('whatsapp.delete'));

const saving = ref(false);
const testing = ref(false);
const importing = ref(false);
const syncing = ref(false);
const loadingCountries = ref(false);
const countries = ref([]);
const whatsapp = ref(null);
const serverErrors = reactive({});
const templateServerErrors = reactive({});

const templates = ref([]);
const languages = ref([]);
const loadingTemplates = ref(false);
const savingTemplate = ref(false);
const submittingId = ref(null);
const syncingId = ref(null);
const isEditTemplate = ref(false);
const editingTemplateId = ref(null);
const templateModalElement = ref(null);
let templateModalInstance = null;

const categoryOptions = computed(() => [
    { value: 'AUTHENTICATION', label: t('sms.whatsapp.category_authentication') },
    { value: 'MARKETING', label: t('sms.whatsapp.category_marketing') },
    { value: 'UTILITY', label: t('sms.whatsapp.category_utility') },
]);

const templateForm = reactive({
    language_id: null,
    template_name: '',
    category: 'AUTHENTICATION',
    body: '',
    is_active: false,
});

const form = reactive({
    name: '',
    access_token: '',
    phone_number_id: '',
    phone: '',
    phone_country_id: null,
    business_account_id: '',
    api_version: '',
    is_active: false,
    countries: [],
});

const hasAccessToken = ref(false);

const phoneCountry = ref(null);
const phoneDialCode = ref('');

/* --------------------------------------------------------------------- *
 | Validation
 * --------------------------------------------------------------------- */

/** Meta IDs are only mandatory while the single row is being created. */
const isConfigured = computed(() => Boolean(whatsapp.value));

const combinedPhone = computed(() => combinePhoneNumber(phoneDialCode.value, form.phone));

const rules = computed(() => ({
    name: {
        required: requiredField('sms.whatsapp.name'),
        maxLength: maxString('sms.whatsapp.name', 150),
    },
    access_token: {
        // A stored token is never returned, so an empty field is valid once one
        // exists and the backend keeps the current value.
        required: helpers.withMessage(
            () => t('validation.required', { field: t('sms.whatsapp.access_token') }),
            (value) => hasAccessToken.value || Boolean(String(value ?? '').trim()),
        ),
        maxLength: maxString('sms.whatsapp.access_token', 2000),
    },
    phone_number_id: {
        required: helpers.withMessage(
            () => t('validation.required', { field: t('sms.whatsapp.phone_number_id') }),
            (value) => isConfigured.value || Boolean(String(value ?? '').trim()),
        ),
        maxLength: maxString('sms.whatsapp.phone_number_id', 100),
    },
    business_account_id: {
        required: helpers.withMessage(
            () => t('validation.required', { field: t('sms.whatsapp.business_account_id') }),
            (value) => isConfigured.value || Boolean(String(value ?? '').trim()),
        ),
        maxLength: maxString('sms.whatsapp.business_account_id', 100),
    },
    phone: {
        // The number and its country are only valid together.
        pairRequired: helpers.withMessage(
            () => t('sms.whatsapp.phone_pair_required'),
            (value) => {
                const hasLocal = Boolean(String(value ?? '').trim());
                const hasCountry = Boolean(form.phone_country_id);

                return hasLocal === hasCountry;
            },
        ),
        format: helpers.withMessage(
            () => t('validation.digits_between', {
                field: t('sms.whatsapp.phone_number'),
                min: 6,
                max: 20,
            }),
            (value) => {
                if (! String(value ?? '').trim()) {
                    return true;
                }

                return /^\+?[0-9]{6,20}$/.test(combinedPhone.value);
            },
        ),
        phoneStartsWith: helpers.withMessage(
            () => t('sms.whatsapp.phone_starts_with_invalid', {
                prefix: String(phoneCountry.value?.phone_starts_with ?? ''),
            }),
            (value) => {
                const prefix = String(phoneCountry.value?.phone_starts_with ?? '').trim();

                if (! prefix) {
                    return true;
                }

                const local = String(value ?? '').replace(/\D/g, '');

                return local.startsWith(prefix);
            },
        ),
        phoneLength: helpers.withMessage(
            () => t('sms.whatsapp.phone_length_invalid', {
                length: selectedCountryPhoneLength.value ?? 0,
            }),
            (value) => {
                const length = selectedCountryPhoneLength.value;

                if (length === null) {
                    return true;
                }

                const local = String(value ?? '').replace(/\D/g, '');

                return local.length === length;
            },
        ),
    },
    api_version: {
        format: helpers.withMessage(
            () => t('validation.regex', { field: t('sms.whatsapp.api_version') }),
            (value) => ! String(value ?? '').trim() || /^v\d+\.\d+$/.test(String(value).trim()),
        ),
        maxLength: maxString('sms.whatsapp.api_version', 20),
    },
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

const templateRules = computed(() => ({
    language_id: {
        required: requiredField('sms.whatsapp.language'),
    },
    template_name: {
        required: requiredField('sms.whatsapp.template_name'),
        maxLength: maxString('sms.whatsapp.template_name', 120),
        format: helpers.withMessage(
            () => t('sms.whatsapp.template_name_format'),
            (value) => /^[a-z0-9_]+$/.test(String(value ?? '').trim()),
        ),
    },
    category: {
        required: requiredField('sms.whatsapp.category'),
    },
    body: {
        required: requiredField('sms.whatsapp.body'),
        maxLength: maxString('sms.whatsapp.body', 1024),
        // Meta rejects AUTHENTICATION templates without the OTP variable.
        otpVariable: helpers.withMessage(
            () => t('sms.whatsapp.otp_variable_required'),
            (value) => templateForm.category !== 'AUTHENTICATION'
                || String(value ?? '').includes('{{1}}'),
        ),
    },
}));

const templateV$ = useVuelidate(templateRules, templateForm, { $autoDirty: true });

/**
 * Bind a Vuelidate field to the shared feedback + message shape used by the
 * other admin forms.
 */
function fieldUi(vuelidateField, serverKey, value, errors) {
    const serverError = firstError(errors, [serverKey]);
    const feedback = fieldFeedback(vuelidateField, serverError, value);

    return {
        feedback,
        inputClass: {
            'is-invalid': feedback.show && feedback.invalid,
            'is-valid': feedback.show && feedback.valid,
        },
        message: feedback.invalid ? (vuelidateField?.$errors[0]?.$message || serverError) : null,
    };
}

const nameUi = computed(() => fieldUi(v$.value.name, 'name', form.name, serverErrors));
const accessTokenUi = computed(() => fieldUi(v$.value.access_token, 'access_token', form.access_token, serverErrors));
const phoneNumberIdUi = computed(() => fieldUi(v$.value.phone_number_id, 'phone_number_id', form.phone_number_id, serverErrors));
const businessAccountIdUi = computed(() => fieldUi(
    v$.value.business_account_id,
    'business_account_id',
    form.business_account_id,
    serverErrors,
));
const phoneUi = computed(() => fieldUi(v$.value.phone, 'phone_number', form.phone, serverErrors));
const apiVersionUi = computed(() => fieldUi(v$.value.api_version, 'api_version', form.api_version, serverErrors));

const languageUi = computed(() => fieldUi(
    templateV$.value.language_id,
    'language_id',
    templateForm.language_id,
    templateServerErrors,
));
const categoryUi = computed(() => fieldUi(
    templateV$.value.category,
    'category',
    templateForm.category,
    templateServerErrors,
));
const templateNameUi = computed(() => fieldUi(
    templateV$.value.template_name,
    'template_name',
    templateForm.template_name,
    templateServerErrors,
));
const bodyUi = computed(() => fieldUi(templateV$.value.body, 'body', templateForm.body, templateServerErrors));

const selectedCountryPhoneLength = computed(() => {
    const length = Number(phoneCountry.value?.phone_length);

    return Number.isInteger(length) && length > 0 ? length : null;
});

const phonePlaceholder = computed(() => {
    const prefix = String(phoneCountry.value?.phone_starts_with ?? '').trim();
    const length = selectedCountryPhoneLength.value;

    if (! prefix || length === null) {
        return t('sms.whatsapp.phone_number_placeholder');
    }

    const stars = '*'.repeat(Math.max(length - prefix.length, 0));

    return `${prefix}${stars}`;
});

const previewBody = computed(() => {
    return templateForm.body.replace(/\{\{1\}\}/g, '123456');
});

function categoryLabel(category) {
    const option = categoryOptions.value.find(
        (item) => item.value === String(category ?? '').toUpperCase(),
    );

    return option?.label ?? '—';
}

function metaStatusClass(status) {
    switch (status) {
        case 'approved': return 'bg-success-transparent';
        case 'pending': return 'bg-warning-transparent';
        case 'rejected':
        case 'paused':
        case 'disabled': return 'bg-danger-transparent';
        default: return 'bg-secondary-transparent';
    }
}

function metaStatusLabel(status) {
    switch (status) {
        case 'approved': return t('sms.whatsapp.status_approved');
        case 'pending': return t('sms.whatsapp.status_pending');
        case 'rejected': return t('sms.whatsapp.status_rejected');
        case 'paused': return t('sms.whatsapp.status_paused');
        case 'disabled': return t('sms.whatsapp.status_disabled');
        default: return t('sms.whatsapp.status_unknown');
    }
}

const templateStatus = computed(() => whatsapp.value?.test_status ?? null);

const templateStatusClass = computed(() => {
    switch (templateStatus.value) {
        case 'approved': return 'alert-success';
        case 'pending': return 'alert-warning';
        case 'rejected':
        case 'disabled': return 'alert-danger';
        default: return 'alert-secondary';
    }
});

const templateStatusIcon = computed(() => {
    switch (templateStatus.value) {
        case 'approved': return 'ri-checkbox-circle-line';
        case 'pending': return 'ri-time-line';
        case 'rejected':
        case 'disabled': return 'ri-error-warning-line';
        default: return 'ri-question-line';
    }
});

const templateStatusLabel = computed(() => {
    switch (templateStatus.value) {
        case 'approved': return t('sms.whatsapp.status_approved');
        case 'pending': return t('sms.whatsapp.status_pending');
        case 'rejected': return t('sms.whatsapp.status_rejected');
        case 'disabled': return t('sms.whatsapp.status_disabled');
        default: return t('sms.whatsapp.status_unknown');
    }
});

async function loadCountries() {
    loadingCountries.value = true;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/countries/dropdown');
        countries.value = data?.data ?? [];
    } catch {
        countries.value = [];
    } finally {
        loadingCountries.value = false;
    }
}

function onPhoneCountryChange(country) {
    phoneCountry.value = country ?? null;
    phoneDialCode.value = country?.dial_code ?? '';

    // The number and its country must stay in sync, so re-validate the pair.
    v$.value.phone.$touch();
}

function countryById(id) {
    return countries.value.find((country) => Number(country.id) === Number(id)) ?? null;
}

function onCountriesLoaded(list) {
    if (! form.phone_country_id) {
        return;
    }

    phoneCountry.value = (list ?? []).find(
        (country) => Number(country.id) === Number(form.phone_country_id),
    ) ?? phoneCountry.value;
    phoneDialCode.value = phoneCountry.value?.dial_code ?? phoneDialCode.value;
}

async function loadLanguages() {
    try {
        // The admin catalog intentionally has no `languages/dropdown` route
        // (see routes/admin.php), so the shared general endpoint is used.
        const { data } = await adminAxios.get('/api/general/v1/languages/dropdown');
        languages.value = data?.data ?? [];
    } catch {
        languages.value = [];
    }
}

async function loadTemplates() {
    loadingTemplates.value = true;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/whatsapp/templates');
        templates.value = data?.data ?? [];
    } catch {
        templates.value = [];
    } finally {
        loadingTemplates.value = false;
    }
}

function openCreateTemplate() {
    isEditTemplate.value = false;
    editingTemplateId.value = null;
    templateForm.language_id = null;
    templateForm.template_name = '';
    templateForm.category = 'AUTHENTICATION';
    templateForm.body = '';
    templateForm.is_active = false;
    applyApiErrors(templateServerErrors, {});
    templateV$.value.$reset();
    openTemplateModal();
}

function openEditTemplate(tpl) {
    isEditTemplate.value = true;
    editingTemplateId.value = tpl.id;
    templateForm.language_id = tpl.language_id;
    templateForm.template_name = tpl.template_name;
    templateForm.category = tpl.category ?? 'AUTHENTICATION';
    templateForm.body = tpl.body ?? '';
    templateForm.is_active = Boolean(tpl.is_active);
    applyApiErrors(templateServerErrors, {});
    templateV$.value.$reset();
    openTemplateModal();
}

function openTemplateModal() {
    // The element must come from a Vue template ref. Vue 3 does not render
    // `ref` as a DOM attribute, so querying for it never matches.
    if (! templateModalElement.value) {
        return;
    }

    templateModalInstance ??= new window.bootstrap.Modal(templateModalElement.value, { focus: false });
    templateModalInstance.show();
}

function closeTemplateModal() {
    templateModalInstance?.hide();
}

async function saveTemplate() {
    templateV$.value.$touch();

    if (templateV$.value.$invalid) {
        showWarning(t('toast.validation_error'));
        return;
    }

    savingTemplate.value = true;
    applyApiErrors(templateServerErrors, {});

    try {
        const payload = {
            language_id: templateForm.language_id,
            template_name: templateForm.template_name.trim(),
            category: templateForm.category,
            body: templateForm.body,
            is_active: templateForm.is_active,
        };

        const response = isEditTemplate.value && editingTemplateId.value
            ? await adminAxios.put(`/api/admin/v1/whatsapp/templates/${editingTemplateId.value}`, payload)
            : await adminAxios.post('/api/admin/v1/whatsapp/templates', payload);

        showSuccess(extractApiMessage(response, t('sms.whatsapp.template_saved')));
        closeTemplateModal();
        await loadTemplates();
    } catch (error) {
        applyApiErrors(templateServerErrors, error?.response?.data?.errors ?? {});
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        savingTemplate.value = false;
    }
}

async function deleteTemplate(id) {
    try {
        const response = await adminAxios.delete(`/api/admin/v1/whatsapp/templates/${id}`);
        showSuccess(extractApiMessage(response, t('sms.whatsapp.template_deleted')));
        await loadTemplates();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

async function syncTemplate(id) {
    syncingId.value = id;
    try {
        const response = await adminAxios.post(`/api/admin/v1/whatsapp/templates/${id}/sync`);
        showSuccess(extractApiMessage(response, t('sms.whatsapp.template_synced')));
        await loadTemplates();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        syncingId.value = null;
    }
}

async function submitTemplate(id) {
    submittingId.value = id;
    try {
        const response = await adminAxios.post(`/api/admin/v1/whatsapp/templates/${id}/submit`);
        showSuccess(extractApiMessage(response, t('sms.whatsapp.template_submitted')));
        await loadTemplates();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('sms.whatsapp.template_submit_failed')));
    } finally {
        submittingId.value = null;
    }
}

async function importTemplates() {
    importing.value = true;
    try {
        const response = await adminAxios.post('/api/admin/v1/whatsapp/templates/import');
        showSuccess(extractApiMessage(response, t('sms.whatsapp.templates_imported')));
        await loadTemplates();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('sms.whatsapp.template_import_failed')));
    } finally {
        importing.value = false;
    }
}

async function loadWhatsapp() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/whatsapp');

        // A missing row is serialized as an empty array, which must not be
        // treated as an existing configuration.
        whatsapp.value = Array.isArray(data?.data) ? null : (data?.data ?? null);

        if (whatsapp.value) {
            form.name = whatsapp.value.name ?? '';
            // The access token is the only secret and is never returned, so the
            // field stays empty and an empty submission keeps the stored token.
            form.access_token = '';
            hasAccessToken.value = Boolean(whatsapp.value.has_access_token);
            form.phone_number_id = whatsapp.value.phone_number_id ?? '';
            form.phone_country_id = whatsapp.value.phone_country_id ?? null;
            phoneCountry.value = countries.value.find(
                (country) => Number(country.id) === Number(form.phone_country_id),
            ) ?? null;
            phoneDialCode.value = phoneCountry.value?.dial_code ?? '';
            form.phone = splitPhoneNumber(whatsapp.value.phone_number, phoneDialCode.value);
            form.business_account_id = whatsapp.value.business_account_id ?? '';
            form.api_version = whatsapp.value.api_version ?? '';
            form.is_active = Boolean(whatsapp.value.is_active);
            form.countries = Array.isArray(whatsapp.value.countries)
                ? whatsapp.value.countries.map((c) => c.id ?? c)
                : [];
        } else {
            form.name = '';
            form.access_token = '';
            hasAccessToken.value = false;
            form.phone_number_id = '';
            form.phone = '';
            form.phone_country_id = null;
            phoneCountry.value = null;
            phoneDialCode.value = '';
            form.business_account_id = '';
            form.api_version = '';
            form.is_active = false;
            form.countries = [];
        }

        // The values were filled from the server, so any previous client-side
        // errors are stale.
        applyApiErrors(serverErrors, {});
        v$.value.$reset();
    } catch {
        whatsapp.value = null;
    }
}

async function save() {
    v$.value.$touch();

    if (v$.value.$invalid) {
        showWarning(t('toast.validation_error'));
        return;
    }

    saving.value = true;
    applyApiErrors(serverErrors, {});

    try {
        const payload = {
            name: form.name.trim(),
            access_token: form.access_token || undefined,
            phone_number_id: form.phone_number_id.trim() || undefined,
            phone_number: combinedPhone.value || null,
            phone_country_id: form.phone_country_id || null,
            business_account_id: form.business_account_id.trim() || undefined,
            api_version: form.api_version.trim() || undefined,
            is_active: form.is_active,
            countries: form.countries,
        };

        const response = await adminAxios.post('/api/admin/v1/whatsapp', payload);
        showSuccess(extractApiMessage(response, t('sms.whatsapp.saved')));
        await loadWhatsapp();
    } catch (error) {
        applyApiErrors(serverErrors, error?.response?.data?.errors ?? {});
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        saving.value = false;
    }
}

async function testConnection() {
    testing.value = true;
    try {
        const response = await adminAxios.post('/api/admin/v1/whatsapp/test-connection');
        showSuccess(extractApiMessage(response, t('sms.whatsapp.connection_successful')));
        await loadWhatsapp();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('sms.whatsapp.connection_failed')));
    } finally {
        testing.value = false;
    }
}

onMounted(async () => {
    // The stored number is split with the dial code, so the country list must
    // be available before the WhatsApp configuration is read.
    await Promise.all([loadCountries(), loadLanguages()]);
    await loadWhatsapp();
    await loadTemplates();
});
</script>

<style scoped>
.whatsapp-countries-select :deep(.p-multiselect-label) {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    flex-wrap: wrap;
    max-height: 6rem;
    overflow-y: auto;
}

.whatsapp-chip-remove {
    cursor: pointer;
    font-size: 1rem;
    line-height: 1;
}

.whatsapp-preview {
    max-width: 320px;
    margin: 0 auto;
    border: 1px solid #e0e0e0;
    border-radius: 12px;
    overflow: hidden;
    background: #f0f2f5;
}

.whatsapp-preview-header {
    background: #075e54;
    color: #fff;
    padding: 10px 14px;
    font-weight: 600;
    font-size: 0.85rem;
}

.whatsapp-preview-body {
    background: #dcf8c6;
    padding: 12px 14px;
    margin: 8px 8px 0 8px;
    border-radius: 8px;
    font-size: 0.9rem;
    line-height: 1.5;
    white-space: pre-wrap;
    word-break: break-word;
}

.whatsapp-preview-footer {
    background: #dcf8c6;
    padding: 4px 14px 12px 14px;
    margin: 0 8px 8px 8px;
    border-radius: 0 0 8px 8px;
    font-size: 0.75rem;
    color: #667781;
}
</style>
