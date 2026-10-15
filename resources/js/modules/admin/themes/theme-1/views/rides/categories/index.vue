<template>
    <div>
        <WalletPageHeader :title="t('rides.categories.title')" :section="t('sidebar.rides')" :total="rows.length || null" />

        <div class="alert alert-light border mb-3 fs-13">{{ t('rides.categories.hint') }}</div>

        <div class="row g-3">
            <div v-for="c in rows" :key="c.id" class="col-md-6 col-xl-4">
                <div class="card custom-card h-100 category-card" :style="{ '--cat': c.color || '#001B53' }">
                    <div class="card-body">
                        <div class="d-flex align-items-start gap-2">
                            <span class="avatar avatar-md avatar-rounded" :style="{ background: (c.color || '#001B53') + '22', color: c.color || '#001B53' }"><i class="ri-taxi-line fs-5"></i></span>
                            <div class="flex-grow-1">
                                <div class="fw-semibold">{{ c.name || c.slug }}</div>
                                <div class="fs-12 text-muted">{{ c.taglines?.[locale] || '' }}</div>
                            </div>
                            <span class="badge" :class="c.status ? 'bg-success-transparent' : 'bg-light text-muted'">{{ c.status ? t('wallet.common.active') : t('wallet.common.inactive') }}</span>
                        </div>
                        <div class="d-flex flex-wrap gap-1 mt-3 fs-11">
                            <span class="badge bg-light text-dark">{{ t(`rides.categories.strategy.${c.pricing_strategy}`) }}</span>
                            <span class="badge bg-light text-dark"><i class="ri-user-line me-1"></i>{{ c.capacity_min }}–{{ c.capacity_max }}</span>
                            <span v-for="f in onFlags(c)" :key="f" class="badge bg-primary-transparent">{{ t(`rides.categories.flags.${f}`) }}</span>
                        </div>
                    </div>
                    <div v-if="can('ride-categories.update')" class="card-footer bg-transparent text-end py-2">
                        <button type="button" class="btn btn-sm btn-light" @click="open(c)"><i class="ri-edit-line me-1"></i>{{ t('rides.drivers.edit') }}</button>
                    </div>
                </div>
            </div>
        </div>

        <WalletModal :show="!!form" :title="form ? (form.names.ar || form.slug) : ''" @close="form = null">
            <template v-if="form">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">{{ t('rides.document_types.name_ar') }}</label><input v-model="form.names.ar" class="form-control" dir="rtl"></div>
                    <div class="col-md-6"><label class="form-label">{{ t('rides.document_types.name_en') }}</label><input v-model="form.names.en" class="form-control" dir="ltr"></div>
                    <div class="col-md-6"><label class="form-label">{{ t('rides.categories.tagline_ar') }}</label><input v-model="form.taglines.ar" class="form-control" dir="rtl"></div>
                    <div class="col-md-6"><label class="form-label">{{ t('rides.categories.tagline_en') }}</label><input v-model="form.taglines.en" class="form-control" dir="ltr"></div>
                    <div class="col-md-3"><label class="form-label">{{ t('rides.categories.color') }}</label><input v-model="form.color" type="color" class="form-control form-control-color w-100"></div>
                    <div class="col-md-3"><label class="form-label">{{ t('rides.categories.capacity_min') }}</label><input v-model.number="form.capacity_min" type="number" min="1" class="form-control"></div>
                    <div class="col-md-3"><label class="form-label">{{ t('rides.categories.capacity_max') }}</label><input v-model.number="form.capacity_max" type="number" min="1" class="form-control"></div>
                    <div class="col-md-3"><label class="form-label">{{ t('rides.categories.sort') }}</label><input v-model.number="form.sort_order" type="number" min="0" class="form-control"></div>
                    <div class="col-12 d-flex flex-wrap gap-3">
                        <div class="form-check form-switch"><input id="cat-status" v-model="form.status" class="form-check-input" type="checkbox"><label class="form-check-label" for="cat-status">{{ t('wallet.common.active') }}</label></div>
                        <div v-for="f in flags" :key="f" class="form-check form-switch">
                            <input :id="`flag-${f}`" v-model="form.flags[f]" class="form-check-input" type="checkbox"><label class="form-check-label" :for="`flag-${f}`">{{ t(`rides.categories.flags.${f}`) }}</label>
                        </div>
                    </div>
                    <div class="col-12"><h6 class="mb-0">{{ t('rides.categories.eligibility') }}</h6></div>
                    <div class="col-md-4"><label class="form-label">{{ t('rides.categories.min_year') }}</label><input v-model.number="form.eligibility.min_year" type="number" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">{{ t('rides.categories.min_seats') }}</label><input v-model.number="form.eligibility.min_seats" type="number" class="form-control"></div>
                    <div class="col-md-4">
                        <label class="form-label">{{ t('rides.categories.driver_gender') }}</label>
                        <select v-model="form.eligibility.driver_gender" class="form-select">
                            <option :value="null">—</option><option value="male">{{ t('rides.gender.male') }}</option><option value="female">{{ t('rides.gender.female') }}</option>
                        </select>
                    </div>
                    <div class="col-12 form-check form-switch ms-2"><input id="lux" v-model="form.eligibility.luxury_only" class="form-check-input" type="checkbox"><label class="form-check-label" for="lux">{{ t('rides.categories.luxury_only') }}</label></div>
                </div>
            </template>
            <template #footer>
                <button type="button" class="btn btn-light" @click="form = null">{{ t('cancel') }}</button>
                <button type="button" class="btn btn-primary" :disabled="saving" @click="save">{{ t('save') }}</button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// The kinds of ride: names, colour, capacity, what each allows, and who may drive it.
const { t, locale } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const rows = ref([]);
const flags = ref([]);
const form = ref(null);
const saving = ref(false);

const onFlags = (c) => Object.entries(c.flags ?? {}).filter(([, v]) => v).map(([k]) => k);

function open(c) {
    const e = c.eligibility ?? {};
    form.value = {
        id: c.id, slug: c.slug, names: { ar: c.names?.ar ?? '', en: c.names?.en ?? '' }, taglines: { ar: c.taglines?.ar ?? '', en: c.taglines?.en ?? '' },
        color: c.color || '#001B53', capacity_min: c.capacity_min, capacity_max: c.capacity_max, status: c.status, sort_order: c.sort_order,
        flags: { ...c.flags }, eligibility: { min_year: e.min_year ?? null, min_seats: e.min_seats ?? null, driver_gender: e.driver_gender ?? null, luxury_only: !!e.luxury_only },
    };
}

async function save() {
    saving.value = true;
    const f = form.value;
    try {
        await adminAxios.put(`/api/admin/v1/ride-categories/${f.id}`, {
            name: f.names, tagline: f.taglines, color: f.color, capacity_min: f.capacity_min, capacity_max: f.capacity_max,
            status: f.status, sort_order: f.sort_order, flags: f.flags, eligibility: { ...f.eligibility, luxury_only: f.eligibility.luxury_only || null },
        });
        showSuccess(t('rides.drivers.saved'));
        form.value = null;
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        saving.value = false;
    }
}

async function load() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ride-categories');
        rows.value = data.data?.categories ?? [];
        flags.value = data.data?.flags ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

onMounted(load);
</script>

<style scoped>
.category-card {
    border-top: 3px solid var(--cat);
}
</style>
