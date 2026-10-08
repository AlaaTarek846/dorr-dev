<template>
    <div>
        <WalletPageHeader :title="t('support.settings.title')" :section="t('sidebar.users')" />

        <div v-if="loading" class="text-center py-5"><span class="spinner-border"></span></div>

        <template v-else-if="form">
            <div class="alert alert-info fs-13 d-flex align-items-start gap-2">
                <i class="ri-robot-2-line fs-5"></i>
                <span>{{ t('support.settings.intro') }}</span>
            </div>

            <div class="row g-3">
                <div class="col-xl-8">
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

                <!-- quick replies -->
                <div class="col-xl-4">
                    <div class="card custom-card">
                        <div class="card-header d-flex align-items-center justify-content-between">
                            <div class="card-title"><i class="ri-flashlight-line me-1"></i>{{ t('support.settings.quick_title') }}</div>
                            <button v-if="canUpdate" type="button" class="btn btn-sm btn-primary" @click="openReply()">
                                <i class="ri-add-line me-1"></i>{{ t('support.settings.quick_add') }}
                            </button>
                        </div>
                        <div class="card-body">
                            <p class="text-muted fs-12">{{ t('support.settings.quick_hint') }}</p>
                            <div v-if="! quickReplies.length" class="text-center text-muted py-4">{{ t('support.settings.quick_empty') }}</div>
                            <div v-for="reply in quickReplies" :key="reply.id" class="quick-row" :class="{ 'opacity-50': ! reply.status }">
                                <div class="min-w-0">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-primary-transparent" dir="ltr">/{{ reply.shortcut }}</span>
                                        <strong class="text-truncate">{{ reply.title }}</strong>
                                    </div>
                                    <div class="text-muted fs-12 quick-body">{{ reply.body }}</div>
                                </div>
                                <div v-if="canUpdate" class="btn-list flex-shrink-0">
                                    <button type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('support.settings.quick_edit')" @click="openReply(reply)"><i class="ri-pencil-line"></i></button>
                                    <button type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('support.settings.quick_delete')" @click="removeReply(reply)"><i class="ri-delete-bin-line"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <WalletModal :show="replyModal" :title="replyForm.id ? t('support.settings.quick_edit') : t('support.settings.quick_add')" size="md" @close="replyModal = false">
            <div class="d-flex flex-column gap-3">
                <div>
                    <label class="form-label" for="qr-shortcut">{{ t('support.settings.quick_shortcut') }}</label>
                    <div class="input-group" dir="ltr">
                        <span class="input-group-text">/</span>
                        <input id="qr-shortcut" v-model="replyForm.shortcut" type="text" class="form-control" :class="cls(rv$.shortcut)" placeholder="refund">
                    </div>
                    <div v-if="msg(rv$.shortcut)" class="invalid-feedback d-block">{{ msg(rv$.shortcut) }}</div>
                </div>
                <div>
                    <label class="form-label" for="qr-title">{{ t('support.settings.quick_name') }}</label>
                    <input id="qr-title" v-model="replyForm.title" type="text" class="form-control" :class="cls(rv$.title)">
                    <div v-if="msg(rv$.title)" class="invalid-feedback d-block">{{ msg(rv$.title) }}</div>
                </div>
                <div>
                    <label class="form-label" for="qr-body">{{ t('support.settings.quick_text') }}</label>
                    <textarea id="qr-body" v-model="replyForm.body" rows="5" class="form-control" :class="cls(rv$.body)"></textarea>
                    <div v-if="msg(rv$.body)" class="invalid-feedback d-block">{{ msg(rv$.body) }}</div>
                </div>
                <div class="form-check form-switch">
                    <input id="qr-status" v-model="replyForm.status" class="form-check-input" type="checkbox" role="switch">
                    <label class="form-check-label" for="qr-status">{{ t('wallet.common.active') }}</label>
                </div>
                <div v-if="replyError" class="text-danger fs-12">{{ replyError }}</div>
            </div>
            <template #footer>
                <button type="button" class="btn btn-light" @click="replyModal = false">{{ t('close') }}</button>
                <button type="button" class="btn btn-primary" :disabled="savingReply" @click="saveReply">
                    <span v-if="savingReply" class="spinner-border spinner-border-sm me-1"></span>{{ t('save_changes') }}
                </button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { helpers } from '@vuelidate/validators';
