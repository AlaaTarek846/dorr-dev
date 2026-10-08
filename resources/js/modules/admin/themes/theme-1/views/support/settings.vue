<template>
    <div>
        <WalletPageHeader :title="t('support.settings.title')" :section="t('sidebar.users')" />

        <div class="d-flex justify-content-end mb-3">
            <button type="button" class="btn btn-sm btn-light" :title="t('support.refresh')" :disabled="loading" @click="load">
                <i class="ri-refresh-line" :class="{ 'support-spin': loading }"></i>
            </button>
        </div>

        <!-- the page's own shape while it loads: the intro, the master switch and the section cards -->
        <div v-if="loading" aria-busy="true">
            <Skeleton height="3.25rem" border-radius="0.5rem" class="mb-3" />
            <div class="row g-3 justify-content-center">
                <div class="col-xl-9 ">
                    <div class="card custom-card">
                        <div class="card-body d-flex align-items-center justify-content-between gap-3">
                            <div class="flex-grow-1">
                                <Skeleton width="30%" height="1rem" class="mb-2" />
                                <Skeleton width="55%" height="0.75rem" />
                            </div>
                            <Skeleton width="2.5rem" height="1.4rem" border-radius="1rem" />
                        </div>
                    </div>
                    <div v-for="section in 3" :key="section" class="card custom-card">
                        <div class="card-header"><Skeleton width="12rem" height="1.1rem" /></div>
                        <div class="card-body">
                            <div class="d-flex gap-2 mb-3">
                                <Skeleton width="5.5rem" height="2rem" border-radius="0.5rem" />
                                <Skeleton width="5.5rem" height="2rem" border-radius="0.5rem" />
                            </div>
                            <Skeleton height="6rem" border-radius="0.5rem" class="mb-3" />
                            <div class="row g-3">
                                <div class="col-md-6"><Skeleton height="2.4rem" border-radius="0.5rem" /></div>
                                <div class="col-md-6"><Skeleton height="2.4rem" border-radius="0.5rem" /></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <template v-else-if="form">
            <div class="alert alert-info fs-13 d-flex align-items-start gap-2">
                <i class="ri-robot-2-line fs-5"></i>
                <span>{{ t('support.settings.intro') }}</span>
            </div>

            <div class="row g-3 justify-content-center">
                <div class="col-xl-9">
                    <!-- master switch -->
                    <div class="card custom-card">
                        <div class="card-body d-flex align-items-center justify-content-between gap-3">
                            <div>
                                <div class="fw-semibold">{{ t('support.settings.enabled') }}</div>
                                <div class="text-muted fs-12">{{ t('support.settings.enabled_hint') }}</div>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input id="auto-enabled" v-model="form.auto_reply_enabled" class="form-check-input" type="checkbox" role="switch" :disabled="! canUpdate">
                            </div>
                        </div>
                    </div>

                    <fieldset :disabled="! canUpdate || ! form.auto_reply_enabled" class="settings-fieldset">
                        <!-- acknowledgement -->
                        <WalletSection :title="t('support.settings.ack_title')" icon="ri-mail-check-line">
                            <div class="form-check form-switch mb-3">
                                <input id="ack-enabled" v-model="form.ack_enabled" class="form-check-input" type="checkbox" role="switch">
                                <label class="form-check-label" for="ack-enabled">{{ t('support.settings.ack_enabled') }}</label>
                            </div>
                            <CatalogTranslationTabs :languages="languageList" :active-locale="activeLocale" :translation-tab-class="tabClass" :translation-tab-feedback="tabFeedback" @update:active-locale="activeLocale = $event" />
                            <textarea v-model="form.ack_message[activeLocale]" rows="4" class="form-control" :class="cls(v$.ack_message[activeLocale])" :dir="localeDir" :placeholder="defaults.ack[activeLocale]"></textarea>
                            <div v-if="msg(v$.ack_message[activeLocale])" class="invalid-feedback d-block">{{ msg(v$.ack_message[activeLocale]) }}</div>
                            <div class="text-muted fs-12 mt-2">{{ t('support.settings.vars_ack') }}</div>
                        </WalletSection>

                        <!-- away -->
                        <WalletSection :title="t('support.settings.away_title')" icon="ri-moon-clear-line">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                                <div class="form-check form-switch mb-0">
                                    <input id="away-enabled" v-model="form.away_enabled" class="form-check-input" type="checkbox" role="switch">
                                    <label class="form-check-label" for="away-enabled">{{ t('support.settings.away_enabled') }}</label>
                                </div>
                                <span class="badge fs-12" :class="settings.open_now ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                                    {{ settings.open_now ? t('support.settings.open_now') : t('support.settings.closed_now') }}
                                </span>
                            </div>

                            <CatalogTranslationTabs :languages="languageList" :active-locale="activeLocale" :translation-tab-class="tabClass" :translation-tab-feedback="tabFeedback" @update:active-locale="activeLocale = $event" />
                            <textarea v-model="form.away_message[activeLocale]" rows="4" class="form-control mb-1" :class="cls(v$.away_message[activeLocale])" :dir="localeDir" :placeholder="defaults.away[activeLocale]"></textarea>
                            <div v-if="msg(v$.away_message[activeLocale])" class="invalid-feedback d-block">{{ msg(v$.away_message[activeLocale]) }}</div>
                            <div class="text-muted fs-12 mb-4">{{ t('support.settings.vars_away') }}</div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-8">
                                    <label class="form-label">{{ t('support.settings.timezone') }}</label>
                                    <Select
                                        v-model="form.timezone"
                                        filter
                                        :filter-placeholder="t('search_placeholder')"
                                        :placeholder="t('support.settings.timezone')"
                                        :options="timezones"
                                        :invalid="v$.timezone.$error"
                                        append-to="self"
                                        class="w-100"
                                    />
                                    <div v-if="msg(v$.timezone)" class="invalid-feedback d-block">{{ msg(v$.timezone) }}</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="away-every">{{ t('support.settings.away_every') }}</label>
                                    <div class="input-group">
                                        <input id="away-every" v-model.number="form.away_every_hours" type="number" min="1" max="168" class="form-control" :class="cls(v$.away_every_hours)">
                                        <span class="input-group-text">{{ t('support.settings.hours_unit') }}</span>
                                    </div>
                                    <div v-if="msg(v$.away_every_hours)" class="invalid-feedback d-block">{{ msg(v$.away_every_hours) }}</div>
                                </div>
                            </div>

                            <label class="form-label">{{ t('support.settings.hours') }}</label>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0 settings-hours">
                                    <tbody>
                                        <tr v-for="(day, index) in form.hours" :key="index">
                                            <td style="width: 130px;" class="fw-semibold">{{ dayName(index) }}</td>
                                            <td style="width: 90px;">
                                                <div class="form-check form-switch mb-0">
                                                    <input v-model="day.open" class="form-check-input" type="checkbox" role="switch">
                                                </div>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2" :class="{ 'opacity-50': ! day.open }">
                                                    <AdminDatePicker v-model="day.from" time-only :disabled="! day.open" :invalid="v$.hours[index].from.$error" class="hours-picker" />
                                                    <span class="text-muted">–</span>
                                                    <AdminDatePicker v-model="day.to" time-only :disabled="! day.open" :invalid="v$.hours[index].to.$error" class="hours-picker" />
                                                    <small v-if="msg(v$.hours[index].from) || msg(v$.hours[index].to)" class="text-danger">{{ msg(v$.hours[index].from) || msg(v$.hours[index].to) }}</small>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-muted fs-12 mt-2">{{ t('support.settings.hours_hint') }}</div>
                        </WalletSection>

                        <!-- AI -->
                        <WalletSection :title="t('support.settings.ai_title')" icon="ri-sparkling-2-line">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                                <span class="badge fs-12" :class="settings.ai_available ? 'bg-success-transparent' : 'bg-danger-transparent'">
                                    <i :class="settings.ai_available ? 'ri-check-line' : 'ri-error-warning-line'" class="me-1"></i>
                                    {{ settings.ai_available ? t('support.settings.ai_ready') : t('support.settings.ai_missing') }}
                                </span>
                                <span class="badge bg-primary-transparent fs-12">
                                    <i class="ri-question-answer-line me-1"></i>{{ t('support.settings.faqs_count', { count: settings.faqs_count }) }}
                                </span>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input id="ai-enabled" v-model="form.ai_enabled" class="form-check-input" type="checkbox" role="switch">
                                <label class="form-check-label" for="ai-enabled">{{ t('support.settings.ai_enabled') }}</label>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label" for="ai-max">{{ t('support.settings.ai_max') }}</label>
                                    <input id="ai-max" v-model.number="form.ai_max_replies" type="number" min="0" max="10" class="form-control" :class="cls(v$.ai_max_replies)" :disabled="! form.ai_enabled">
                                    <div v-if="msg(v$.ai_max_replies)" class="invalid-feedback d-block">{{ msg(v$.ai_max_replies) }}</div>
                                </div>
                            </div>
                            <ul class="text-muted fs-12 mt-3 mb-0 ps-3">
                                <li>{{ t('support.settings.ai_rule_faq') }}</li>
                                <li>{{ t('support.settings.ai_rule_person') }}</li>
                                <li>{{ t('support.settings.ai_rule_label') }}</li>
                            </ul>
                        </WalletSection>
                    </fieldset>

                    <div v-if="errorMessage" class="alert alert-danger">{{ errorMessage }}</div>

                    <div v-if="canUpdate" class="d-flex justify-content-end my-3">
                        <button type="button" class="btn btn-primary" :disabled="saving" @click="save">
                            <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save_changes') }}
                        </button>
                    </div>
                </div>

            </div>
        </template>

    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { helpers } from '@vuelidate/validators';
