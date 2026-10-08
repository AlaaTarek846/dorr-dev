<template>
    <div>
        <WalletPageHeader :title="t('discover.settings.title')" :section="t('sidebar.discover')" />

        <div class="alert alert-info fs-13">{{ t('discover.settings.intro') }}</div>

        <div v-if="loading" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></div>

        <form v-else id="discover-settings-form" @submit.prevent="save">
            <div class="row g-3">
                <div class="col-xl-6">
                    <div class="card custom-card h-100 mb-0">
                        <div class="card-header py-3 d-flex align-items-center gap-2">
                            <span class="avatar avatar-sm avatar-rounded bg-primary-transparent"><i class="ri-compass-3-line"></i></span>
                            <div class="card-title mb-0">{{ t('discover.settings.section_general') }}</div>
                            <div class="form-check form-switch ms-auto mb-0">
                                <input id="ds-enabled" v-model="form.enabled" class="form-check-input" type="checkbox" :disabled="!canUpdate">
                                <label class="form-check-label" for="ds-enabled">{{ t('discover.settings.enabled') }}</label>
                            </div>
                        </div>
                        <div class="card-body">
                            <label class="form-label fs-13">{{ t('discover.settings.enabled_countries') }}</label>
                            <MultiSelect v-model="form.enabled_countries" :options="countries" option-label="name" option-value="id" filter display="chip" class="w-100" :placeholder="t('discover.settings.everywhere')" :disabled="!canUpdate || !form.enabled" />
                            <div class="text-muted fs-11 mt-1">{{ t('discover.settings.hint_countries') }}</div>
                            <div class="form-check form-switch mt-3">
                                <input id="ds-ai" v-model="form.ai_enabled" class="form-check-input" type="checkbox" :disabled="!canUpdate">
                                <label class="form-check-label" for="ds-ai">{{ t('discover.settings.ai_enabled') }}</label>
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
                            <div class="form-check form-switch ms-auto mb-0">
                                <input id="ds-sub" v-model="form.submissions_enabled" class="form-check-input" type="checkbox" :disabled="!canUpdate">
                                <label class="form-check-label" for="ds-sub">{{ t('discover.settings.submissions_enabled') }}</label>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="form-check form-switch">
                                <input id="ds-auto" v-model="form.auto_publish_verified" class="form-check-input" type="checkbox" :disabled="!canUpdate || !form.submissions_enabled">
                                <label class="form-check-label" for="ds-auto">{{ t('discover.settings.auto_publish_verified') }}</label>
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
                            <div class="form-check form-switch ms-auto mb-0">
                                <input id="ds-alerts" v-model="form.alerts_enabled" class="form-check-input" type="checkbox" :disabled="!canUpdate">
                                <label class="form-check-label" for="ds-alerts">{{ t('discover.settings.alerts_enabled') }}</label>
                            </div>
                        </div>
                        <div class="card-body">
                            <label class="form-label fs-13">{{ t('discover.settings.max_alerts_per_week') }}</label>
                            <input v-model.number="form.max_alerts_per_week" type="number" min="0" max="21" dir="ltr" class="form-control" :disabled="!canUpdate || !form.alerts_enabled">
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
                                <input v-model.number="form.room_close_hours" type="number" min="0" max="720" dir="ltr" class="form-control" :disabled="!canUpdate">
                                <span class="input-group-text fs-12">{{ t('discover.settings.hours') }}</span>
                            </div>
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
import adminAxios from '../../../../../../../api/adminAxios';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const canUpdate = computed(() => can('discover-settings.update'));

const loading = ref(true);
const saving = ref(false);
const error = ref('');
const countries = ref([]);
const form = reactive({
    enabled: true, enabled_countries: [], submissions_enabled: true, auto_publish_verified: true,
    alerts_enabled: true, max_alerts_per_week: 3, room_close_hours: 24, ai_enabled: true,
});

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
    saving.value = true;
    error.value = '';
    try {
        const { data } = await adminAxios.put('/api/admin/v1/discover-settings', { ...form });
        Object.assign(form, data.data ?? {});
        showSuccess(t('discover.settings.saved'));
    } catch (e) {
        error.value = extractApiErrorMessage(e);
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>