import Select from 'primevue/select';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import AdminDatePicker from '../../../../../../components/ui/AdminDatePicker.vue';
import CatalogTranslationTabs from '../../../../../../components/catalog/CatalogTranslationTabs.vue';
import { useAvailableLanguagesStore } from '../../../../../../stores/availableLanguages';
import WalletModal from '../../../../../../components/wallet/WalletModal.vue';
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
const quickReplies = ref([]);

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
        const [settingsResponse, repliesResponse] = await Promise.all([
            adminAxios.get('/api/admin/v1/support-settings'),
            adminAxios.get('/api/admin/v1/support-settings/quick-replies'),
        ]);

        fill(settingsResponse.data.data);
        quickReplies.value = repliesResponse.data.data ?? [];
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

// ---------------------------------------------------------------- quick replies

const replyModal = ref(false);
const savingReply = ref(false);
const replyError = ref('');
const replyForm = reactive({ id: null, shortcut: '', title: '', body: '', status: true });

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

const replyRules = computed(() => ({
    shortcut: {
        required: requiredField('support.settings.quick_shortcut'),
        max: maxString('support.settings.quick_shortcut', 40),
        format: helpers.withMessage(
            () => t('validation.regex', { field: t('support.settings.quick_shortcut') }),
            (value) => ! value || /^[\p{L}\p{N}_-]+$/u.test(String(value).replace(/^\//, '')),
        ),
    },
    title: { required: requiredField('support.settings.quick_name'), max: maxString('support.settings.quick_name', 120) },
    body: { required: requiredField('support.settings.quick_text'), max: maxString('support.settings.quick_text', 4000) },
}));

const rv$ = useVuelidate(replyRules, replyForm, { $autoDirty: true, $scope: 'reply' });

const msg = (field) => field?.$errors?.[0]?.$message || '';
const cls = (field) => ({ 'is-invalid': Boolean(field?.$error) });



function openReply(reply = null) {
    Object.assign(replyForm, reply
        ? { id: reply.id, shortcut: reply.shortcut, title: reply.title, body: reply.body, status: Boolean(reply.status) }
        : { id: null, shortcut: '', title: '', body: '', status: true });
    replyError.value = '';
    rv$.value.$reset();
    replyModal.value = true;
}

async function saveReply() {
    if (! await rv$.value.$validate()) {
        return;
    }

    savingReply.value = true;
    replyError.value = '';

    try {
        const payload = { shortcut: replyForm.shortcut, title: replyForm.title, body: replyForm.body, status: replyForm.status };

        if (replyForm.id) {
            await adminAxios.put(`/api/admin/v1/support-settings/quick-replies/${replyForm.id}`, payload);
        } else {
            await adminAxios.post('/api/admin/v1/support-settings/quick-replies', payload);
        }

        replyModal.value = false;
        quickReplies.value = (await adminAxios.get('/api/admin/v1/support-settings/quick-replies')).data.data ?? [];
        showSuccess(t('support.settings.saved'));
    } catch (error) {
        replyError.value = extractApiErrorMessage(error);
    } finally {
        savingReply.value = false;
    }
}

async function removeReply(reply) {
    if (! window.confirm(t('support.settings.quick_confirm_delete', { name: reply.title }))) {
        return;
    }

    try {
        await adminAxios.delete(`/api/admin/v1/support-settings/quick-replies/${reply.id}`);
        quickReplies.value = quickReplies.value.filter((item) => item.id !== reply.id);
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

onMounted(async () => {
    await languagesStore.fetch();
    activeLocale.value = languageList.value[0]?.code ?? 'ar';
    load();
});
</script>

<style scoped>
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

.quick-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.75rem 0;
    border-top: 1px solid var(--default-border, #dee2e6);
}

.quick-body {
    display: -webkit-box;
    overflow: hidden;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    line-clamp: 2;
}
</style>
