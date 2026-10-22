<template>
    <div class="service-category-reorder">
        <p class="text-muted fs-13 mb-3">{{ t('freelance.specialties.reorder_hint') }}</p>

        <div v-if="!loading" class="mb-3" style="max-width: 320px;">
            <Select
                v-model="levelKey"
                :options="levelOptions"
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

        <div v-else-if="!items.length" class="text-center py-5 text-muted">
            {{ t('wallet.common.empty') }}
        </div>

        <ul v-else ref="listEl" class="service-category-reorder__list list-unstyled mb-0">
            <li v-for="item in items" :key="item.id" class="service-category-reorder__item" :data-id="item.id">
                <div class="service-category-reorder__row">
                    <span v-if="canUpdate" class="service-category-reorder__handle drag-handle" :title="t('service_categories.reorder_drag')">
                        <i class="ri-drag-move-2-line"></i>
                    </span>
                    <span class="service-category-reorder__name">{{ item.name || `#${item.id}` }}</span>
                    <span class="badge bg-light text-default service-category-reorder__sort-badge">{{ item.sort_order ?? '—' }}</span>
                </div>
            </li>
        </ul>
    </div>
</template>

<script setup>
import Sortable from 'sortablejs';
import Select from 'primevue/select';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../api/adminAxios';
import useToast, { extractApiErrorMessage } from '../../composables/useToast';

defineProps({ canUpdate: { type: Boolean, default: false } });

const { t } = useI18n();
const { showError } = useToast();

const endpoint = '/api/admin/v1/freelance-specialties';
const loading = ref(true);
const rows = ref([]);
const levelKey = ref('main');
const listEl = ref(null);
let sortable = null;

/** One level at a time: the main specialties, or the sub-specialties of one of them. */
const levelOptions = computed(() => [
    { value: 'main', label: t('freelance.specialties.main') },
    ...rows.value.filter((r) => r.parent_id === null && r.children_count > 0).map((r) => ({ value: String(r.id), label: r.name })),
]);
const items = computed(() => rows.value.filter((r) => (levelKey.value === 'main' ? r.parent_id === null : String(r.parent_id) === levelKey.value)));

async function load(silent = false) {
    if (!silent) loading.value = true;
    try {
        const { data } = await adminAxios.get(endpoint);
        rows.value = data.data ?? [];
    } catch (error) {
        rows.value = [];
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
        await nextTick();
        init();
    }
}

function init() {
    sortable?.destroy();
    sortable = null;
    if (!listEl.value) return;
    sortable = Sortable.create(listEl.value, {
        handle: '.drag-handle',
        animation: 160,
        draggable: '.service-category-reorder__item',
        onEnd: async (event) => {
            if (event.oldIndex === event.newIndex) return;
            const ids = [...listEl.value.children].filter((li) => li.matches('li[data-id]')).map((li) => Number(li.dataset.id));
            try {
                await adminAxios.put(`${endpoint}/reorder`, { ordered_ids: ids });
                [...listEl.value.children].filter((li) => li.matches('li[data-id]')).forEach((li, index) => {
                    const badge = li.querySelector('.service-category-reorder__sort-badge');
                    if (badge) badge.textContent = String(index + 1);
                });
            } catch (error) {
                showError(extractApiErrorMessage(error, t('toast.error')));
                await load(true);
            }
        },
    });
}

watch(levelKey, async () => { await nextTick(); init(); });
onMounted(() => load());
onBeforeUnmount(() => sortable?.destroy());

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
