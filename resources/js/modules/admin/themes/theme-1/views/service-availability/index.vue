<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('service_control.title') }}
                <span v-if="pagination?.total != null" class="badge bg-primary-transparent ms-2 fs-12 align-middle">{{ pagination.total }}</span>
            </h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('service_control.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex flex-wrap align-items-center gap-3 py-3">
                <div class="input-group input-group-sm catalog-toolbar-search">
                    <span class="input-group-text bg-white">
                        <i class="ri-search-line text-muted"></i>
                    </span>
                    <input v-model="search" type="search" class="form-control" :placeholder="t('service_control.search')">
                    <button v-if="search" type="button" class="btn btn-light border catalog-search-clear" :title="t('service_control.clear_search')" @click="search = ''">
                        <i class="ri-close-line"></i>
                    </button>
                </div>
                <div>
                    <Select
                        v-model="countryId"
                        input-id="sc-country"
                        size="small"
                        :aria-label="t('service_control.country')"
                        :options="countries"
                        option-label="name"
                        option-value="id"
                        :placeholder="t('service_control.country')"
                        filter
                        append-to="body"
                        class="service-control-picker"
                    />
                </div>
                <div>
                    <Select
                        v-model="regionId"
                        input-id="sc-region"
                        size="small"
                        :aria-label="t('service_control.region')"
                        :options="regionOptions"
                        option-label="name"
                        option-value="id"
                        :placeholder="t('service_control.whole_country')"
                        filter
                        :disabled="!countryId"
                        append-to="body"
                        class="service-control-picker"
                    />
                </div>
                <div class="text-muted fs-12 ms-md-auto">
                    <i class="ri-information-line me-1"></i>{{ regionId ? t('service_control.hint_region') : t('service_control.hint_country') }}
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th scope="col" class="ps-4">{{ t('service_control.service') }}</th>
                                <th scope="col" class="text-center">{{ t('service_control.switch_service') }}</th>
                                <th scope="col">{{ t('service_control.switch_customer') }}</th>
                                <th scope="col" class="text-center">{{ t('service_control.switch_provider') }}</th>
                                <th v-if="regionId" scope="col" class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="6" :columns="regionId ? 5 : 4" />

                            <tr v-else-if="!items.length">
                                <td :colspan="regionId ? 5 : 4" class="border-0">
                                    <div class="text-center py-5">
                                        <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                            <i class="ri-toggle-line fs-2 text-primary"></i>
                                        </span>
                                        <p class="fw-semibold mb-1">{{ search ? t('service_control.no_results_title') : t('service_control.empty_title') }}</p>
                                        <p class="text-muted mb-0">{{ search ? t('service_control.no_results') : t('service_control.empty') }}</p>
                                    </div>
                                </td>
                            </tr>

                            <template v-else>
                                <tr v-for="item in items" :key="item.id">
                                    <td class="ps-4">
                                        <span class="fw-semibold text-default">{{ item.name || `#${item.id}` }}</span>
                                        <span class="d-block text-muted fs-11">{{ statusText(item) }}</span>
                                    </td>

                                    <!-- The service as a whole: off, and neither customers nor providers can use it here. -->
                                    <td class="text-center">
                                        <div
                                            class="toggle toggle-success mb-0 catalog-status-toggle d-inline-block"
                                            :class="{
                                                on: item.switches.service_enabled,
                                                'catalog-status-toggle--loading': busy === item.id,
                                                'catalog-status-toggle--disabled': !canUpdate,
                                            }"
                                            role="button"
                                            :tabindex="canUpdate ? 0 : -1"
                                            :aria-busy="busy === item.id"
                                            @click="flip(item, 'service_enabled')"
                                            @keydown.enter.space.prevent="flip(item, 'service_enabled')"
                                        >
                                            <span></span>
                                        </div>
                                        <span v-if="inherited(item, 'service_enabled')" class="d-block text-muted fs-10">{{ t('service_control.inherited') }}</span>
                                    </td>

                                    <!-- The customer app, and what the customer sees while it is off. -->
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div
                                            class="toggle toggle-success mb-0 catalog-status-toggle"
                                            :class="{
                                                on: item.switches.customer_enabled,
                                                'catalog-status-toggle--loading': busy === item.id,
                                                'catalog-status-toggle--disabled': !canUpdate,
                                            }"
                                            role="button"
                                            :tabindex="canUpdate ? 0 : -1"
                                            :aria-busy="busy === item.id"
                                            @click="flip(item, 'customer_enabled')"
                                            @keydown.enter.space.prevent="flip(item, 'customer_enabled')"
                                        >
                                            <span></span>
                                        </div>
                                            <Select
                                                v-if="!item.switches.customer_enabled"
                                                :model-value="item.switches.customer_mode"
                                                :options="modeOptions"
                                                option-label="label"
                                                option-value="value"
                                                :disabled="!canUpdate || busy === item.id"
                                                :aria-label="t('service_control.when_off')"
                                                append-to="body"
                                                class="service-control-select"
                                                @update:model-value="save(item, { customer_mode: $event })"
                                            />
                                        </div>
                                        <span v-if="inherited(item, 'customer_enabled')" class="d-block text-muted fs-10">{{ t('service_control.inherited') }}</span>
                                    </td>

                                    <!-- The provider app: only for services that have providers. -->
                                    <td class="text-center">
                                        <template v-if="item.has_provider">
                                            <div
                                            class="toggle toggle-success mb-0 catalog-status-toggle d-inline-block"
                                            :class="{
                                                on: item.switches.provider_enabled,
                                                'catalog-status-toggle--loading': busy === item.id,
                                                'catalog-status-toggle--disabled': !canUpdate,
                                            }"
                                            role="button"
                                            :tabindex="canUpdate ? 0 : -1"
                                            :aria-busy="busy === item.id"
                                            @click="flip(item, 'provider_enabled')"
                                            @keydown.enter.space.prevent="flip(item, 'provider_enabled')"
                                        >
                                            <span></span>
                                        </div>
                                            <span v-if="inherited(item, 'provider_enabled')" class="d-block text-muted fs-10">{{ t('service_control.inherited') }}</span>
                                        </template>
                                        <span v-else class="text-muted" :title="t('service_control.no_provider')">—</span>
                                    </td>

                                    <td v-if="regionId" class="text-end pe-4">
                                        <button
                                            v-if="canUpdate && hasOverride(item)"
                                            type="button"
                                            class="btn btn-sm btn-light"
                                            :disabled="busy === item.id"
                                            @click="reset(item)"
                                        >
                                            <i class="ri-arrow-go-back-line me-1"></i>{{ t('service_control.follow_country') }}
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="pagination && !loading" class="card-footer border-top-0">
                <div class="d-flex align-items-center flex-wrap gap-3">
                    <div class="d-flex align-items-center gap-2 text-muted fs-13">
                        <span>{{ entriesLabel }}</span>
                        <i :class="paginationArrowIcon"></i>
                    </div>

                    <div class="d-flex align-items-center gap-2 ms-md-auto">
                        <label class="text-muted fs-13 mb-0" for="service-control-per-page">{{ t('service_control.per_page') }}</label>
                        <select id="service-control-per-page" v-model.number="perPage" class="form-select form-select-sm w-auto">
                            <option :value="15">15</option>
                            <option :value="25">25</option>
                            <option :value="50">50</option>
                        </select>
                    </div>

                    <nav aria-label="Service control pagination" class="pagination-style-4">
                        <ul class="pagination mb-0">
                            <li class="page-item" :class="{ disabled: !pagination.prev_page_url }">
                                <button type="button" class="page-link" @click="changePage(currentPage - 1)">{{ t('service_control.previous') }}</button>
                            </li>
                            <li v-for="n in pageNumbers" :key="n" class="page-item" :class="{ active: n === currentPage }">
                                <button type="button" class="page-link" @click="changePage(n)">{{ n }}</button>
                            </li>
                            <li class="page-item" :class="{ disabled: !pagination.next_page_url }">
                                <button type="button" class="page-link text-primary" @click="changePage(currentPage + 1)">{{ t('service_control.next') }}</button>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import Select from 'primevue/select';
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import { usePermission } from '../../../../../../composables/usePermission';
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';