import Select from 'primevue/select';
import Skeleton from 'primevue/skeleton';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import AdminDatePicker from '../../../../../../components/ui/AdminDatePicker.vue';
import CatalogTranslationTabs from '../../../../../../components/catalog/CatalogTranslationTabs.vue';
import { useAvailableLanguagesStore } from '../../../../../../stores/availableLanguages';
import WalletPageHeader from '../../../../../../components/wallet/WalletPageHeader.vue';
import WalletSection from '../../../../../../components/wallet/WalletSection.vue';
import useValidation from '../../../../../../composables/useValidation';
import { usePermission } from '../../../../../../composables/usePermission';
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';

const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();

const { requiredField, maxString, numberRules } = useValidation();

const canUpdate = computed(() => can('support-settings.update'));
const languagesStore = useAvailableLanguagesStore();
// The system's own languages (the same tabs as every translated form); ar / en until they load.
const languageList = computed(() => languagesStore.items.length ? languagesStore.items : [{ code: 'ar', name: 'العربية' }, { code: 'en', name: 'English' }]);
const activeLocale = ref('');
const localeDir = computed(() => ['ar', 'fa', 'ur', 'he'].includes(activeLocale.value) ? 'rtl' : 'ltr');

function tabClass(code) {
    const filled = !! (form.value?.ack_message?.[code] || form.value?.away_message?.[code]);

    return { active: activeLocale.value === code, 'catalog-lang-tab--valid': filled };
}

