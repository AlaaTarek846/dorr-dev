<template>
    <div>
        <WalletPageHeader :title="t('moving.policies.title')" :section="t('sidebar.moving')" />

        <div class="alert alert-light border mb-3 fs-13">{{ t('moving.policies.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex flex-wrap gap-2 align-items-center py-3">
                <select v-model="countryId" class="form-select form-select-sm" style="max-width: 260px">
                    <option :value="null">{{ t('moving.policies.default_all') }}</option>
                    <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }} ({{ c.code }})</option>
                </select>
                <span v-if="current?.version" class="badge bg-light text-dark">{{ t('moving.policies.version') }} {{ current.version }}</span>
                <button v-if="can('moving-policies.update')" type="button" class="btn btn-primary btn-sm ms-auto" :disabled="busy" @click="save">
                    <i class="ri-save-line me-1"></i>{{ t('save') }}
                </button>
            </div>
            <div class="card-body">
                <div v-for="group in groups" :key="group.key" class="mb-4">
                    <h6 class="fw-semibold mb-3"><i :class="group.icon" class="me-1 text-primary"></i>{{ t(`moving.policies.groups.${group.key}`) }}</h6>
                    <div class="row g-3">
                        <div v-for="key in group.keys" :key="key" class="col-md-6 col-xl-4">
                            <label class="form-label fs-13">{{ t(`moving.policies.keys.${key.replaceAll('.', '__')}`) }}</label>
                            <div class="input-group input-group-sm">
                                <input v-model="values[key]" type="number" min="0" class="form-control" :placeholder="inherited(key)" :disabled="!can('moving-policies.update')">
                                <span class="input-group-text fs-12">{{ t(`moving.policies.units.${unitOf(key)}`) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer fs-12 text-muted">{{ t('moving.policies.footer') }}</div>
        </div>
    </div>
</template>

<script setup>
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
    }
}

async function save() {
    busy.value = true;
    try {
        const payload = {};
        Object.entries(values).forEach(([key, value]) => { payload[key] = value === '' || value === null ? null : Number(value); });
        await adminAxios.put('/api/admin/v1/moving-policies', { country_id: countryId.value, values: payload });
        showSuccess(t('moving.common.saved'));
        await load();
    } catch (e) {
        showError(extractApiErrorMessage(e));
    } finally {
        busy.value = false;
    }
}

watch(countryId, fill);
onMounted(load);
</script>
