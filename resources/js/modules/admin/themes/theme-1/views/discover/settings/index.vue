<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">{{ t('discover.settings.title') }}</h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item">{{ t('sidebar.discover') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('discover.settings.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="alert alert-info fs-13">{{ t('discover.settings.intro') }}</div>

        <div v-if="loading" class="row g-3">
            <div v-for="n in 4" :key="n" class="col-xl-6">
                <div class="card custom-card h-100 mb-0 placeholder-glow">
                    <div class="card-header py-3 d-flex align-items-center gap-2">
                        <span class="placeholder rounded-circle" style="width: 32px; height: 32px;"></span>
                        <span class="placeholder col-4"></span>
                        <span class="placeholder rounded-pill ms-auto" style="width: 2.5rem; height: 1.25rem;"></span>
                    </div>
                    <div class="card-body">
                        <span class="placeholder col-5 placeholder-sm d-block mb-2"></span>
                        <span class="placeholder col-12 d-block mb-2" style="height: 2.25rem;"></span>
                        <span class="placeholder col-8 placeholder-sm d-block"></span>
                    </div>
                </div>
            </div>
        </div>

        <form v-else id="discover-settings-form" novalidate @submit.prevent="save">
            <div class="row g-3">
                <div class="col-xl-6">
                    <div class="card custom-card h-100 mb-0">
                        <div class="card-header py-3 d-flex align-items-center gap-2">
                            <span class="avatar avatar-sm avatar-rounded bg-primary-transparent"><i class="ri-compass-3-line"></i></span>
                            <div class="card-title mb-0">{{ t('discover.settings.section_general') }}</div>
                            <div class="d-flex align-items-center gap-2 ms-auto">
                                <span class="fs-13">{{ t('discover.settings.enabled') }}</span>
                                <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="toggleClass(form.enabled, !canUpdate)" role="button" tabindex="0" :aria-disabled="!canUpdate" @click="flip('enabled')" @keydown.enter.space.prevent="flip('enabled')"><span></span></div>
                            </div>
                        </div>
                        <div class="card-body">
                            <label class="form-label fs-13">{{ t('discover.settings.enabled_countries') }}</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ri-earth-line"></i></span>
                                <MultiSelect v-model="form.enabled_countries" :options="countries" option-label="name" option-value="id" filter display="chip" :placeholder="t('discover.settings.everywhere')" :disabled="!canUpdate || !form.enabled" />
                            </div>
                            <div class="text-muted fs-11 mt-1">{{ t('discover.settings.hint_countries') }}</div>
                            <div class="d-flex align-items-center justify-content-between gap-2 mt-3">
                                <span class="fs-13">{{ t('discover.settings.ai_enabled') }}</span>
                                <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="toggleClass(form.ai_enabled, !canUpdate)" role="button" tabindex="0" :aria-disabled="!canUpdate" @click="flip('ai_enabled')" @keydown.enter.space.prevent="flip('ai_enabled')"><span></span></div>
                            </div>
                            <div class="text-muted fs-11">{{ t('discover.settings.hint_ai') }}</div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-6">
                    <div class="card custom-card h-100 mb-0">
                        <div class="card-header py-3 d-flex align-items-center gap-2">
                            <span class="avatar avatar-sm avatar-rounded bg-success-transparent"><i class="ri-user-star-line"></i></span>
                            <div class="card-title mb-0">{{ t('discover.settings.section_organizers') }}</div>
                            <div class="d-flex align-items-center gap-2 ms-auto">
                                <span class="fs-13">{{ t('discover.settings.submissions_enabled') }}</span>
                                <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="toggleClass(form.submissions_enabled, !canUpdate)" role="button" tabindex="0" :aria-disabled="!canUpdate" @click="flip('submissions_enabled')" @keydown.enter.space.prevent="flip('submissions_enabled')"><span></span></div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <span class="fs-13">{{ t('discover.settings.auto_publish_verified') }}</span>
                                <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="toggleClass(form.auto_publish_verified, !canUpdate || !form.submissions_enabled)" role="button" tabindex="0" :aria-disabled="!canUpdate || !form.submissions_enabled" @click="flip('auto_publish_verified', !form.submissions_enabled)" @keydown.enter.space.prevent="flip('auto_publish_verified', !form.submissions_enabled)"><span></span></div>
                            </div>
                            <div class="text-muted fs-11">{{ t('discover.settings.hint_auto') }}</div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-6">
                    <div class="card custom-card h-100 mb-0">
                        <div class="card-header py-3 d-flex align-items-center gap-2">
                            <span class="avatar avatar-sm avatar-rounded bg-warning-transparent"><i class="ri-notification-3-line"></i></span>
                            <div class="card-title mb-0">{{ t('discover.settings.section_alerts') }}</div>
                            <div class="d-flex align-items-center gap-2 ms-auto">
                                <span class="fs-13">{{ t('discover.settings.alerts_enabled') }}</span>
                                <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="toggleClass(form.alerts_enabled, !canUpdate)" role="button" tabindex="0" :aria-disabled="!canUpdate" @click="flip('alerts_enabled')" @keydown.enter.space.prevent="flip('alerts_enabled')"><span></span></div>
                            </div>
                        </div>
                        <div class="card-body">
                            <label class="form-label fs-13">{{ t('discover.settings.max_alerts_per_week') }}</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ri-notification-badge-line"></i></span>
                                <input v-model.number="form.max_alerts_per_week" type="number" dir="ltr" class="form-control" :class="{ 'is-invalid': !!fieldError('max_alerts_per_week') }" :disabled="!canUpdate || !form.alerts_enabled">
                            </div>
                            <div v-if="fieldError('max_alerts_per_week')" class="text-danger fs-12 mt-1">{{ fieldError('max_alerts_per_week') }}</div>
                            <div class="text-muted fs-11 mt-1">{{ t('discover.settings.hint_alerts') }}</div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-6">
                    <div class="card custom-card h-100 mb-0">
                        <div class="card-header py-3 d-flex align-items-center gap-2">
                            <span class="avatar avatar-sm avatar-rounded bg-info-transparent"><i class="ri-group-line"></i></span>
                            <div class="card-title mb-0">{{ t('discover.settings.section_rooms') }}</div>
                        </div>
                        <div class="card-body">
                            <label class="form-label fs-13">{{ t('discover.settings.room_close_hours') }}</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ri-time-line"></i></span>
                                <input v-model.number="form.room_close_hours" type="number" dir="ltr" class="form-control" :class="{ 'is-invalid': !!fieldError('room_close_hours') }" :disabled="!canUpdate">
                                <span class="input-group-text fs-12">{{ t('discover.settings.hours') }}</span>
                            </div>
                            <div v-if="fieldError('room_close_hours')" class="text-danger fs-12 mt-1">{{ fieldError('room_close_hours') }}</div>
                            <div class="text-muted fs-11 mt-1">{{ t('discover.settings.hint_rooms') }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="error" class="text-danger mt-3 fs-13">{{ error }}</div>

            <div v-if="canUpdate" class="d-flex justify-content-end gap-2 mt-3 mb-4">
                <button type="submit" class="btn btn-primary" :disabled="saving"><span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}</button>
            </div>
        </form>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MultiSelect from 'primevue/multiselect';
import useVuelidate from '@vuelidate/core';
import adminAxios from '../../../../../../../api/adminAxios';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import useValidation from '../../../../../../../composables/useValidation';
import { usePermission } from '../../../../../../../composables/usePermission';

const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const { requiredField, numberRules } = useValidation();
const canUpdate = computed(() => can('discover-settings.update'));

const loading = ref(true);
const saving = ref(false);
const error = ref('');
const countries = ref([]);
const form = reactive({
    enabled: true, enabled_countries: [], submissions_enabled: true, auto_publish_verified: true,
    alerts_enabled: true, max_alerts_per_week: 3, room_close_hours: 24, ai_enabled: true,
});

const rules = computed(() => ({
    max_alerts_per_week: {
        required: requiredField('discover.settings.max_alerts_per_week'),
        ...numberRules('discover.settings.max_alerts_per_week', { min: 0, max: 21, integerOnly: true }),
    },
    room_close_hours: {
        required: requiredField('discover.settings.room_close_hours'),
        ...numberRules('discover.settings.room_close_hours', { min: 0, max: 720, integerOnly: true }),
    },
}));
const v$ = useVuelidate(rules, form, { $autoDirty: true });

/** The first Vuelidate message of a field once it was touched. */
function fieldError(name) {
    const field = v$.value[name];

    return field?.$dirty && field.$invalid ? field.$errors[0]?.$message || '' : '';
}

/** Same switch as the countries catalog; `disabled` greys it and ignores clicks. */
function toggleClass(on, disabled) {
    return { on, 'opacity-50': disabled };
}

function flip(key, extraDisabled = false) {
    if (!canUpdate.value || extraDisabled) return;
    form[key] = !form[key];
}

async function load() {
    loading.value = true;
    try {
        const [settings, list] = await Promise.all([
            adminAxios.get('/api/admin/v1/discover-settings'),
            adminAxios.get('/api/admin/v1/countries/dropdown').catch(() => ({ data: { data: [] } })),
        ]);
        Object.assign(form, settings.data.data ?? {}, { enabled_countries: [...(settings.data.data?.enabled_countries ?? [])] });
        countries.value = list.data?.data ?? [];
    } catch (e) {
        showError(extractApiErrorMessage(e));
    } finally {
        loading.value = false;
    }
}

async function save() {
    error.value = '';
    if (!(await v$.value.$validate())) return;
    saving.value = true;
    try {
        const { data } = await adminAxios.put('/api/admin/v1/discover-settings', { ...form });
        Object.assign(form, data.data ?? {});
        v$.value.$reset();
        showSuccess(t('discover.settings.saved'));
    } catch (e) {
        error.value = extractApiErrorMessage(e);
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>
