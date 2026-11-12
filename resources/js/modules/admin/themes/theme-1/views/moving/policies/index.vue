<template>
    <div>
        <WalletPageHeader :title="t('moving.policies.title')" :section="t('sidebar.moving')" />

        <div class="alert alert-light border mb-3 fs-13">{{ t('moving.policies.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex flex-wrap gap-2 align-items-center py-3">
                <Select
                    v-model="countryId"
                    :options="countryOptions"
                    option-label="label"
                    option-value="value"
                    :placeholder="t('moving.policies.default_all')"
                    filter
                    :filter-placeholder="t('search_placeholder')"
                    class="wallet-filter-select"
                />
                <span v-if="current?.version" class="badge bg-light text-dark">{{ t('moving.policies.version') }} {{ current.version }}</span>
                <button v-if="canUpdate" type="button" class="btn btn-primary btn-sm btn-wave ms-auto" :disabled="busy || loading" @click="save">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span><i v-else class="ri-save-line me-1"></i>{{ t('save') }}
                </button>
            </div>
            <div v-if="loading" class="card-body text-center py-5"><span class="spinner-border spinner-border-sm"></span></div>
            <fieldset v-else class="card-body" :disabled="!canUpdate">
                <div v-for="group in groups" :key="group.key" class="mb-4">
                    <h6 class="fw-semibold mb-3"><i :class="group.icon" class="me-1 text-primary"></i>{{ t(`moving.policies.groups.${group.key}`) }}</h6>
                    <div class="row g-3">
                        <div v-for="key in group.keys" :key="key" class="col-md-6 col-xl-4">
                            <label class="form-label fs-13" :for="`mp-${key}`">{{ t(`moving.policies.keys.${key.replaceAll('.', '__')}`) }}</label>
                            <div class="input-group input-group-sm">
                                <input :id="`mp-${key}`" v-model="values[key]" type="number" min="0" step="1" class="form-control" :class="{ 'is-invalid': errorOf(key) }" :placeholder="inherited(key)" @input="clearError(key)">
                                <span v-if="unitOf(key) !== 'number'" class="input-group-text fs-12">{{ t(`moving.policies.units.${unitOf(key)}`) }}</span>
                            </div>
                            <div v-if="errorOf(key)" class="invalid-feedback d-block">{{ errorOf(key) }}</div>
                        </div>
                    </div>
                </div>
            </fieldset>
            <div class="card-footer fs-12 text-muted">{{ t('moving.policies.footer') }}</div>
        </div>
    </div>
</template>

<script setup>
import Select from 'primevue/select';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// The moving values each country sets (docs/moving_tasks.md B-01): empty = not decided yet, the rule is not applied
// (requirements DEC-020). A request keeps the values in force when it was published.
const { t } = useI18n();
const { can } = usePermission();
const { showError, showSuccess } = useToast();

const groups = [
    { key: 'offers', icon: 'ri-price-tag-3-line', keys: ['offers.window_minutes', 'offers.max_validity_hours', 'broadcast.max_radius_km', 'schedule.block_hours', 'assignment.lead_minutes'] },
    { key: 'money', icon: 'ri-wallet-3-line', keys: ['commission.bp', 'commission.min_minor', 'commission.max_minor', 'tax.bp'] },
    { key: 'delivery', icon: 'ri-shield-check-line', keys: ['delivery.objection_hours', 'dispute.max_hold_bp', 'media.max_mb'] },
    { key: 'cancellation', icon: 'ri-close-circle-line', keys: ['cancel.near_hours', 'cancel.customer.early_bp', 'cancel.customer.near_bp', 'cancel.customer.on_way_bp', 'cancel.customer.arrived_bp'] },
    { key: 'waiting', icon: 'ri-timer-line', keys: ['waiting.grace_minutes', 'waiting.fee_per_hour_minor', 'waiting.cap_minor', 'tickets.escalate_hours'] },
];

const countries = ref([]);
const rows = ref([]);
const countryId = ref(null);
const values = reactive({});
const busy = ref(false);
const loading = ref(true);
const canUpdate = computed(() => can('moving-policies.update'));
const countryOptions = computed(() => [
    { value: null, label: t('moving.policies.default_all') },
    ...countries.value.map((c) => ({ value: c.id, label: `${c.name} (${c.code})` })),
]);
// 422 messages come back as "values.offers.window_minutes"; shown under the matching field.
const errors = reactive({});
const errorOf = (key) => errors[key] ?? null;
const clearError = (key) => delete errors[key];
const clearErrors = () => Object.keys(errors).forEach((key) => delete errors[key]);
const current = computed(() => rows.value.find((r) => r.country_id === countryId.value) ?? null);
const defaults = computed(() => rows.value.find((r) => r.country_id === null)?.values ?? {});

const unitOf = (key) => {
    if (key.endsWith('_bp')) return 'bp';
    if (key.endsWith('_minor')) return 'minor';
    if (key.endsWith('_minutes')) return 'minutes';
    if (key.endsWith('_hours')) return 'hours';
    if (key.endsWith('_km')) return 'km';
    if (key.endsWith('_mb')) return 'mb';
    return 'number';
};

// A country field left empty falls back to the default row; say so in the placeholder.
const inherited = (key) => {
    if (countryId.value === null) return t('moving.policies.not_set');
    const value = defaults.value[key];
    return value === null || value === undefined || value === '' ? t('moving.policies.not_set') : `${t('moving.policies.from_default')} ${value}`;
};

function fill() {
    clearErrors();
    const source = current.value?.values ?? {};
    groups.flatMap((g) => g.keys).forEach((key) => { values[key] = source[key] ?? ''; });
}

async function load() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/moving-policies');
        countries.value = data.data.countries ?? [];
        rows.value = data.data.rows ?? [];
        fill();
    } catch (e) {
        showError(extractApiErrorMessage(e));
    } finally {
        loading.value = false;
    }
}

async function save() {
    busy.value = true;
    clearErrors();
    try {
        const payload = {};
        Object.entries(values).forEach(([key, value]) => { payload[key] = value === '' || value === null ? null : Number(value); });
        await adminAxios.put('/api/admin/v1/moving-policies', { country_id: countryId.value, values: payload });
        showSuccess(t('moving.common.saved'));
        await load();
    } catch (e) {
        Object.entries(e?.response?.data?.errors ?? {}).forEach(([key, messages]) => {
            errors[key.replace(/\\/g, '').replace(/^values\./, '')] = messages[0];
        });
        showError(extractApiErrorMessage(e));
    } finally {
        busy.value = false;
    }
}

watch(countryId, fill);
onMounted(load);
</script>