const URI = '/api/admin/v1/service-availability';

const { t, locale } = useI18n();
const { can } = usePermission();
const { showError } = useToast();

const canUpdate = computed(() => can('service-availability.update'));

const countries = ref([]);
const regions = ref([]);
const countryId = ref(null);
const regionId = ref(null);
const items = ref([]);
const loading = ref(false);
const search = ref('');
const perPage = ref(15);
const pagination = ref(null);
const currentPage = computed(() => pagination.value?.current_page ?? 1);
// The service whose switch is being saved.
const busy = ref(null);

// "Whole country" first, then the country's regions.
const regionOptions = computed(() => [{ id: null, name: t('service_control.whole_country') }, ...regions.value]);

// What the customer sees while the customer switch is off.
const modeOptions = computed(() => [
    { value: 'hidden', label: t('service_control.mode_hidden') },
    { value: 'coming_soon', label: t('service_control.mode_coming_soon') },
    { value: 'view_only', label: t('service_control.mode_view_only') },
]);

// Which place a switch belongs to (the list adds search and the page on top of it).
const params = computed(() => ({ country_id: countryId.value, region_id: regionId.value || undefined }));

const paginationArrowIcon = computed(() => (locale.value === 'ar' ? 'ri-arrow-left-s-line fw-semibold' : 'ri-arrow-right-s-line fw-semibold'));

