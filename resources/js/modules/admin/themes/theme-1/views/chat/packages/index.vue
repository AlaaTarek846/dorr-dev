<template>
    <div>
        <WalletPageHeader :title="t('chat.packages.title')" :section="t('sidebar.chat')" :total="rows.length || null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="btn-group btn-group-sm" role="group">
                    <button v-for="k in kindFilters" :key="k.value" type="button" class="btn" :class="kind === k.value ? 'btn-primary' : 'btn-outline-primary'" @click="kind = k.value">{{ k.label }}</button>
                </div>
                <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave ms-auto" @click="openCreate">
                    <i class="ri-add-line me-1 align-middle"></i>{{ t('chat.packages.add') }}
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('chat.common.name') }}</th>
                                <th>{{ t('chat.packages.kind') }}</th>
                                <th>{{ t('chat.packages.duration') }}</th>
                                <th>{{ t('chat.packages.prices') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="6" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!visibleRows.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <template v-else>
                                <tr v-for="row in visibleRows" :key="row.id">
                                    <td class="ps-4 fw-semibold">{{ row.name || `#${row.id}` }}</td>
                                    <td>
                                        <span class="badge" :class="row.kind === 'portal' ? 'bg-info-transparent' : 'bg-success-transparent'">
                                            <i :class="row.kind === 'portal' ? 'ri-store-2-line' : 'ri-verified-badge-line'" class="me-1"></i>{{ t(`chat.packages.kinds.${row.kind}`) }}
                                        </span>
                                    </td>
                                    <td>{{ durationLabel(row) }}</td>
                                    <td>
                                        <span v-for="p in row.prices" :key="p.country_id" class="badge bg-light text-dark me-1">{{ p.country_code }} · {{ money(p.amount_minor) }} {{ p.currency_code }}</span>
                                    </td>
                                    <td>
                                        <div v-if="canUpdate" class="toggle toggle-success mb-0" :class="{ on: row.status }" role="button" @click="toggleStatus(row)"><span></span></div>
                                        <span v-else class="badge" :class="row.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">{{ row.status ? t('wallet.common.active') : t('wallet.common.inactive') }}</span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="btn-list justify-content-end">
                                            <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" @click="openEdit(row)"><i class="ri-pencil-line"></i></button>
                                            <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" @click="remove(row)"><i class="ri-delete-bin-line"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <WalletModal :show="showModal" :title="editingId ? t('chat.packages.edit') : t('chat.packages.add')" size="lg" @close="showModal = false">
            <form id="chat-package-form" @submit.prevent="save">
                <div class="row g-2 mb-3">
                    <div class="col-md-5">
                        <label class="form-label">{{ t('chat.packages.kind') }} <span class="text-danger">*</span></label>
                        <select v-model="form.kind" class="form-select">
                            <option value="portal">{{ t('chat.packages.kinds.portal') }}</option>
                            <option value="channel_verification">{{ t('chat.packages.kinds.channel_verification') }}</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ t('chat.packages.count') }} <span class="text-danger">*</span></label>
                        <input v-model.number="form.period_count" type="number" min="1" max="60" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ t('chat.packages.period') }} <span class="text-danger">*</span></label>
                        <select v-model="form.period" class="form-select">
                            <option v-for="p in ['week', 'month', 'year']" :key="p" :value="p">{{ t(`chat.packages.periods.${p}`) }}</option>
                        </select>
                    </div>
                </div>

                <CatalogTranslationTabs
                    :languages="languages"
                    :active-locale="activeLocale"
                    :translation-tab-class="tabClass"
                    :translation-tab-feedback="tabFeedback"
                    @update:active-locale="activeLocale = $event"
                />
                <div v-if="form.translations[activeLocale]" class="mb-3">
                    <label class="form-label">{{ t('chat.common.name') }} <span class="text-danger">*</span></label>
                    <input v-model="form.translations[activeLocale].name" type="text" maxlength="100" class="form-control" :placeholder="t('chat.packages.name_hint')">
                    <label class="form-label mt-2">{{ t('chat.packages.description') }}</label>
                    <textarea v-model="form.translations[activeLocale].description" rows="2" maxlength="500" class="form-control"></textarea>
                    <div v-if="errors.translations" class="text-danger fs-12 mt-1">{{ errors.translations }}</div>
                </div>

                <label class="form-label d-flex align-items-center">{{ t('chat.packages.prices') }} <span class="text-danger ms-1">*</span>
                    <small class="text-muted ms-2">{{ t('chat.packages.prices_hint') }}</small>
                </label>
                <div v-for="(price, i) in form.prices" :key="i" class="row g-2 mb-2 align-items-center">
                    <div class="col-6">
                        <select v-model="price.country_id" class="form-select form-select-sm">
                            <option :value="null" disabled>{{ t('chat.packages.country') }}</option>
                            <option v-for="c in countries" :key="c.id" :value="c.id" :disabled="form.prices.some((p, j) => j !== i && p.country_id === c.id)">{{ c.name }} ({{ c.code }})</option>
                        </select>
                    </div>
                    <div class="col-4">
                        <input v-model="price.amount" type="number" min="0.01" step="0.01" class="form-control form-control-sm" :placeholder="t('chat.packages.price')">
                    </div>
                    <div class="col-2 text-end">
                        <button type="button" class="btn btn-sm btn-danger-light btn-icon" :disabled="form.prices.length === 1" @click="form.prices.splice(i, 1)"><i class="ri-close-line"></i></button>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-light" @click="form.prices.push({ country_id: null, amount: '' })"><i class="ri-add-line me-1"></i>{{ t('chat.packages.add_country') }}</button>
                <div v-if="errors.prices" class="text-danger fs-12 mt-1">{{ errors.prices }}</div>

                <div class="row g-2 align-items-end mt-2">
                    <div class="col-5">
                        <label class="form-label">{{ t('chat.common.sort_order') }}</label>
                        <input v-model.number="form.sort_order" type="number" min="0" class="form-control">
                    </div>
                    <div class="col-7">
                        <div class="form-check form-switch mb-2"><input id="pkg-status" v-model="form.status" class="form-check-input" type="checkbox"><label class="form-check-label" for="pkg-status">{{ t('wallet.common.active') }}</label></div>
                    </div>
                </div>
                <div v-if="errors.general" class="text-danger mt-2 fs-13">{{ errors.general }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="saving" @click="showModal = false">{{ t('cancel') }}</button>
                <button type="submit" form="chat-package-form" class="btn btn-primary" :disabled="saving">
                    <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}
                </button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import CatalogTranslationTabs from '../../../../../../../components/catalog/CatalogTranslationTabs.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';
