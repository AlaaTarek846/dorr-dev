<template>
    <div>
        <WalletPageHeader :title="t('rides.switches.title')" :section="t('sidebar.rides')" :total="rows.length || null" />

        <div class="alert alert-light border mb-3 fs-13">{{ t('rides.switches.hint') }}</div>

        <!-- A matrix per country: every feature, the country's state, and its cities' exceptions. -->
        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <select v-model="countryId" class="form-select form-select-sm" style="max-width: 220px;">
                    <option v-for="c in options.countries" :key="c.id" :value="c.id">{{ c.name }} ({{ c.code }})</option>
                </select>
                <button v-if="can('ride-feature-rules.create')" type="button" class="btn btn-primary btn-sm ms-auto" @click="openCity">
                    <i class="ri-map-pin-add-line me-1"></i>{{ t('rides.switches.city_exception') }}
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('rides.switches.feature') }}</th>
                                <th>{{ t('rides.switches.whole_country') }}</th>
                                <th>{{ t('rides.switches.cities') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="3" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-for="f in options.features" v-else :key="f" :class="{ 'table-active': f === 'rides' }">
                                <td class="ps-4">
                                    <div class="fw-semibold">{{ featureLabel(f) }}</div>
                                    <code class="fs-11 text-muted">{{ f }}</code>
                                </td>
                                <td>
                                    <div class="toggle toggle-success mb-0" :class="{ on: countryRule(f)?.enabled }" role="button" @click="toggleCountry(f)"><span></span></div>
                                </td>
                                <td>
                                    <span v-for="r in cityRules(f)" :key="r.id" class="badge me-1 mb-1" :class="r.enabled ? 'bg-success-transparent' : 'bg-danger-transparent'">
                                        <i class="ri-map-pin-2-line me-1"></i>{{ r.city?.name || `#${r.city?.id}` }}: {{ r.enabled ? t('rides.switches.on') : t('rides.switches.off') }}
                                        <i v-if="can('ride-feature-rules.delete')" class="ri-close-line ms-1" role="button" @click="remove(r)"></i>
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <WalletModal :show="showModal" :title="t('rides.switches.city_exception')" size="md" @close="showModal = false">
            <label class="form-label">{{ t('rides.switches.feature') }}</label>
            <select v-model="form.feature" class="form-select mb-3">
                <option v-for="f in options.features" :key="f" :value="f">{{ featureLabel(f) }}</option>
            </select>
            <label class="form-label">{{ t('rides.drivers.city') }}</label>
            <select v-model="form.city_id" class="form-select mb-3">
                <option v-for="c in citiesOfCountry" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <div class="form-check form-switch mb-3">
                <input id="city-on" v-model="form.enabled" class="form-check-input" type="checkbox">
                <label class="form-check-label" for="city-on">{{ form.enabled ? t('rides.switches.on') : t('rides.switches.off') }}</label>
            </div>
            <input v-model="form.note" type="text" maxlength="255" class="form-control" :placeholder="t('rides.switches.note')">
            <template #footer>
                <button type="button" class="btn btn-light" @click="showModal = false">{{ t('cancel') }}</button>
                <button type="button" class="btn btn-primary" :disabled="!form.city_id" @click="saveCity">{{ t('save') }}</button>
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

// Every service and feature on or off (owner): per country, with a city's exception beating it.
// No rule means off, and nothing works while "rides" itself is off.
const { t, te } = useI18n();
const { can } = usePermission();
const { showError } = useToast();

const rows = ref([]);
const loading = ref(false);
const options = reactive({ features: [], countries: [], cities: [] });
const countryId = ref(null);
const showModal = ref(false);
const form = reactive({ feature: 'rides', city_id: null, enabled: false, note: '' });

const citiesOfCountry = computed(() => options.cities.filter((c) => c.country_id === countryId.value));
const countryRule = (f) => rows.value.find((r) => r.feature === f && r.country.id === countryId.value && !r.city);
const cityRules = (f) => rows.value.filter((r) => r.feature === f && r.country.id === countryId.value && r.city);
const featureLabel = (f) => {
    if (f.startsWith('category.')) return `${t('rides.switches.category')}: ${f.slice(9)}`;
    return te(`rides.switches.features.${f}`) ? t(`rides.switches.features.${f}`) : f;
};

async function toggleCountry(f) {
    if (!can('ride-feature-rules.update')) return;
    const rule = countryRule(f);
    try {
        if (rule) await adminAxios.put(`/api/admin/v1/ride-feature-rules/${rule.id}`, { enabled: !rule.enabled, note: rule.note });
        else await adminAxios.post('/api/admin/v1/ride-feature-rules', { feature: f, country_id: countryId.value, enabled: true });
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

function openCity() {
    Object.assign(form, { feature: 'rides', city_id: citiesOfCountry.value[0]?.id ?? null, enabled: false, note: '' });
    showModal.value = true;
}

async function saveCity() {
    try {
        await adminAxios.post('/api/admin/v1/ride-feature-rules', { ...form, country_id: countryId.value, note: form.note || null });
        showModal.value = false;
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function remove(rule) {
    try {
        await adminAxios.delete(`/api/admin/v1/ride-feature-rules/${rule.id}`);
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ride-feature-rules');
        rows.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ride-feature-rules/options');
        Object.assign(options, data.data ?? {});
        countryId.value = options.countries[0]?.id ?? null;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
    load();
});
</script>
