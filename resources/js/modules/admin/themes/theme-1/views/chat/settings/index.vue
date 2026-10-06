<template>
    <div>
        <WalletPageHeader :title="t('chat.settings.title')" :section="t('sidebar.chat')" />

        <div class="alert alert-info fs-13">{{ t('chat.settings.intro') }}</div>

        <div v-if="loading" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></div>

        <form v-else id="chat-settings-form" @submit.prevent="save">
            <div class="row g-3">
                <div v-for="section in sections" :key="section.key" class="col-xl-6">
                    <div class="card custom-card h-100 mb-0">
                        <div class="card-header py-3 d-flex align-items-center gap-2">
                            <span class="avatar avatar-sm avatar-rounded bg-primary-transparent"><i :class="section.icon"></i></span>
                            <div class="card-title mb-0">{{ t(`chat.settings.section_${section.key}`) }}</div>
                            <div v-if="section.toggle" class="form-check form-switch ms-auto mb-0">
                                <input :id="`cs-${section.toggle}`" v-model="form[section.toggle]" class="form-check-input" type="checkbox" :disabled="!canUpdate">
                                <label class="form-check-label" :for="`cs-${section.toggle}`">{{ t(`chat.settings.${section.toggle}`) }}</label>
                            </div>
                        </div>
                        <div class="card-body">
                            <p v-if="section.note" class="text-muted fs-12 mb-0">{{ t(`chat.settings.note_${section.key}`) }}</p>
                            <div class="row g-3">
                                <div v-for="f in section.fields" :key="f.key" class="col-sm-6">
                                    <label class="form-label fs-13">{{ t(`chat.settings.${f.key}`) }}</label>
                                    <div class="input-group">
                                        <input v-model.number="form[f.key]" type="number" :min="f.min" :max="f.max" dir="ltr" class="form-control" :class="{ 'is-invalid': errors[f.key] }" :disabled="!canUpdate || (section.toggle && !form[section.toggle])">
                                        <span v-if="f.unit" class="input-group-text fs-12">{{ t(`chat.settings.unit_${f.unit}`) }}</span>
                                    </div>
                                    <div v-if="errors[f.key]" class="text-danger fs-12 mt-1">{{ errors[f.key] }}</div>
                                    <div v-else-if="f.hint" class="text-muted fs-11 mt-1">{{ t(`chat.settings.hint_${f.key}`) }}</div>
                                </div>
                                <div v-if="section.countries" class="col-12">
                                    <label class="form-label fs-13">{{ t(`chat.settings.${section.countries}`) }}</label>
                                    <MultiSelect
                                        v-model="form[section.countries]"
                                        :options="countries"
                                        option-label="name"
                                        option-value="id"
                                        filter
                                        display="chip"
                                        class="w-100"
                                        :placeholder="t('chat.settings.countries_placeholder')"
                                        :loading="loadingCountries"
                                        :disabled="!canUpdate || !form[section.toggle]"
                                    />
                                    <div v-if="errors[section.countries]" class="text-danger fs-12 mt-1">{{ errors[section.countries] }}</div>
                                    <div v-else class="text-muted fs-11 mt-1">{{ t(`chat.settings.hint_${section.countries}`) }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="errors.general" class="text-danger mt-3 fs-13">{{ errors.general }}</div>

            <div v-if="canUpdate" class="d-flex justify-content-end gap-2 mt-3 mb-4">
                <button type="button" class="btn btn-light" :disabled="saving || !dirty" @click="reset">{{ t('cancel') }}</button>
                <button type="submit" class="btn btn-primary" :disabled="saving || !dirty">
                    <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save_changes') }}
                </button>
            </div>
        </form>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MultiSelect from 'primevue/multiselect';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();

const canUpdate = computed(() => can('chat-settings.update'));

/** Same bounds as ChatSettingController::update. */
const sections = [
    {
        key: 'messages',
        icon: 'ri-chat-3-line',
        fields: [
            { key: 'max_file_size_mb', min: 1, max: 2048, unit: 'mb' },
            { key: 'edit_window_minutes', min: 0, max: 10080, unit: 'minutes', hint: true },
            { key: 'delete_for_everyone_window_minutes', min: 0, max: 43200, unit: 'minutes', hint: true },
            { key: 'deleted_message_retention_days', min: 0, max: 365, unit: 'days', hint: true },
            { key: 'max_pinned_messages', min: 1, max: 20 },
            { key: 'max_forward_targets', min: 1, max: 50 },
        ],
    },
    {
        key: 'groups',
        icon: 'ri-group-line',
        fields: [
            { key: 'max_group_members', min: 2, max: 5000, unit: 'members' },
            { key: 'max_folders', min: 0, max: 50 },
        ],
    },
    {
        key: 'stories',
        icon: 'ri-donut-chart-line',
        toggle: 'stories_enabled',
        fields: [
            { key: 'story_duration_hours', min: 1, max: 168, unit: 'hours' },
            { key: 'story_video_max_seconds', min: 5, max: 600, unit: 'seconds' },
        ],
    },
    {
        // Public stories on the app's home page: on/off, and how many one person may have running for free.
        key: 'public_stories',
        icon: 'ri-earth-line',
        toggle: 'public_stories_enabled',
        note: true,
        fields: [
            { key: 'public_stories_free', min: 0, max: 20, hint: true },
        ],
    },
    {
        // DORR Moments: occasions, greeting cards and their effects (the chat keeps working when off).
        key: 'moments',
        icon: 'ri-cake-2-line',
        toggle: 'moments_enabled',
        note: true,
        fields: [],
    },
    {
        // DORR Calendar & DORR Today: appointments, today's page and their reminders (the chat keeps working when off).
        key: 'calendar',
        icon: 'ri-calendar-event-line',
        toggle: 'calendar_enabled',
        note: true,
        fields: [],
    },
    {
        key: 'calls',
        icon: 'ri-phone-line',
        toggle: 'calls_enabled',
        // Calls off in some countries only (internet calls need a licence in some places).
        countries: 'calls_disabled_countries',
        fields: [
            { key: 'max_call_participants', min: 2, max: 100, unit: 'members' },
        ],
    },
    {
        key: 'ai',
        icon: 'ri-sparkling-line',
        toggle: 'ai_enabled',
        note: true,
        fields: [],
    },
];

const loading = ref(true);
const saving = ref(false);
const form = reactive({});
const errors = reactive({});
const original = ref({});
const countries = ref([]);
const loadingCountries = ref(false);

/** Lists (the countries) compare by content, not by reference. */
const same = (a, b) => (Array.isArray(a) || Array.isArray(b)
    ? JSON.stringify([...(a ?? [])].sort()) === JSON.stringify([...(b ?? [])].sort())
    : a === b);

const dirty = computed(() => Object.keys(original.value).some((key) => !same(original.value[key], form[key])));

function fill(data) {
    const copy = { ...data, calls_disabled_countries: [...(data.calls_disabled_countries ?? [])] };
    original.value = copy;
    Object.assign(form, { ...copy, calls_disabled_countries: [...copy.calls_disabled_countries] });
}

function reset() {
    Object.assign(form, { ...original.value, calls_disabled_countries: [...(original.value.calls_disabled_countries ?? [])] });
    Object.keys(errors).forEach((key) => delete errors[key]);
}

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

async function save() {
    Object.keys(errors).forEach((key) => delete errors[key]);
    saving.value = true;

    const body = {};
    Object.keys(original.value).forEach((key) => {
        if (!same(original.value[key], form[key])) {
            body[key] = form[key];
        }
    });

    try {
        const { data } = await adminAxios.put('/api/admin/v1/chat-settings', body);

        fill(data.data);
        showSuccess(t('chat.settings.saved'));
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};

        Object.entries(bag).forEach(([key, messages]) => { errors[key] = messages[0]; });
        errors.general = Object.keys(bag).length ? '' : extractApiErrorMessage(error);
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    loadCountries();

    try {
        const { data } = await adminAxios.get('/api/admin/v1/chat-settings');

        fill(data.data ?? {});
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
});
</script>
