<template>
    <div>
        <WalletPageHeader :title="t('adminMarketing.countries.title')" :section="t('adminMarketing.sidebar')" :total="loading ? null : options.features.length" />

        <div class="alert alert-light border mb-3 fs-13">{{ t('adminMarketing.countries.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2 catalog-toolbar-filters">
                    <Select
                        v-model="countryId"
                        :options="countryOptions"
                        option-label="label"
                        option-value="value"
                        :placeholder="t('adminMarketing.common.country')"
                        filter
                        :filter-placeholder="t('search_placeholder')"
                        class="wallet-filter-select"
                        @change="load"
                    />
                </div>
                <div class="catalog-toolbar-actions d-flex flex-wrap align-items-center gap-2">
                    <router-link v-if="can('service-availability.view')" :to="{ name: 'admin.service-availability.index' }" class="btn btn-sm btn-outline-secondary btn-wave">
                        <i class="ri-map-pin-line me-1"></i>{{ t('adminMarketing.countries.availability') }}
                    </router-link>
                    <button v-if="canUpdate" type="button" class="btn btn-outline-primary btn-sm btn-wave" :disabled="!otherCountries.length" @click="openCopy">
                        <i class="ri-file-copy-line me-1 align-middle"></i>{{ t('adminMarketing.countries.copy') }}
                    </button>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('adminMarketing.countries.feature') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="6" :layout="['two-lines', 'toggle']" />
                            <tr v-else-if="!options.features.length">
                                <td colspan="2" class="border-0 text-center text-muted py-5">{{ t('wallet.common.empty') }}</td>
                            </tr>
                            <tr v-for="f in options.features" v-else :key="f">
                                <td class="ps-4">
                                    <span class="d-block fw-semibold">{{ featureLabel(f) }}</span>
                                    <span class="text-muted fs-11 d-block text-wrap feature-hint">{{ featureHint(f) }}</span>
                                    <code class="fs-11 text-muted">{{ f }}</code>
                                </td>
                                <td>
                                    <div
                                        v-if="canUpdate"
                                        class="toggle toggle-success mb-0 catalog-status-toggle"
                                        :class="{ on: isOn(f), 'catalog-status-toggle--loading': toggling.includes(f) }"
                                        role="button"
                                        tabindex="0"
                                        :aria-busy="toggling.includes(f)"
                                        @click="toggle(f)"
                                        @keydown.enter.space.prevent="toggle(f)"
                                    ><span></span></div>
                                    <span v-else class="badge" :class="isOn(f) ? 'bg-success-transparent' : 'bg-secondary-transparent'">{{ isOn(f) ? t('wallet.common.active') : t('wallet.common.inactive') }}</span>
                                    <span v-if="f !== 'marketing' && isOn(f) && !isOn('marketing')" class="badge bg-warning-transparent ms-2">{{ t('adminMarketing.countries.needs_service') }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <WalletModal :show="showCopy" :title="t('adminMarketing.countries.copy')" size="md" @close="showCopy = false">
            <form id="marketing-copy-form" novalidate @submit.prevent="copy">
                <div class="alert alert-light border fs-13 mb-3">{{ t('adminMarketing.countries.copy_hint') }}</div>
                <div class="mb-3">
                    <label class="form-label">{{ t('adminMarketing.countries.copy_from') }} <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="ri-earth-line"></i></span>
                        <Select
                            v-model="copyFrom"
                            :options="otherCountryOptions"
                            option-label="label"
                            option-value="value"
                            filter
                            :filter-placeholder="t('search_placeholder')"
                            append-to="body"
                            class="flex-grow-1"
                        />
                    </div>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" @click="showCopy = false">{{ t('cancel') }}</button>
                <button type="submit" form="marketing-copy-form" class="btn btn-primary" :disabled="!copyFrom || copying">{{ t('save') }}</button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import Select from 'primevue/select';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// AI Marketing Director (MK-A15): the service and each feature on or off per country. No rule means off,
// and nothing works in a country while "marketing" itself is off there.
const { t, te } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const endpoint = '/api/admin/v1/marketing/feature-rules';

const canUpdate = computed(() => can('marketing-feature-rules.update'));
const rows = ref([]);
const loading = ref(false);
const toggling = ref([]);
const options = reactive({ features: [], countries: [] });
const countryId = ref(null);
const showCopy = ref(false);
const copyFrom = ref(null);
const copying = ref(false);

const countryOptions = computed(() => options.countries.map((c) => ({ value: c.id, label: `${c.name} (${c.code})` })));
const otherCountries = computed(() => options.countries.filter((c) => c.id !== countryId.value));
const otherCountryOptions = computed(() => otherCountries.value.map((c) => ({ value: c.id, label: `${c.name} (${c.code})` })));
const isOn = (f) => !!rows.value.find((r) => r.feature === f)?.enabled;
const featureLabel = (f) => (te(`adminMarketing.features.${f}`) ? t(`adminMarketing.features.${f}`) : f);
const featureHint = (f) => (te(`adminMarketing.features_hint.${f}`) ? t(`adminMarketing.features_hint.${f}`) : '');

async function toggle(f) {
    if (toggling.value.includes(f) || !countryId.value) return;
    toggling.value.push(f);
    try {
        const response = await adminAxios.post(endpoint, { feature: f, country_id: countryId.value, enabled: !isOn(f) });
        const saved = response.data.data;
        const row = rows.value.find((r) => r.feature === f);
        if (row) row.enabled = saved.enabled;
        else rows.value.push(saved);
        showSuccess(extractApiMessage(response, t('toast.success')));
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        toggling.value = toggling.value.filter((x) => x !== f);
    }
}

function openCopy() {
    copyFrom.value = otherCountries.value[0]?.id ?? null;
    showCopy.value = true;
}

async function copy() {
    copying.value = true;
    try {
        const response = await adminAxios.post(`${endpoint}/copy`, { from_country_id: copyFrom.value, to_country_id: countryId.value });
        showCopy.value = false;
        showSuccess(extractApiMessage(response, t('toast.success')));
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        copying.value = false;
    }
}

async function load() {
    if (!countryId.value) {
        loading.value = false;
        return;
    }
    loading.value = true;
    try {
        const { data } = await adminAxios.get(endpoint, { params: { country_id: countryId.value } });
        rows.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(`${endpoint}/options`);
        Object.assign(options, data.data ?? {});
        countryId.value = options.countries[0]?.id ?? null;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
    load();
});
</script>

<style scoped>
.feature-hint {
    max-width: 520px;
}
</style>