const pageNumbers = computed(() => {
    const last = pagination.value?.last_page ?? 0;

    if (! last) {
        return [];
    }

    let start = Math.max(1, currentPage.value - 2);
    const end = Math.min(last, start + 4);
    start = Math.max(1, end - 4);

    return Array.from({ length: end - start + 1 }, (_, i) => start + i);
});

const entriesLabel = computed(() => t('service_control.showing_entries', {
    from: pagination.value?.from ?? 0,
    to: pagination.value?.to ?? 0,
    total: pagination.value?.total ?? 0,
}));

async function load(page = 1) {
    if (! countryId.value) {
        items.value = [];
        pagination.value = null;

        return;
    }

    loading.value = true;

    try {
        const { data } = await adminAxios.get(URI, {
            params: { ...params.value, search: search.value.trim() || undefined, per_page: perPage.value, page },
        });
        items.value = data.data ?? [];
        pagination.value = data.pagination ?? null;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

async function loadRegions() {
    regions.value = [];

    if (! countryId.value) {
        return;
    }

    try {
        const { data } = await adminAxios.get('/api/admin/v1/regions/dropdown', { params: { country_id: countryId.value } });
        regions.value = data.data ?? [];
    } catch {
        regions.value = [];
    }
}

/** Saves some switches of one service and replaces its row with what the server says it now is. */
async function save(item, changes) {
    busy.value = item.id;

    try {
        const { data } = await adminAxios.put(URI, { ...params.value, service_category_id: item.id, ...changes });
        replace(data.data);
    } catch (error) {
        showError(extractApiErrorMessage(error));
        await load(currentPage.value);
    } finally {
        busy.value = null;
    }
}

/** Flips one switch (nothing while it is saving, or when the admin may only look). */
function flip(item, name) {
    if (! canUpdate.value || busy.value === item.id) {
        return;
    }

    return save(item, { [name]: ! item.switches[name] });
}

async function reset(item) {
    busy.value = item.id;

    try {
        const { data } = await adminAxios.post(`${URI}/reset`, { ...params.value, service_category_id: item.id });
        replace(data.data);
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        busy.value = null;
    }
}

function replace(updated) {
    items.value = items.value.map((row) => (row.id === updated.id ? updated : row));
}

/** In a region view: this switch is the country's, not set by the region. */
function inherited(item, name) {
    return Boolean(regionId.value) && item.overridden && ! item.overridden[name];
}

function hasOverride(item) {
    return Boolean(item.overridden) && Object.values(item.overridden).some(Boolean);
}

/** One line under the name: what customers and providers get right now. */
function statusText(item) {
    const e = item.effective;

    if (! e.service_open) {
        return t('service_control.state_closed');
    }

    const customer = e.customer_state === 'enabled'
        ? t('service_control.state_customer_on')
        : t(`service_control.state_customer_${e.customer_state}`);

    return item.has_provider
        ? `${customer} · ${e.provider_can_use ? t('service_control.state_provider_on') : t('service_control.state_provider_off')}`
        : customer;
}

function changePage(page) {
    if (! pagination.value || page < 1 || page > pagination.value.last_page) {
        return;
    }

    load(page);
}

watch(countryId, async () => {
    regionId.value = null;
    await loadRegions();
    await load(1);
});
watch([regionId, perPage], () => load(1));

// Searching waits for a pause in typing.
let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => load(1), 350);
});

onMounted(async () => {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/countries/dropdown');
        countries.value = data.data ?? [];
        countryId.value = (countries.value.find((c) => c.is_default) ?? countries.value[0])?.id ?? null;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
});
</script>

<style scoped>
.catalog-status-toggle--disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.service-control-select {
    min-width: 12rem;
}

/* The country and governorate / state pickers in the toolbar: small, a placeholder instead of a label. */
.service-control-picker {
    width: 10.5rem;
    min-width: 0;
    font-size: 0.8125rem;
}
</style>