function tabFeedback() {
    return { show: false, valid: true };
}

const loading = ref(true);
const saving = ref(false);
const errorMessage = ref('');
const settings = ref({});
const form = ref(null);

const defaults = computed(() => settings.value.default_texts ?? { ack: {}, away: {} });
const timezones = computed(() => {
    const zones = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : [];

    // The saved zone is always an option, even where the browser does not list it.
    return form.value && ! zones.includes(form.value.timezone) ? [form.value.timezone, ...zones] : zones;
});

/** Sunday first, like the server's hours. */
function dayName(index) {
    return new Intl.DateTimeFormat(document.documentElement.lang === 'ar' ? 'ar' : 'en', { weekday: 'long' }).format(new Date(2026, 9, 4 + index));
}

function fill(data) {
    settings.value = data;
    form.value = {
        auto_reply_enabled: data.auto_reply_enabled,
        ack_enabled: data.ack_enabled,
        ack_message: { ...data.ack_message },
        away_enabled: data.away_enabled,
        away_message: { ...data.away_message },
        hours: data.hours.map((day) => ({ ...day })),
        timezone: data.timezone,
        away_every_hours: data.away_every_hours,
        ai_enabled: data.ai_enabled,
        ai_max_replies: data.ai_max_replies,
    };
}

async function load() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/support-settings');

        fill(data.data);
        v$.value.$reset();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

