<template>
    <div>
        <WalletPageHeader :title="t('chat.feature_rules.title')" :section="t('sidebar.chat')" :total="rows.length || null" />

        <div class="alert alert-light border mb-3 fs-13">{{ t('chat.feature_rules.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="btn-group btn-group-sm" role="group">
                    <button v-for="f in featureFilters" :key="f.value" type="button" class="btn" :class="feature === f.value ? 'btn-primary' : 'btn-outline-primary'" @click="feature = f.value">{{ f.label }}</button>
                </div>
                <button v-if="canUpdate" type="button" class="btn btn-primary btn-sm btn-wave ms-auto" @click="openCreate">
                    <i class="ri-add-line me-1 align-middle"></i>{{ t('chat.feature_rules.add') }}
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('chat.feature_rules.feature') }}</th>
                                <th>{{ t('chat.feature_rules.country') }}</th>
                                <th>{{ t('chat.feature_rules.city') }}</th>
                                <th>{{ t('chat.feature_rules.state') }}</th>
                                <th>{{ t('chat.feature_rules.note') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="6" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!visibleRows.length"><td colspan="6" class="text-center text-muted py-5">{{ t('chat.feature_rules.empty') }}</td></tr>
                            <template v-else>
                                <tr v-for="row in visibleRows" :key="row.id">
                                    <td class="ps-4 fw-semibold"><i :class="icons[row.feature]" class="me-1"></i>{{ t(`chat.feature_rules.features.${row.feature}`) }}</td>
                                    <td>{{ row.country?.name || row.country?.code }}</td>
                                    <td>
                                        <span v-if="row.city" class="badge bg-info-transparent"><i class="ri-map-pin-2-line me-1"></i>{{ row.city.name || `#${row.city.id}` }}</span>
                                        <span v-else class="text-muted">{{ t('chat.feature_rules.whole_country') }}</span>
                                    </td>
                                    <td>
                                        <div v-if="canUpdate" class="toggle toggle-success mb-0" :class="{ on: row.enabled }" role="button" @click="toggle(row)"><span></span></div>
                                        <span v-else class="badge" :class="row.enabled ? 'bg-success-transparent' : 'bg-danger-transparent'">{{ row.enabled ? t('chat.feature_rules.on') : t('chat.feature_rules.off') }}</span>
                                    </td>
                                    <td class="text-muted text-wrap" style="max-width: 280px">{{ row.note }}</td>
                                    <td class="text-end pe-4">
                                        <button v-if="canUpdate" type="button" class="btn btn-sm btn-danger-light btn-icon" @click="remove(row)"><i class="ri-delete-bin-line"></i></button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <WalletModal :show="showModal" :title="t('chat.feature_rules.add')" @close="showModal = false">
            <form id="chat-feature-rule-form" @submit.prevent="save">
                <label class="form-label">{{ t('chat.feature_rules.feature') }} <span class="text-danger">*</span></label>
                <select v-model="form.feature" class="form-select mb-3">
                    <option v-for="f in options.features" :key="f" :value="f">{{ t(`chat.feature_rules.features.${f}`) }}</option>
                </select>

                <label class="form-label">{{ t('chat.feature_rules.country') }} <span class="text-danger">*</span></label>
                <select v-model="form.country_id" class="form-select mb-3" @change="form.city_id = null">
                    <option :value="null" disabled>{{ t('chat.feature_rules.country') }}</option>
                    <option v-for="c in options.countries" :key="c.id" :value="c.id">{{ c.name }} ({{ c.code }})</option>
                </select>

                <label class="form-label">{{ t('chat.feature_rules.city') }}</label>
                <select v-model="form.city_id" class="form-select" :disabled="!form.country_id">
                    <option :value="null">{{ t('chat.feature_rules.whole_country') }}</option>
                    <option v-for="c in citiesOfCountry" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <small class="text-muted d-block mb-3">{{ t('chat.feature_rules.city_hint') }}</small>
                <div v-if="errors.city_id" class="text-danger fs-12 mb-2">{{ errors.city_id }}</div>

                <div class="form-check form-switch mb-3">
                    <input id="rule-enabled" v-model="form.enabled" class="form-check-input" type="checkbox">
                    <label class="form-check-label" for="rule-enabled">{{ form.enabled ? t('chat.feature_rules.on') : t('chat.feature_rules.off') }}</label>
                </div>

                <label class="form-label">{{ t('chat.feature_rules.note') }}</label>
                <input v-model="form.note" type="text" maxlength="255" class="form-control" :placeholder="t('chat.feature_rules.note_hint')">
                <div v-if="errors.general" class="text-danger mt-2 fs-13">{{ errors.general }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="saving" @click="showModal = false">{{ t('cancel') }}</button>
                <button type="submit" form="chat-feature-rule-form" class="btn btn-primary" :disabled="saving || !form.country_id">
                    <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}
                </button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// Chat features on or off for a country or one of its cities: a city's rule beats its country's,
// and no rule means on. The app learns the city from the phone's location.
const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();

const canUpdate = computed(() => can('chat-settings.update'));
const icons = { calls: 'ri-phone-line', voice_rooms: 'ri-mic-line', live: 'ri-live-line', stories: 'ri-donut-chart-line', ai: 'ri-sparkling-line', verification_paid: 'ri-shield-check-line', verification_celebrity: 'ri-star-line' };

const rows = ref([]);
const loading = ref(false);
const options = reactive({ features: [], countries: [], cities: [] });
const feature = ref('');
const featureFilters = computed(() => [{ value: '', label: t('chat.feature_rules.all') }, ...options.features.map((f) => ({ value: f, label: t(`chat.feature_rules.features.${f}`) }))]);
const visibleRows = computed(() => (feature.value ? rows.value.filter((row) => row.feature === feature.value) : rows.value));

const showModal = ref(false);
const saving = ref(false);
const errors = reactive({});
const form = reactive({ feature: 'calls', country_id: null, city_id: null, enabled: false, note: '' });
const citiesOfCountry = computed(() => options.cities.filter((c) => c.country_id === form.country_id));

function resetErrors() {
    Object.keys(errors).forEach((key) => delete errors[key]);
}

function openCreate() {
    resetErrors();
    Object.assign(form, { feature: feature.value || 'calls', country_id: options.countries[0]?.id ?? null, city_id: null, enabled: false, note: '' });
    showModal.value = true;
}

async function save() {
    resetErrors();
    saving.value = true;
    try {
        await adminAxios.post('/api/admin/v1/chat-feature-rules', { ...form, note: form.note || null });
        showSuccess(t('chat.feature_rules.saved'));
        showModal.value = false;
        load();
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};
        Object.entries(bag).forEach(([key, messages]) => { errors[key] ??= messages[0]; });
        errors.general = Object.keys(bag).length ? '' : extractApiErrorMessage(error);
    } finally {
        saving.value = false;
    }
}

async function toggle(row) {
    try {
        await adminAxios.put(`/api/admin/v1/chat-feature-rules/${row.id}`, { enabled: !row.enabled, note: row.note });
        row.enabled = !row.enabled;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function remove(row) {
    if (!window.confirm(t('chat.feature_rules.confirm_delete'))) {
        return;
    }
    try {
        await adminAxios.delete(`/api/admin/v1/chat-feature-rules/${row.id}`);
        showSuccess(t('chat.common.deleted'));
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/chat-feature-rules');
        rows.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    load();
    try {
        const { data } = await adminAxios.get('/api/admin/v1/chat-feature-rules/options');
        Object.assign(options, data.data ?? {});
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
});
</script>
