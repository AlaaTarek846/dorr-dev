<template>
    <div class="faq-reorder">
        <div class="d-flex flex-wrap align-items-end gap-3 mb-3">
            <div class="faq-reorder__service">
                <label for="faq-reorder-service" class="form-label mb-1">{{ t('faqs.service') }}</label>
                <Select
                    id="faq-reorder-service"
                    v-model="selectedServiceId"
                    :options="serviceChoices"
                    option-label="name"
                    option-value="id"
                    :filter="true"
                    :filter-fields="['name']"
                    append-to="self"
                    auto-filter-focus
                    class="w-100"
                />
            </div>
            <p class="text-muted fs-13 mb-0 flex-grow-1">
                {{ t('faqs.reorder_hint') }}
            </p>
        </div>

        <div v-if="loading" class="text-center py-5 text-muted">
            <span class="spinner-border spinner-border-sm me-2"></span>
            {{ t('faqs.reorder_loading') }}
        </div>

        <div v-else-if="! items.length" class="text-center py-5 text-muted">
            {{ t('faqs.reorder_empty') }}
        </div>

        <ul v-else ref="listEl" class="faq-reorder__list list-unstyled mb-0">
            <li
                v-for="item in items"
                :key="item.id"
                class="faq-reorder__item"
                :data-id="item.id"
            >
                <div class="faq-reorder__row">
                    <span
                        v-if="canUpdate"
                        class="faq-reorder__handle drag-handle"
                        :title="t('faqs.reorder_drag')"
                    >
                        <i class="ri-drag-move-2-line"></i>
                    </span>
                    <span class="faq-reorder__name">{{ displayQuestion(item) }}</span>
                    <span v-if="! item.status" class="badge bg-secondary-transparent">{{ t('faqs.inactive') }}</span>
                    <span class="badge bg-light text-default faq-reorder__sort-badge">{{ item.sort_order ?? '—' }}</span>
                </div>
            </li>
        </ul>
    </div>
</template>

<script setup>
import Sortable from 'sortablejs';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { useI18n } from 'vue-i18n';
import Select from 'primevue/select';
import adminAxios from '../../api/adminAxios';
import useToast, { extractApiErrorMessage } from '../../composables/useToast';

const GENERAL_OPTION_ID = 0;

const props = defineProps({
    canUpdate: { type: Boolean, default: false },
    locale: { type: String, default: 'en' },
});

const { t } = useI18n();
const { showError } = useToast();

const loading = ref(true);
const items = ref([]);
const listEl = ref(null);
const serviceOptions = ref([]);
const selectedServiceId = ref(GENERAL_OPTION_ID);

/** @type {import('sortablejs').Sortable|null} */
let sortable = null;

const serviceChoices = computed(() => [
    { id: GENERAL_OPTION_ID, name: t('faqs.general') },
    ...serviceOptions.value,
]);

const serviceIdParam = computed(() => (
    selectedServiceId.value && selectedServiceId.value !== GENERAL_OPTION_ID
        ? selectedServiceId.value
        : null
));

function displayQuestion(record) {
    const translations = Array.isArray(record?.translations) ? record.translations : [];
    const match = translations.find((item) => item.locale === props.locale);

    return match?.question || record?.question || `#${record?.id ?? ''}`;
}

async function loadServiceOptions() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/service-categories/dropdown');
        serviceOptions.value = data.data ?? [];
    } catch {
        serviceOptions.value = [];
    }
}

async function loadItems(options = {}) {
    const { silent = false } = options;

    if (! silent) {
        loading.value = true;
    }

    try {
        const { data } = await adminAxios.get('/api/admin/v1/faqs/ordered', {
            params: serviceIdParam.value ? { service_id: serviceIdParam.value } : {},
        });
        items.value = data.data ?? [];
    } catch (error) {
        items.value = [];
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        if (! silent) {
            loading.value = false;
        }
        await nextTick();
        initSortable();
    }
}

function destroySortable() {
    sortable?.destroy();
    sortable = null;
}

function collectOrderedIds() {
    if (! listEl.value) {
        return [];
    }

    return [...listEl.value.children]
        .filter((item) => item.matches('li[data-id]'))
        .map((item) => Number(item.dataset.id))
        .filter((id) => Number.isFinite(id));
}

function syncSortOrders(orderedIds) {
    const map = new Map(items.value.map((item) => [item.id, item]));

    orderedIds.forEach((id, index) => {
        const item = map.get(id);

        if (item) {
            item.sort_order = index + 1;
        }
    });

    if (! listEl.value) {
        return;
    }

    [...listEl.value.children]
        .filter((item) => item.matches('li[data-id]'))
        .forEach((item, index) => {
            const badge = item.querySelector('.faq-reorder__sort-badge');

            if (badge) {
                badge.textContent = String(index + 1);
            }
        });
}

async function persistReorder(event) {
    if (! props.canUpdate || event.oldIndex === event.newIndex) {
        return;
    }

    const orderedIds = collectOrderedIds();

    if (! orderedIds.length) {
        return;
    }

    try {
        await adminAxios.put('/api/admin/v1/faqs/reorder', {
            service_id: serviceIdParam.value,
            ordered_ids: orderedIds,
        });

        syncSortOrders(orderedIds);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
        await loadItems({ silent: true });
    }
}

function initSortable() {
    destroySortable();

    if (! props.canUpdate || ! listEl.value) {
        return;
    }

    sortable = Sortable.create(listEl.value, {
        handle: '.drag-handle',
        animation: 160,
        draggable: '.faq-reorder__item',
        onEnd: persistReorder,
    });
}

watch(selectedServiceId, () => {
    loadItems();
});

watch(
    () => props.canUpdate,
    async () => {
        await nextTick();
        initSortable();
    },
);

onMounted(() => {
    loadServiceOptions();
    loadItems();
});

onBeforeUnmount(() => {
    destroySortable();
});

defineExpose({ reload: loadItems });
</script>

<style scoped>
.faq-reorder__service {
    width: 280px;
    max-width: 100%;
}

.faq-reorder__list {
    margin: 0;
    padding: 0;
}

.faq-reorder__item {
    margin-bottom: 0.5rem;
}

.faq-reorder__row {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.55rem 0.75rem;
    border: 1px solid var(--default-border, #dee2e6);
    border-radius: 0.375rem;
    background: var(--custom-white, #fff);
}

.faq-reorder__handle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: var(--text-muted, #8c9097);
    cursor: grab;
    font-size: 1.125rem;
    line-height: 1;
}

.faq-reorder__handle:active {
    cursor: grabbing;
}

.faq-reorder__name {
    flex: 1 1 auto;
    min-width: 0;
    font-weight: 500;
    white-space: normal;
}

[data-theme-mode='dark'] .faq-reorder__row,
html.app-dark .faq-reorder__row {
    background: var(--form-control-bg, #232628);
    border-color: var(--input-border, #313335);
    color: var(--default-text-color, rgba(255, 255, 255, 0.85));
}
</style>
