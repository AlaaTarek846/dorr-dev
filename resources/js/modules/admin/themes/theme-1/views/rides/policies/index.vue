<template>
    <div>
        <WalletPageHeader :title="t('rides.policies.title')" :section="t('sidebar.rides')" />

        <div class="alert alert-light border mb-3 fs-13">{{ t('rides.policies.hint') }}</div>

        <!-- The scope: a country, optionally a city, optionally one category. -->
        <div class="card custom-card mb-3">
            <div class="card-body d-flex flex-wrap gap-2 align-items-end">
                <div>
                    <label class="form-label fs-12 mb-1">{{ t('rides.drivers.country') }}</label>
                    <select v-model="scope.country_id" class="form-select form-select-sm" style="min-width: 180px" @change="scope.city_id = null">
                        <option v-for="c in options.countries" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="form-label fs-12 mb-1">{{ t('rides.drivers.city') }}</label>
                    <select v-model="scope.city_id" class="form-select form-select-sm" style="min-width: 180px">
                        <option :value="null">{{ t('rides.switches.whole_country') }}</option>
                        <option v-for="c in cities" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="form-label fs-12 mb-1">{{ t('rides.policies.category') }}</label>
                    <select v-model="scope.category_id" class="form-select form-select-sm" style="min-width: 180px">
                        <option :value="null">{{ t('rides.policies.all_categories') }}</option>
                        <option v-for="c in options.categories" :key="c.id" :value="c.id">{{ c.name || c.slug }}</option>
                    </select>
                </div>
                <div class="ms-auto d-flex gap-2 flex-wrap">
                    <span v-for="s in scopesOfCountry" :key="s.id" class="badge bg-info-transparent" role="button" @click="jump(s)">
                        {{ scopeName(s) }} · {{ s.keys.length }}
                    </span>
                </div>
            </div>
        </div>

        <div v-if="loading" class="text-center py-5"><span class="spinner-border"></span></div>
        <div v-else class="row g-3">
            <div v-for="group in groups" :key="group.name" class="col-xl-6">
                <WalletSection :title="t(`rides.policies.groups.${group.name}`, group.name)" :icon="groupIcon(group.name)" flush>
                    <table class="table mb-0 align-middle fs-13">
                        <tbody>
                            <tr v-for="key in group.keys" :key="key" :class="{ 'policy-own': own[key] !== undefined }">
                                <td class="ps-3" style="width: 45%">
                                    <div class="fw-semibold">{{ label(key) }}</div>
                                    <code class="fs-11 text-muted">{{ key }}</code>
                                </td>
                                <td class="text-muted" style="width: 22%">{{ display(key, inherited[key]) }}</td>
                                <td class="pe-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <input :id="`o-${key}`" type="checkbox" class="form-check-input mt-0" :checked="own[key] !== undefined" :disabled="!canUpdate" @change="toggleOwn(key, $event.target.checked)">
                                        <template v-if="own[key] !== undefined">
                                            <div v-if="typeof defaults[key] === 'boolean'" class="form-check form-switch mb-0">
                                                <input v-model="own[key]" class="form-check-input" type="checkbox" :disabled="!canUpdate">
                                            </div>
                                            <select v-else-if="choices[key]" v-model="own[key]" class="form-select form-select-sm" :disabled="!canUpdate">
                                                <option v-for="o in choices[key]" :key="o" :value="o">{{ t(`rides.policies.choice.${o}`, o) }}</option>
                                            </select>
                                            <div v-else class="input-group input-group-sm">
                                                <input :value="toForm(key, own[key])" class="form-control" :disabled="!canUpdate" dir="ltr" @input="own[key] = fromForm(key, $event.target.value)">
                                                <span class="input-group-text">{{ unit(key) }}</span>
                                            </div>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </WalletSection>
            </div>
        </div>

        <div v-if="canUpdate && !loading" class="policy-bar">
            <span class="fs-13 text-muted">{{ t('rides.policies.own_count', { n: Object.keys(own).length }) }}</span>
            <button type="button" class="btn btn-primary" :disabled="saving" @click="save">
                <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}
            </button>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletSection from '../../../../../../../components/wallet/WalletSection.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// Prices & rules, one scope at a time: what this scope inherits from the broader ones, and the keys
// it changes. Money is typed in the currency (halalas are stored), rates in percent, times in seconds.
const { t, te } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const canUpdate = computed(() => can('ride-policies.update'));

const options = reactive({ countries: [], cities: [], categories: [], defaults: {} });
const scopes = ref([]);
const scope = reactive({ country_id: null, city_id: null, category_id: null });
const own = ref({});
const inherited = ref({});
const loading = ref(false);
const saving = ref(false);
const defaults = computed(() => options.defaults);
const choices = { pricing_mode: ['upfront', 'metered'], 'price.commission_type': ['percent', 'fixed'], 'gps_gap.strategy': ['route', 'straight'] };

