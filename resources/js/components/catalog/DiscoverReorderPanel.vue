<template>
    <div class="service-category-reorder">
        <p class="text-muted fs-13 mb-3">{{ t('discover.reorder.hint') }}</p>

        <div v-if="kind === 'cities'" class="mb-3" style="max-width: 320px;">
            <Select
                v-model="selectedKey"
                :options="countryOptions"
                option-label="label"
                option-value="value"
                filter
                :filter-placeholder="t('search_placeholder')"
                append-to="self"
                class="w-100"
            />
        </div>

        <div v-if="loading" class="text-center py-5 text-muted">
            <span class="spinner-border spinner-border-sm me-2"></span>
            {{ t('service_categories.reorder_loading') }}
        </div>

        <div v-else-if="!groups.length" class="text-center py-5 text-muted">
            {{ t('wallet.common.empty') }}
        </div>

        <template v-else>
            <div v-for="group in groups" :key="group.key" class="mb-4">
                <h6 v-if="group.title && kind !== 'cities'" class="fw-semibold mb-2">{{ group.title }}</h6>
                <ul :ref="(el) => registerList(group.key, el)" class="service-category-reorder__list list-unstyled mb-0">
                    <li v-for="item in group.items" :key="item.id" class="service-category-reorder__item" :data-id="item.id">
                        <div class="service-category-reorder__row">
                            <span v-if="canUpdate" class="service-category-reorder__handle drag-handle" :title="t('service_categories.reorder_drag')">
                                <i class="ri-drag-move-2-line"></i>
                            </span>
                            <span v-if="kind === 'categories'" class="avatar avatar-sm avatar-rounded" :style="{ background: item.color || '#eef' }"><span class="fs-15">{{ item.emoji || '📍' }}</span></span>
                            <span class="service-category-reorder__name">{{ item.name || `#${item.id}` }}</span>
                            <span class="badge bg-light text-default service-category-reorder__sort-badge">{{ item.sort_order ?? '—' }}</span>
                        </div>
                    </li>
                </ul>
            </div>
        </template>
    </div>
</template>

<script setup>
import Sortable from 'sortablejs';
import Select from 'primevue/select';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../api/adminAxios';
import useToast, { extractApiErrorMessage } from '../../composables/useToast';

const props = defineProps({
    /** 'categories' (one list) or 'cities' (one list per country). */
    kind: { type: String, required: true },
    /** Cities: the country to start on (else the default country). */
    initialCountryId: { type: [Number, String], default: null },
    canUpdate: { type: Boolean, default: false },
});

const { t } = useI18n();
const { showError } = useToast();

const base = computed(() => `/api/admin/v1/${props.kind === 'categories' ? 'discover-categories' : 'discover-cities'}`);
const loading = ref(true);
const rows = ref([]);
const listEls = new Map();
const sortables = [];

const countries = ref([]);
const selectedKey = ref(null);
const countryOptions = computed(() => countries.value.map((c) => ({ value: c.id, label: [c.name, c.code].filter(Boolean).join(' · ') })));

/** Cities: the chosen country's cities (loaded from the API when the country changes); categories: one list. */
const groups = computed(() => (rows.value.length ? [{ key: props.kind === 'cities' ? String(selectedKey.value) : 'all', title: '', items: rows.value }] : []));

async function loadCountries() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/countries/dropdown');
        countries.value = data?.data ?? [];
    } catch {
        countries.value = [];
    }

    // Start on the filtered country, else the default one.
    selectedKey.value = countries.value.find((c) => c.id === Number(props.initialCountryId))?.id
        ?? countries.value.find((c) => c.is_default)?.id
        ?? countries.value[0]?.id
        ?? null;
}

function registerList(key, el) {
    if (el) listEls.set(key, el); else listEls.delete(key);
}

function orderedIds(el) {
    return [...el.children].filter((li) => li.matches('li[data-id]')).map((li) => Number(li.dataset.id));
}

async function load(silent = false) {
    if (!silent) loading.value = true;
    try {
        const params = props.kind === 'cities' ? { country_id: selectedKey.value } : {};
        const { data } = await adminAxios.get(base.value, { params });
        rows.value = data.data ?? [];
    } catch (error) {
        rows.value = [];
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
        await nextTick();
        initSortables();
    }
}

function destroySortables() {
    while (sortables.length) sortables.pop()?.destroy();
}

async function persist(el, event) {
    if (event.oldIndex === event.newIndex) return;
    const ids = orderedIds(el);
    try {
        await adminAxios.put(`${base.value}/reorder`, { ordered_ids: ids });
        [...el.children].filter((li) => li.matches('li[data-id]')).forEach((li, index) => {
            const badge = li.querySelector('.service-category-reorder__sort-badge');
            if (badge) badge.textContent = String(index + 1);
        });
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
        await load(true);
    }
}

function initSortables() {
    destroySortables();
    if (!props.canUpdate) return;
    listEls.forEach((el, key) => {
        sortables.push(Sortable.create(el, {
            handle: '.drag-handle',
            animation: 160,
            draggable: '.service-category-reorder__item',
            group: { name: `discover-${props.kind}-${key}`, pull: false, put: false },
            onEnd: (event) => persist(el, event),
        }));
    });
}

watch(selectedKey, (countryId, old) => { if (props.kind === 'cities' && countryId && countryId !== old) load(); });
watch(() => props.canUpdate, async () => { await nextTick(); initSortables(); });
onMounted(async () => {
    if (props.kind === 'cities') {
        await loadCountries(); // choosing the country loads its cities (the watcher above)
        if (selectedKey.value === null) loading.value = false;

        return;
    }

    load();
});
onBeforeUnmount(destroySortables);

defineExpose({ reload: load });
</script>

<style scoped>
.service-category-reorder__list {
    margin: 0;
    padding: 0;
}

.service-category-reorder__item {
    margin-bottom: 0.5rem;
}

.service-category-reorder__row {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.55rem 0.75rem;
    border: 1px solid var(--default-border, #dee2e6);
    border-radius: 0.375rem;
    background: var(--custom-white, #fff);
}

.service-category-reorder__handle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: var(--text-muted, #8c9097);
    cursor: grab;
    font-size: 1.125rem;
    line-height: 1;
}

.service-category-reorder__handle:active {
    cursor: grabbing;
}

.service-category-reorder__name {
    flex: 1 1 auto;
    min-width: 0;
    font-weight: 500;
}

[data-theme-mode='dark'] .service-category-reorder__row,
html.app-dark .service-category-reorder__row {
    background: var(--form-control-bg, #232628);
    border-color: var(--input-border, #313335);
    color: var(--default-text-color, rgba(255, 255, 255, 0.85));
}
</style>
