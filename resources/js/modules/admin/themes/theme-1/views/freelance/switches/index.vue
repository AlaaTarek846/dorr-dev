<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('freelance.switches.title') }}
                <span v-if="!loading" class="badge bg-primary-transparent ms-2 fs-12 align-middle">{{ options.features.length }}</span>
            </h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item">{{ t('sidebar.freelance') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('freelance.switches.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="alert alert-light border mb-3 fs-13">{{ t('freelance.switches.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2 catalog-toolbar-filters">
                    <Select
                        v-model="countryId"
                        :options="countryOptions"
                        option-label="label"
                        option-value="value"
                        :placeholder="t('discover.catalog.country')"
                        filter
                        :filter-placeholder="t('search_placeholder')"
                        class="wallet-filter-select"
                        @change="load"
                    />
                </div>
                <div class="catalog-toolbar-actions d-flex flex-wrap align-items-center gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm btn-wave" :disabled="downloading" @click="downloadGuide">
                        <span v-if="downloading" class="spinner-border spinner-border-sm me-1"></span><i v-else class="ri-file-word-2-line me-1 align-middle"></i>{{ t('freelance.switches.guide') }}
                    </button>
                    <button v-if="canUpdate" type="button" class="btn btn-outline-primary btn-sm btn-wave" :disabled="!otherCountries.length" @click="openCopy">
                        <i class="ri-file-copy-line me-1 align-middle"></i>{{ t('freelance.switches.copy') }}
                    </button>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('freelance.switches.feature') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="8" :layout="['two-lines', 'toggle']" />
                            <tr v-else-if="!options.features.length">
                                <td colspan="2" class="border-0 text-center text-muted py-5">{{ t('wallet.common.empty') }}</td>
                            </tr>
                            <tr v-for="f in options.features" v-else :key="f">
                                <td class="ps-4">
                                    <span class="d-block fw-semibold">{{ featureLabel(f) }}</span>
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
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <WalletModal :show="showCopy" :title="t('freelance.switches.copy')" size="md" @close="showCopy = false">
            <form id="freelance-copy-form" novalidate @submit.prevent="copy">
                <div class="alert alert-light border fs-13 mb-3">{{ t('freelance.switches.copy_hint') }}</div>
                <div class="mb-3">
                    <label class="form-label">{{ t('freelance.switches.copy_from') }} <span class="text-danger">*</span></label>
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
                <button type="submit" form="freelance-copy-form" class="btn btn-primary" :disabled="!copyFrom">{{ t('save') }}</button>
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
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// The service and each feature on or off per country (no cities). No rule means off,
// and nothing works while "freelance" itself is off.
const { t, te } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();

const canUpdate = computed(() => can('freelance-feature-rules.update'));
const rows = ref([]);
const loading = ref(false);
const toggling = ref([]);
const options = reactive({ features: [], countries: [] });
const countryId = ref(null);
const showCopy = ref(false);
const copyFrom = ref(null);

const countryOptions = computed(() => options.countries.map((c) => ({ value: c.id, label: `${c.name} (${c.code})` })));
const otherCountries = computed(() => options.countries.filter((c) => c.id !== countryId.value));
const otherCountryOptions = computed(() => otherCountries.value.map((c) => ({ value: c.id, label: `${c.name} (${c.code})` })));
const isOn = (f) => !!rows.value.find((r) => r.feature === f)?.enabled;
const featureLabel = (f) => (te(`freelance.switches.features.${f}`) ? t(`freelance.switches.features.${f}`) : f);

async function toggle(f) {
    if (toggling.value.includes(f)) return;
    toggling.value.push(f);
    try {
        const response = await adminAxios.post('/api/admin/v1/freelance-feature-rules', {
            feature: f,
            country_id: countryId.value,
            enabled: !isOn(f),
        });
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

const downloading = ref(false);

/** The Word file that explains every switch (always Arabic), with this country's state in it. */
async function downloadGuide() {
    downloading.value = true;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/freelance-feature-rules/guide', {
            params: { country_id: countryId.value },
            responseType: 'blob',
        });
        const url = URL.createObjectURL(data);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'freelance-feature-switches.docx';
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        downloading.value = false;
    }
}

function openCopy() {
    copyFrom.value = otherCountries.value[0]?.id ?? null;
    showCopy.value = true;
}

async function copy() {
    try {
        const response = await adminAxios.post('/api/admin/v1/freelance-feature-rules/copy', { from_country_id: copyFrom.value, to_country_id: countryId.value });
        showCopy.value = false;
        showSuccess(extractApiMessage(response, t('toast.success')));
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

async function load() {
    if (!countryId.value) return;
    loading.value = true;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/freelance-feature-rules', { params: { country_id: countryId.value } });
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
        const { data } = await adminAxios.get('/api/admin/v1/freelance-feature-rules/options');
        Object.assign(options, data.data ?? {});
        countryId.value = options.countries[0]?.id ?? null;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
    load();
});
</script>