const cities = computed(() => options.cities.filter((c) => c.country_id === scope.country_id));
const scopesOfCountry = computed(() => scopes.value.filter((s) => s.country_id === scope.country_id));
const groups = computed(() => {
    const byGroup = {};
    Object.keys(defaults.value).forEach((key) => {
        const name = key.includes('.') ? key.split('.')[0] : 'general';
        (byGroup[name] ??= []).push(key);
    });
    return Object.entries(byGroup).map(([name, keys]) => ({ name, keys }));
});

const label = (key) => {
    const k = `rides.policies.keys.${key.replaceAll('.', '__')}`;
    return te(k) ? t(k) : key.split('.').pop().replaceAll('_', ' ');
};
const isMoney = (key, current) => key.endsWith('_minor') || (key === 'price.commission_value' && (current ?? own.value['price.commission_type'] ?? inherited.value['price.commission_type']) === 'fixed');
const isPercent = (key) => key.endsWith('_bp') || (key === 'price.commission_value' && (own.value['price.commission_type'] ?? inherited.value['price.commission_type']) !== 'fixed');
const unit = (key) => (isPercent(key) ? '%' : isMoney(key) ? t('rides.policies.currency') : key.endsWith('_s') ? t('rides.policies.seconds') : key.endsWith('_m') ? 'm' : key.endsWith('_h') ? t('rides.policies.hours') : '');
const toForm = (key, v) => {
    if (Array.isArray(v)) return v.map((x) => (isMoney(key) ? x / 100 : x)).join(', ');
    if (typeof v !== 'number') return v ?? '';
    if (isPercent(key)) return v / 100;
    if (isMoney(key)) return v / 100;
    return v;
};
const fromForm = (key, text) => {
    if (Array.isArray(defaults.value[key])) return text.split(',').map((x) => Math.round(Number(x.trim()) * (isMoney(key) ? 100 : 1))).filter((x) => x > 0);
    if (typeof defaults.value[key] !== 'number') return text;
    const n = Number(text);
    if (Number.isNaN(n)) return text;
    return Math.round(isPercent(key) || isMoney(key) ? n * 100 : n);
};
const display = (key, v) => {
    if (typeof v === 'boolean') return v ? t('rides.switches.on') : t('rides.switches.off');
    if (choices[key]) return t(`rides.policies.choice.${v}`, String(v));
    const shown = toForm(key, v);
    return `${shown} ${unit(key)}`.trim();
};
const groupIcon = (name) => ({ price: 'ri-money-dollar-circle-line', search: 'ri-radar-line', cancel: 'ri-close-circle-line', waiting: 'ri-time-line', bids: 'ri-auction-line', location: 'ri-map-pin-line', settlement: 'ri-bank-line', wasl: 'ri-government-line' }[name] ?? 'ri-settings-3-line');
const scopeName = (s) => [options.cities.find((c) => c.id === s.city_id)?.name ?? t('rides.switches.whole_country'), options.categories.find((c) => c.id === s.category_id)?.name ?? t('rides.policies.all_categories')].join(' · ');

function toggleOwn(key, on) {
    const next = { ...own.value };
    if (on) next[key] = inherited.value[key] ?? defaults.value[key];
    else delete next[key];
    own.value = next;
}

function jump(s) {
    Object.assign(scope, { city_id: s.city_id, category_id: s.category_id });
}

async function load() {
    if (!scope.country_id) return;
    loading.value = true;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ride-policies', { params: { ...scope } });
        own.value = { ...(data.data?.own ?? {}) };
        inherited.value = data.data?.inherited ?? {};
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;
    try {
        const { data } = await adminAxios.put('/api/admin/v1/ride-policies', { ...scope, data: own.value });
        own.value = { ...(data.data?.own ?? {}) };
        inherited.value = data.data?.inherited ?? {};
        showSuccess(t('rides.drivers.saved'));
        loadScopes();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        saving.value = false;
    }
}

async function loadScopes() {
    const { data } = await adminAxios.get('/api/admin/v1/ride-policies/scopes');
    scopes.value = data.data ?? [];
}

watch(scope, load);

onMounted(async () => {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ride-policies/options');
        Object.assign(options, data.data ?? {});
        scope.country_id = options.countries[0]?.id ?? null;
        loadScopes();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
});
</script>

<style scoped>
.policy-own td {
    background: rgba(250, 117, 82, 0.06);
}

.policy-bar {
    position: sticky;
    bottom: 0;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 1rem;
    margin-top: 1rem;
    padding: 0.75rem 1rem;
    border-radius: 0.75rem;
    background: var(--custom-white, #fff);
    box-shadow: 0 -4px 18px rgba(0, 27, 83, 0.08);
}
</style>