async function save() {
    if (! await v$.value.$validate()) {
        // Show the language tab that holds the mistake.
        const bad = languageList.value.find((lang) => v$.value.ack_message[lang.code]?.$error || v$.value.away_message[lang.code]?.$error);

        if (bad) {
            activeLocale.value = bad.code;
        }

        return;
    }

    saving.value = true;
    errorMessage.value = '';

    try {
        const { data } = await adminAxios.put('/api/admin/v1/support-settings', form.value);

        fill(data.data);
        showSuccess(t('support.settings.saved'));
    } catch (error) {
        errorMessage.value = extractApiErrorMessage(error);
    } finally {
        saving.value = false;
    }
}

// ---------------------------------------------------------------- validation (Vuelidate)

const texts = (labelKey) => Object.fromEntries(languageList.value.map((lang) => [lang.code, { max: maxString(labelKey, 1000) }]));

// An open day needs both times (rules below).
const rules = computed(() => ({
    ack_message: texts('support.settings.ack_title'),
    away_message: texts('support.settings.away_title'),
    timezone: { required: requiredField('support.settings.timezone') },
    away_every_hours: {
        required: requiredField('support.settings.away_every'),
        ...numberRules('support.settings.away_every', { min: 1, max: 168, integerOnly: true }),
    },
    ai_max_replies: {
        required: requiredField('support.settings.ai_max'),
        ...numberRules('support.settings.ai_max', { min: 0, max: 10, integerOnly: true }),
    },
    hours: Object.fromEntries((form.value?.hours ?? []).map((day, index) => [index, {
        from: { time: helpers.withMessage(() => t('validation.required', { field: t('support.settings.hours') }), (value) => ! day.open || Boolean(value)) },
        to: { time: helpers.withMessage(() => t('validation.required', { field: t('support.settings.hours') }), (value) => ! day.open || Boolean(value)) },
    }])),
}));

const v$ = useVuelidate(rules, computed(() => form.value ?? {}), { $autoDirty: true, $scope: 'settings' });

const msg = (field) => field?.$errors?.[0]?.$message || '';
const cls = (field) => ({ 'is-invalid': Boolean(field?.$error) });



onMounted(async () => {
    await languagesStore.fetch();
    activeLocale.value = languageList.value[0]?.code ?? 'ar';
    load();
});
</script>

<style scoped>
.support-spin {
    display: inline-block;
    animation: support-spin 0.8s linear infinite;
}

@keyframes support-spin {
    to {
        transform: rotate(360deg);
    }
}

.hours-picker {
    max-width: 150px;
}

.settings-fieldset {
    min-width: 0;
    margin: 0;
    padding: 0;
    border: 0;
}

.settings-fieldset:disabled {
    opacity: 0.6;
}

.settings-hours td {
    border-color: transparent;
}

</style>