import { useAvailableLanguagesStore } from '../../../../../../../stores/availableLanguages';

const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const languagesStore = useAvailableLanguagesStore();

const canCreate = computed(() => can('chat-packages.create'));
const canUpdate = computed(() => can('chat-packages.update'));
const canDelete = computed(() => can('chat-packages.delete'));

const languages = computed(() => languagesStore.items);
const activeLocale = ref('');
const countries = ref([]);
const rows = ref([]);
const loading = ref(false);
const kind = ref('');
const kindFilters = computed(() => [
    { value: '', label: t('chat.packages.all') },
    { value: 'portal', label: t('chat.packages.kinds.portal') },
    { value: 'channel_verification', label: t('chat.packages.kinds.channel_verification') },
]);
const visibleRows = computed(() => (kind.value ? rows.value.filter((row) => row.kind === kind.value) : rows.value));

const showModal = ref(false);
const editingId = ref(null);
const saving = ref(false);
const errors = reactive({});
const form = reactive({ kind: 'portal', period: 'month', period_count: 1, sort_order: 0, status: true, translations: {}, prices: [] });

const money = (minor) => (Number(minor || 0) / 100).toLocaleString(undefined, { maximumFractionDigits: 2 });

function durationLabel(row) {
    return `${row.period_count} × ${t(`chat.packages.periods.${row.period}`)}`;
}

