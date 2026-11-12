<template>
    <div ref="modalElement" class="modal fade" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header catalog-modal-header">
                    <div class="d-flex align-items-center justify-content-between w-100 gap-3">
                        <h6 class="modal-title mb-0">{{ modalTitle }}</h6>
                        <button type="button" class="btn-close catalog-modal-close" aria-label="Close" @click="close"></button>
                    </div>
                </div>

                <!-- Long form: header and footer stay, only the body scrolls (catalog-list.css .catalog-scroll-form). -->
                <form v-if="activeLocale" class="catalog-scroll-form" @submit.prevent="submit">
                    <div class="modal-body px-4 pb-2">
                        <!-- Translated text: one tab per stored language (like countries / FAQs) -->
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

                        <div class="row g-3 mb-2">
                            <div class="col-md-8">
                                <label for="plan-name" class="form-label">
                                    {{ t('ai_plans.name') }} <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-text"></i></span>
                                    <input
                                        id="plan-name"
                                        v-model="form.translations[activeLocale].name"
                                        type="text"
                                        maxlength="150"
                                        class="form-control"
                                        :class="fieldInputClass(activeLocale, 'name')"
                                        :placeholder="t('ai_plans.name_placeholder')"
                                        @input="onTranslationInput(activeLocale, 'name')"
                                    >
                                    <FormFieldFeedback v-bind="fieldFeedbackFor(activeLocale, 'name')" />
                                </div>
                                <div v-if="fieldMessage(activeLocale, 'name')" class="invalid-feedback d-block">
                                    {{ fieldMessage(activeLocale, 'name') }}
                                </div>
                            </div>

                            <div class="col-md-4">
                                <label for="plan-badge" class="form-label">{{ t('ai_plans.badge') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-price-tag-3-line"></i></span>
                                    <input
                                        id="plan-badge"
                                        v-model="form.translations[activeLocale].badge"
                                        type="text"
                                        maxlength="60"
                                        class="form-control"
                                        :class="fieldInputClass(activeLocale, 'badge')"
                                        :placeholder="t('ai_plans.badge_placeholder')"
                                        @input="onTranslationInput(activeLocale, 'badge')"
                                    >
                                </div>
                                <div v-if="fieldMessage(activeLocale, 'badge')" class="invalid-feedback d-block">
                                    {{ fieldMessage(activeLocale, 'badge') }}
                                </div>
                            </div>

                            <div class="col-12">
                                <label for="plan-description" class="form-label">{{ t('ai_plans.description') }}</label>
                                <textarea
                                    id="plan-description"
                                    v-model="form.translations[activeLocale].description"
                                    class="form-control"
                                    :class="fieldInputClass(activeLocale, 'description')"
                                    rows="2"
                                    maxlength="1000"
                                    :placeholder="t('ai_plans.description_placeholder')"
                                    @input="onTranslationInput(activeLocale, 'description')"
                                ></textarea>
                                <div v-if="fieldMessage(activeLocale, 'description')" class="invalid-feedback d-block">
                                    {{ fieldMessage(activeLocale, 'description') }}
                                </div>
                            </div>

                            <div class="col-12">
                                <label for="plan-feature-new" class="form-label">
                                    {{ t('ai_plans.features') }}
                                    <span class="text-muted fs-12">({{ activeFeatures.length }}/{{ MAX_FEATURES }})</span>
                                </label>
                                <div class="input-group mb-2">
                                    <span class="input-group-text bg-light"><i class="ri-list-check-2"></i></span>
                                    <input
                                        id="plan-feature-new"
                                        v-model="newFeature"
                                        type="text"
                                        maxlength="150"
                                        class="form-control"
                                        :placeholder="t('ai_plans.features_placeholder')"
                                        :disabled="activeFeatures.length >= MAX_FEATURES"
                                        @keydown.enter.prevent="addFeature"
                                    >
                                    <button type="button" class="btn btn-light border" :disabled="!newFeature.trim() || activeFeatures.length >= MAX_FEATURES" @click="addFeature">
                                        <i class="ri-add-line me-1 align-middle"></i>{{ t('ai_plans.add_feature') }}
                                    </button>
                                </div>
                                <div v-if="activeFeatures.length" class="d-flex flex-wrap gap-1">
                                    <span v-for="(feature, index) in activeFeatures" :key="`${activeLocale}-${index}`" class="badge bg-light text-default border d-flex align-items-center gap-1 fw-normal fs-12">
                                        {{ feature }}
                                        <button type="button" class="btn btn-link p-0 text-muted lh-1" :aria-label="t('ai_plans.remove_feature')" @click="removeFeature(index)">
                                            <i class="ri-close-line"></i>
                                        </button>
                                    </span>
                                </div>
                                <div v-if="featuresMessage" class="invalid-feedback d-block">{{ featuresMessage }}</div>
                            </div>
                        </div>

                        <!-- Usage -->
                        <h6 class="catalog-form-section"><i class="ri-time-line me-1"></i>{{ t('ai_plans.sections.usage') }}</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="plan-code" class="form-label">{{ t('ai_plans.code') }} <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-code-s-slash-line"></i></span>
                                    <input
                                        id="plan-code"
                                        v-model="form.code"
                                        type="text"
                                        maxlength="100"
                                        dir="ltr"
                                        class="form-control"
                                        :class="inputClass('code')"
                                        :placeholder="t('ai_plans.code_placeholder')"
                                        @input="onFieldInput('code')"
                                    >
                                    <FormFieldFeedback v-bind="feedbackFor('code')" />
                                </div>
                                <div v-if="messageFor('code')" class="invalid-feedback d-block">{{ messageFor('code') }}</div>
                            </div>

                            <div class="col-md-4">
                                <label for="plan-usage-minutes" class="form-label">{{ t('ai_plans.usage_minutes') }} <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-timer-line"></i></span>
                                    <input
                                        id="plan-usage-minutes"
                                        v-model.number="form.usage_minutes"
                                        type="number"
                                        min="0"
                                        class="form-control"
                                        :class="inputClass('usage_minutes')"
                                        @input="onFieldInput('usage_minutes')"
                                    >
                                    <FormFieldFeedback v-bind="feedbackFor('usage_minutes')" />
                                </div>
                                <div v-if="messageFor('usage_minutes')" class="invalid-feedback d-block">{{ messageFor('usage_minutes') }}</div>
                                <div v-else class="form-text">{{ t('ai_plans.usage_minutes_hint') }}</div>
                            </div>

                            <div class="col-md-4">
                                <label for="plan-cooldown" class="form-label">{{ t('ai_plans.cooldown_minutes') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-hourglass-line"></i></span>
                                    <input id="plan-cooldown" v-model.number="form.cooldown_minutes" type="number" min="0" class="form-control" :class="inputClass('cooldown_minutes')" @input="onFieldInput('cooldown_minutes')">
                                </div>
                                <div v-if="messageFor('cooldown_minutes')" class="invalid-feedback d-block">{{ messageFor('cooldown_minutes') }}</div>
                            </div>
                        </div>

                        <!-- Media -->
                        <h6 class="catalog-form-section"><i class="ri-image-line me-1"></i>{{ t('ai_plans.sections.media') }}</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="plan-image-daily" class="form-label">{{ t('ai_plans.image_daily_limit') }}</label>
                                <input id="plan-image-daily" v-model.number="form.image_daily_limit" type="number" min="0" class="form-control" :class="inputClass('image_daily_limit')" :placeholder="t('ai_plans.image_daily_limit_placeholder')" @input="onFieldInput('image_daily_limit')">
                                <div v-if="messageFor('image_daily_limit')" class="invalid-feedback d-block">{{ messageFor('image_daily_limit') }}</div>
                                <div v-else class="form-text">{{ t('ai_plans.image_daily_limit_hint') }}</div>
                            </div>
                            <div class="col-md-4">
                                <label for="plan-video-daily" class="form-label">{{ t('ai_plans.video_daily_limit') }}</label>
                                <input id="plan-video-daily" v-model.number="form.video_daily_limit" type="number" min="0" class="form-control" :class="inputClass('video_daily_limit')" @input="onFieldInput('video_daily_limit')">
                                <div v-if="messageFor('video_daily_limit')" class="invalid-feedback d-block">{{ messageFor('video_daily_limit') }}</div>
                            </div>
                            <div class="col-md-4">
                                <label for="plan-video-seconds" class="form-label">{{ t('ai_plans.video_max_seconds') }}</label>
                                <input id="plan-video-seconds" v-model.number="form.video_max_seconds" type="number" min="0" max="600" class="form-control" :class="inputClass('video_max_seconds')" @input="onFieldInput('video_max_seconds')">
                                <div v-if="messageFor('video_max_seconds')" class="invalid-feedback d-block">{{ messageFor('video_max_seconds') }}</div>
                            </div>
                            <div class="col-12 mt-1"><div class="form-text mt-0">{{ t('ai_plans.video_hint') }}</div></div>
                        </div>

                        <!-- Sites -->
                        <h6 class="catalog-form-section"><i class="ri-global-line me-1"></i>{{ t('ai_plans.sections.sites') }}</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="plan-site-projects" class="form-label">{{ t('ai_plans.site_projects_limit') }}</label>
                                <input id="plan-site-projects" v-model.number="form.site_projects_limit" type="number" min="0" class="form-control" :class="inputClass('site_projects_limit')" @input="onFieldInput('site_projects_limit')">
                                <div v-if="messageFor('site_projects_limit')" class="invalid-feedback d-block">{{ messageFor('site_projects_limit') }}</div>
                            </div>
                            <div class="col-md-6">
                                <label for="plan-site-daily" class="form-label">{{ t('ai_plans.site_daily_generations') }}</label>
                                <input id="plan-site-daily" v-model.number="form.site_daily_generations" type="number" min="0" class="form-control" :class="inputClass('site_daily_generations')" @input="onFieldInput('site_daily_generations')">
                                <div v-if="messageFor('site_daily_generations')" class="invalid-feedback d-block">{{ messageFor('site_daily_generations') }}</div>
                            </div>
                            <div class="col-12 mt-1"><div class="form-text mt-0">{{ t('ai_plans.site_hint') }}</div></div>
                        </div>

                        <!-- Price & duration -->
                        <h6 class="catalog-form-section"><i class="ri-money-dollar-circle-line me-1"></i>{{ t('ai_plans.sections.pricing') }}</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="plan-price" class="form-label">{{ t('ai_plans.price') }}</label>
                                <input id="plan-price" v-model.number="form.price" type="number" min="0" step="0.01" class="form-control" :class="inputClass('price')" @input="onPriceInput">
                                <div v-if="messageFor('price')" class="invalid-feedback d-block">{{ messageFor('price') }}</div>
                            </div>
                            <div class="col-md-4">
                                <label for="plan-discount-percent" class="form-label">{{ t('ai_plans.discount_percent') }}</label>
                                <div class="input-group">
                                    <input id="plan-discount-percent" v-model.number="discountPercent" type="number" min="0" max="99" step="1" class="form-control" @input="onDiscountInput">
                                    <span class="input-group-text bg-light">%</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="plan-original-price" class="form-label">{{ t('ai_plans.original_price') }}</label>
                                <input id="plan-original-price" v-model.number="form.original_price" type="number" min="0" step="0.01" class="form-control" :class="inputClass('original_price')" @input="onOriginalPriceInput">
                                <div v-if="messageFor('original_price')" class="invalid-feedback d-block">{{ messageFor('original_price') }}</div>
                            </div>
                            <div class="col-12 mt-1"><div class="form-text mt-0">{{ t('ai_plans.discount_percent_hint') }}</div></div>
                            <div class="col-md-6">
                                <CurrencySelect
                                    v-model="form.currency_id"
                                    input-id="plan-currency"
                                    :label="t('ai_plans.currency')"
                                    :placeholder="t('ai_plans.currency_placeholder')"
                                    :error="serverErrors.currency_id?.[0]"
                                    :show="show"
                                    :load-on-show="true"
                                    append-to="body"
                                    @update:model-value="onFieldInput('currency_id')"
                                />
                            </div>
                            <div class="col-md-6">
                                <label for="plan-duration-days" class="form-label">{{ t('ai_plans.duration_days') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="ri-calendar-line"></i></span>
                                    <input id="plan-duration-days" v-model.number="form.duration_days" type="number" min="1" max="3650" class="form-control" :class="inputClass('duration_days')" @input="onFieldInput('duration_days')">
                                    <span class="input-group-text bg-light">{{ t('ai_plans.days_suffix') }}</span>
                                </div>
                                <div v-if="messageFor('duration_days')" class="invalid-feedback d-block">{{ messageFor('duration_days') }}</div>
                            </div>
                        </div>

                        <!-- Display -->
                        <h6 class="catalog-form-section"><i class="ri-eye-line me-1"></i>{{ t('ai_plans.sections.display') }}</h6>
                        <div class="row g-3 mb-2">
                            <div class="col-md-3">
                                <label for="plan-sort-order" class="form-label">{{ t('ai_plans.sort_order') }}</label>
                                <input id="plan-sort-order" v-model.number="form.sort_order" type="number" min="0" class="form-control" :class="inputClass('sort_order')" @input="onFieldInput('sort_order')">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label d-block mb-2">{{ t('ai_plans.is_featured') }}</label>
                                <div class="toggle toggle-primary mb-0 catalog-modal-toggle" :class="{ on: form.is_featured }" role="switch" tabindex="0" :aria-checked="form.is_featured" @click="form.is_featured = !form.is_featured" @keydown.enter.space.prevent="form.is_featured = !form.is_featured">
                                    <span></span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label d-block mb-2">{{ t('ai_plans.is_trial') }}</label>
                                <div class="toggle toggle-warning mb-0 catalog-modal-toggle" :class="{ on: form.is_trial }" role="switch" tabindex="0" :aria-checked="form.is_trial" @click="form.is_trial = !form.is_trial" @keydown.enter.space.prevent="form.is_trial = !form.is_trial">
                                    <span></span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label d-block mb-2">{{ t('ai_plans.status') }}</label>
                                <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="{ on: form.status }" role="switch" tabindex="0" :aria-checked="form.status" @click="form.status = !form.status" @keydown.enter.space.prevent="form.status = !form.status">
                                    <span></span>
                                </div>
                            </div>
                            <div v-if="form.is_trial" class="col-12">
                                <div class="alert alert-warning py-2 px-3 mb-0 fs-12">
                                    <i class="ri-information-line me-1"></i>{{ t('ai_plans.trial_hint') }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">
                            {{ submitting ? t('ai_plans.saving') : t('save_changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { helpers, integer, maxValue, minValue } from '@vuelidate/validators';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import CatalogTranslationTabs from '../../../../../../components/catalog/CatalogTranslationTabs.vue';
import CurrencySelect from '../../../../../../components/catalog/CurrencySelect.vue';
import FormFieldFeedback from '../../../../../../components/ui/FormFieldFeedback.vue';
import useCatalogTranslationFields from '../../../../../../composables/useCatalogTranslationFields';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import useValidation from '../../../../../../composables/useValidation';
import { setupCatalogModalWatcher } from '../../../../../../utils/catalog';

const RESOURCE_URI = '/api/admin/v1/ai-plans';
const MAX_FEATURES = 12;

const props = defineProps({
    show: { type: Boolean, default: false },
    type: { type: String, default: 'create' },
    record: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved']);

const { t, locale } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const { stringFieldRules, requiredField, applyApiErrors, fieldFeedback, firstError } = useValidation();

const modalElement = ref(null);
const submitting = ref(false);
const serverErrors = reactive({});
let modalInstance = null;
let v$;

const isEdit = computed(() => props.type === 'edit');

const DEFAULTS = {
    code: '',
    usage_minutes: 0,
    cooldown_minutes: 0,
    image_daily_limit: null,
    video_daily_limit: 0,
    video_max_seconds: 0,
    site_projects_limit: 0,
    site_daily_generations: 0,
    duration_days: 30,
    price: 0,
    original_price: null,
    currency_id: null,
    is_featured: false,
    is_trial: false,
    status: true,
    sort_order: 0,
};

const form = reactive({ ...DEFAULTS, translations: {} });

// Features are a list per language, kept beside the text fields the composable manages.
const features = reactive({});
const newFeature = ref('');

const {
    activeLocale,
    storableLanguages,
    localeOrder,
    translationRules,
    ensureLanguagesLoaded,
    fieldFeedbackFor,
    fieldInputClass,
    fieldMessage,
    translationTabFeedback,
    translationTabClass,
    translationsGroupMessage,
    onTranslationInput,
    resetTranslations,
    fillTranslations,
    buildTranslationsPayload,
    focusInvalidTranslationTab,
} = useCatalogTranslationFields({
    form,
    serverErrors,
    fields: [
        { name: 'name', labelKey: 'ai_plans.name', max: 150, min: 2 },
        { name: 'badge', labelKey: 'ai_plans.badge', max: 60, required: false },
        { name: 'description', labelKey: 'ai_plans.description', max: 1000, required: false },
    ],
    getV$: () => v$.value,
});

function numberRules(labelKey, { required = false, min = 0, max = null } = {}) {
    const label = () => t(labelKey);
    const rules = {
        integer: helpers.withMessage(() => t('validation.integer', { field: label() }), integer),
        minValue: helpers.withMessage(() => t('validation.min.numeric', { field: label(), min }), minValue(min)),
    };

    if (required) {
        rules.required = requiredField(labelKey);
    }

    if (max !== null) {
        rules.maxValue = helpers.withMessage(() => t('validation.max.numeric', { field: label(), max }), maxValue(max));
    }

    return rules;
}

const rules = computed(() => ({
    translations: translationRules.value,
    code: stringFieldRules('ai_plans.code', 100),
    usage_minutes: numberRules('ai_plans.usage_minutes', { required: true }),
    cooldown_minutes: numberRules('ai_plans.cooldown_minutes'),
    image_daily_limit: numberRules('ai_plans.image_daily_limit', { max: 100000 }),
    video_daily_limit: numberRules('ai_plans.video_daily_limit', { max: 100000 }),
    video_max_seconds: numberRules('ai_plans.video_max_seconds', { max: 600 }),
    site_projects_limit: numberRules('ai_plans.site_projects_limit', { max: 1000 }),
    site_daily_generations: numberRules('ai_plans.site_daily_generations', { max: 1000 }),
    duration_days: numberRules('ai_plans.duration_days', { min: 1, max: 3650 }),
    sort_order: numberRules('ai_plans.sort_order'),
    price: { minValue: helpers.withMessage(() => t('validation.min.numeric', { field: t('ai_plans.price'), min: 0 }), minValue(0)) },
    original_price: { minValue: helpers.withMessage(() => t('validation.min.numeric', { field: t('ai_plans.original_price'), min: 0 }), minValue(0)) },
}));

v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t('ai_plans.create_title');
    }

    const record = props.record;
    const name = record?.translations?.find((item) => item.locale === locale.value)?.name || record?.name || '';

    return record?.id ? `${t('ai_plans.edit_title')} #${record.id} ${name}`.trim() : t('ai_plans.edit_title');
});

// --- plain fields: same feedback as the countries form -----------------------------------------

function feedbackFor(field) {
    return fieldFeedback(v$.value[field], serverErrors[field]?.[0], form[field]);
}

function inputClass(field) {
    const feedback = feedbackFor(field);

    return { 'is-invalid': feedback.show && feedback.invalid, 'is-valid': feedback.show && feedback.valid && field === 'code' };
}

function messageFor(field) {
    if (! feedbackFor(field).invalid) {
        return null;
    }

    return v$.value[field]?.$errors?.[0]?.$message || serverErrors[field]?.[0] || null;
}

function onFieldInput(field) {
    delete serverErrors[field];
    v$.value[field]?.$touch();
}

// --- features (per language) --------------------------------------------------------------------

const activeFeatures = computed(() => features[activeLocale.value] ?? []);

const featuresMessage = computed(() => {
    const index = localeOrder.value.indexOf(activeLocale.value);

    return firstError(serverErrors, [
        `translations.${index}.features`,
        ...activeFeatures.value.map((_, i) => `translations.${index}.features.${i}`),
    ]);
});

function addFeature() {
    const value = newFeature.value.trim();

    if (! value || activeFeatures.value.length >= MAX_FEATURES) {
        return;
    }

    features[activeLocale.value] = [...activeFeatures.value, value];
    newFeature.value = '';
}

function removeFeature(index) {
    features[activeLocale.value] = activeFeatures.value.filter((_, i) => i !== index);
}

function resetFeatures(record = null) {
    for (const code of localeOrder.value) {
        const translation = record?.translations?.find((item) => item.locale === code);
        features[code] = Array.isArray(translation?.features) ? [...translation.features] : [];
    }

    newFeature.value = '';
}

// --- price <-> original price <-> discount % (not stored; AiPlan::discountPercent() derives it) ----
// anchorField = the real price field the admin typed last, so editing the % recomputes the OTHER one.

const discountPercent = ref(null);
const anchorField = ref(null);

function round2(value) {
    return Math.round(value * 100) / 100;
}

function recomputeFromDiscount() {
    const pct = Number(discountPercent.value);

    if (! Number.isFinite(pct) || pct <= 0 || pct >= 100) {
        return;
    }

    if (anchorField.value === 'price' && Number(form.price) > 0) {
        form.original_price = round2(Number(form.price) / (1 - pct / 100));
    } else if (anchorField.value === 'original_price' && Number(form.original_price) > 0) {
        form.price = round2(Number(form.original_price) * (1 - pct / 100));
    }
}

function onPriceInput() {
    onFieldInput('price');
    anchorField.value = 'price';
    recomputeFromDiscount();
}

function onOriginalPriceInput() {
    onFieldInput('original_price');
    anchorField.value = 'original_price';
    recomputeFromDiscount();
}

function onDiscountInput() {
    recomputeFromDiscount();
}

// --- fill / reset / payload -------------------------------------------------------------------

function resetValidation() {
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function resetForm() {
    Object.assign(form, DEFAULTS);
    resetTranslations();
    resetFeatures();
    discountPercent.value = null;
    anchorField.value = null;
    resetValidation();
}

function fillForm(record) {
    for (const key of Object.keys(DEFAULTS)) {
        form[key] = record?.[key] ?? DEFAULTS[key];
    }

    form.is_featured = Boolean(record?.is_featured);
    form.is_trial = Boolean(record?.is_trial);
    form.status = Boolean(record?.status ?? true);
    fillTranslations(record);
    resetFeatures(record);

    // Show the current discount and anchor on the charged price, so touching % alone changes original_price.
    const price = Number(form.price);
    const original = Number(form.original_price);
    discountPercent.value = original > price && price > 0 ? Math.round((1 - price / original) * 100) : null;
    anchorField.value = price > 0 ? 'price' : (original > 0 ? 'original_price' : null);

    resetValidation();
}

function emptyToNull(value) {
    return value === '' || value === undefined ? null : value;
}

function buildPayload() {
    return {
        code: String(form.code ?? '').trim(),
        usage_minutes: form.usage_minutes,
        cooldown_minutes: emptyToNull(form.cooldown_minutes) ?? 0,
        image_daily_limit: emptyToNull(form.image_daily_limit),
        video_daily_limit: emptyToNull(form.video_daily_limit),
        video_max_seconds: emptyToNull(form.video_max_seconds),
        site_projects_limit: emptyToNull(form.site_projects_limit),
        site_daily_generations: emptyToNull(form.site_daily_generations),
        duration_days: emptyToNull(form.duration_days),
        price: emptyToNull(form.price) ?? 0,
        original_price: emptyToNull(form.original_price),
        currency_id: form.currency_id,
        is_featured: form.is_featured,
        is_trial: form.is_trial,
        status: form.status,
        sort_order: emptyToNull(form.sort_order) ?? 0,
        translations: buildTranslationsPayload().map((translation) => ({
            ...translation,
            badge: translation.badge || null,
            description: translation.description || null,
            features: features[translation.locale] ?? [],
        })),
    };
}

// --- modal ------------------------------------------------------------------------------------

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

    submitting.value = true;
    applyApiErrors(serverErrors, {});

    try {
        const response = isEdit.value && props.record?.id
            ? await adminAxios.put(`${RESOURCE_URI}/${props.record.id}`, buildPayload())
            : await adminAxios.post(RESOURCE_URI, buildPayload());

        showSuccess(extractApiMessage(response, t(isEdit.value ? 'toast.updated' : 'toast.created')));
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
    resourceUri: RESOURCE_URI,
    onOpen: ensureLanguagesLoaded,
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

.catalog-form-section {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--text-muted, #6c757d);
    margin: 1.5rem 0 0.75rem;
    padding-top: 1rem;
    border-top: 1px dashed var(--default-border, #dee2e6);
}
</style>