function tabClass(code) {
    const filled = !!(form.translations[code]?.name || '').trim();

    return { active: activeLocale.value === code, 'catalog-lang-tab--error': !!errors.translations && !filled, 'catalog-lang-tab--valid': filled };
}

function tabFeedback(code) {
    const filled = !!(form.translations[code]?.name || '').trim();

    return { show: filled || !!errors.translations, valid: filled };
}

function ensureTranslations() {
    languages.value.forEach((lang) => { form.translations[lang.code] ??= { name: '', description: '' }; });
    if (!activeLocale.value && languages.value.length) activeLocale.value = languages.value[0].code;
}

function resetErrors() {
    Object.keys(errors).forEach((key) => delete errors[key]);
}

function openCreate() {
    editingId.value = null;
    resetErrors();
    const defaultCountry = countries.value.find((c) => c.is_default) ?? countries.value[0];
    Object.assign(form, {
        kind: kind.value || 'portal', period: 'month', period_count: 1, sort_order: rows.value.length, status: true,
        translations: {}, prices: [{ country_id: defaultCountry?.id ?? null, amount: '' }],
    });
    ensureTranslations();
    activeLocale.value = languages.value[0]?.code || '';
    showModal.value = true;
}

function openEdit(row) {
    editingId.value = row.id;
    resetErrors();
    Object.assign(form, {
        kind: row.kind, period: row.period, period_count: row.period_count, sort_order: row.sort_order ?? 0, status: !!row.status,
        translations: {}, prices: (row.prices ?? []).map((p) => ({ country_id: p.country_id, amount: p.amount_minor / 100 })),
    });
    if (!form.prices.length) form.prices.push({ country_id: null, amount: '' });
    ensureTranslations();
    (row.translations ?? []).forEach((tr) => { form.translations[tr.locale] = { name: tr.name ?? '', description: tr.description ?? '' }; });
    activeLocale.value = languages.value[0]?.code || '';
    showModal.value = true;
}

async function save() {
    resetErrors();
    saving.value = true;

    const body = {
        kind: form.kind,
        period: form.period,
        period_count: form.period_count || 1,
        sort_order: form.sort_order || 0,
        status: form.status,
        translations: languages.value.map((lang) => ({ locale: lang.code, name: form.translations[lang.code]?.name, description: form.translations[lang.code]?.description || null })),
        prices: form.prices.filter((p) => p.country_id).map((p) => ({ country_id: p.country_id, amount_minor: Math.round(Number(p.amount || 0) * 100) })),
    };

    try {
        if (editingId.value) {
            await adminAxios.put(`/api/admin/v1/chat-packages/${editingId.value}`, body);
        } else {
            await adminAxios.post('/api/admin/v1/chat-packages', body);
        }

        showSuccess(t('chat.packages.saved'));
        showModal.value = false;
        load();
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};

        Object.entries(bag).forEach(([key, messages]) => {
            const field = key.startsWith('translations') ? 'translations' : (key.startsWith('prices') ? 'prices' : key);
            errors[field] ??= messages[0];
        });
        errors.general = Object.keys(bag).length ? '' : extractApiErrorMessage(error);

        const missing = languages.value.find((lang) => !(form.translations[lang.code]?.name || '').trim());
        if (errors.translations && missing) activeLocale.value = missing.code;
    } finally {
        saving.value = false;
    }
}

async function toggleStatus(row) {
    try {
        await adminAxios.patch(`/api/admin/v1/chat-packages/${row.id}/status`, { status: !row.status });
        row.status = !row.status;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function remove(row) {
    if (!window.confirm(t('chat.packages.confirm_delete', { name: row.name || `#${row.id}` }))) {
        return;
    }

    try {
        await adminAxios.delete(`/api/admin/v1/chat-packages/${row.id}`);
        showSuccess(t('chat.common.deleted'));
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function load() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/chat-packages');

        rows.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

watch(languages, () => ensureTranslations(), { immediate: true });

onMounted(async () => {
    load();
    await languagesStore.fetch();
    try {
        const { data } = await adminAxios.get('/api/admin/v1/countries/dropdown');
        countries.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
});
</script>
