<template>
    <div class="service-category-reorder">
        <p class="text-muted fs-13 mb-3">
            {{ t('service_categories.reorder_hint') }}
        </p>

        <div v-if="loading" class="text-center py-5 text-muted">
            <span class="spinner-border spinner-border-sm me-2"></span>
            {{ t('service_categories.reorder_loading') }}
        </div>

        <div v-else-if="! roots.length" class="text-center py-5 text-muted">
            {{ t('service_categories.empty') }}
        </div>

        <template v-else>
            <h6 class="fw-semibold mb-2">{{ t('service_categories.reorder_roots') }}</h6>
            <ul ref="rootsListEl" class="service-category-reorder__list list-unstyled mb-4">
                <li
                    v-for="node in roots"
                    :key="node.id"
                    class="service-category-reorder__root-item"
                    :data-id="node.id"
                >
                    <div class="service-category-reorder__row">
                        <span
                            v-if="canUpdate"
                            class="service-category-reorder__handle drag-handle"
                            :title="t('service_categories.reorder_drag')"
                        >
                            <i class="ri-drag-move-2-line"></i>
                        </span>
                        <span class="service-category-reorder__name">{{ displayName(node) }}</span>
                        <span class="badge bg-light text-default service-category-reorder__sort-badge">{{ node.sort_order ?? '—' }}</span>
                    </div>

                    <div v-if="childNodes(node).length" class="service-category-reorder__children mt-2">
                        <div class="text-muted fs-12 mb-1">{{ t('service_categories.reorder_children') }}</div>
                        <ul
                            :ref="(el) => registerChildList(node.id, el)"
                            class="service-category-reorder__list service-category-reorder__list--nested list-unstyled mb-0"
                        >
                            <li
                                v-for="child in childNodes(node)"
                                :key="child.id"
                                class="service-category-reorder__child-item"
                                :data-id="child.id"
                            >
                                <div class="service-category-reorder__row">
                                    <span
                                        v-if="canUpdate"
                                        class="service-category-reorder__handle drag-handle"
                                        :title="t('service_categories.reorder_drag')"
                                    >
                                        <i class="ri-drag-move-2-line"></i>
                                    </span>
                                    <span class="service-category-reorder__name">{{ displayName(child) }}</span>
                                    <span class="badge bg-light text-default service-category-reorder__sort-badge">{{ child.sort_order ?? '—' }}</span>
                                </div>
                            </li>
                        </ul>
                    </div>
                </li>
            </ul>
        </template>
    </div>
</template>

<script setup>
import Sortable from 'sortablejs';
import {
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../api/adminAxios';
import useToast, { extractApiErrorMessage } from '../../composables/useToast';

const props = defineProps({
    canUpdate: { type: Boolean, default: false },
    locale: { type: String, default: 'en' },
});

const { t } = useI18n();
const { showError } = useToast();

const loading = ref(true);
const roots = ref([]);
const rootsListEl = ref(null);
const childListEls = new Map();

/** @type {import('sortablejs').Sortable[]} */
const sortableInstances = [];

function displayName(record) {
    const translations = Array.isArray(record?.translations) ? record.translations : [];
    const match = translations.find((item) => item.locale === props.locale);

    return match?.name || record?.name || `#${record?.id ?? ''}`;
}

function childNodes(node) {
    const children = node?.children;

    if (Array.isArray(children)) {
        return children;
    }

    return [];
}

function registerChildList(parentId, el) {
    if (el) {
        childListEls.set(parentId, el);
    } else {
        childListEls.delete(parentId);
    }
}

async function loadTree(options = {}) {
    const { silent = false } = options;

    if (! silent) {
        loading.value = true;
    }

    try {
        const { data } = await adminAxios.get('/api/admin/v1/service-categories/tree');
        roots.value = data.data ?? [];
    } catch (error) {
        roots.value = [];
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        if (! silent) {
            loading.value = false;
        }
        await nextTick();
        initSortables();
    }
}

function destroySortables() {
    while (sortableInstances.length) {
        sortableInstances.pop()?.destroy();
    }
}

function collectOrderedIds(listEl) {
    if (! listEl) {
        return [];
    }

    return [...listEl.children]
        .filter((item) => item.matches('li[data-id]'))
        .map((item) => Number(item.dataset.id))
        .filter((id) => Number.isFinite(id));
}

function updateSortBadges(listEl) {
    if (! listEl) {
        return;
    }

    [...listEl.children]
        .filter((item) => item.matches('li[data-id]'))
        .forEach((item, index) => {
            const badge = item.querySelector('.service-category-reorder__sort-badge');

            if (badge) {
                badge.textContent = String(index + 1);
            }
        });
}

function syncSortOrdersInMemory(parentId, orderedIds) {
    if (parentId === null) {
        const map = new Map(roots.value.map((node) => [node.id, node]));

        orderedIds.forEach((id, index) => {
            const node = map.get(id);

            if (node) {
                node.sort_order = index + 1;
            }
        });

        return;
    }

    const parent = roots.value.find((node) => node.id === parentId);

    if (! parent || ! Array.isArray(parent.children)) {
        return;
    }

    const map = new Map(parent.children.map((node) => [node.id, node]));

    orderedIds.forEach((id, index) => {
        const node = map.get(id);

        if (node) {
            node.sort_order = index + 1;
        }
    });
}

async function persistReorder(parentId, listEl, orderedIds, sortableEvent = null) {
    if (! props.canUpdate || ! orderedIds.length) {
        return;
    }

    if (
        sortableEvent
        && sortableEvent.oldIndex === sortableEvent.newIndex
        && sortableEvent.from === sortableEvent.to
    ) {
        return;
    }

    try {
        await adminAxios.put('/api/admin/v1/service-categories/reorder', {
            parent_id: parentId,
            ordered_ids: orderedIds,
        });

        updateSortBadges(listEl);
        syncSortOrdersInMemory(parentId, orderedIds);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
        await loadTree({ silent: true });
    }
}

function initSortables() {
    destroySortables();

    if (! props.canUpdate) {
        return;
    }

    if (rootsListEl.value) {
        sortableInstances.push(Sortable.create(rootsListEl.value, {
            handle: '.drag-handle',
            animation: 160,
            draggable: '.service-category-reorder__root-item',
            group: {
                name: 'service-category-roots',
                pull: false,
                put: false,
            },
            onEnd: (event) => {
                persistReorder(
                    null,
                    rootsListEl.value,
                    collectOrderedIds(rootsListEl.value),
                    event,
                );
            },
        }));
    }

    for (const [parentId, el] of childListEls.entries()) {
        sortableInstances.push(Sortable.create(el, {
            handle: '.drag-handle',
            animation: 160,
            draggable: '.service-category-reorder__child-item',
            group: {
                name: `service-category-children-${parentId}`,
                pull: false,
                put: false,
            },
            onEnd: (event) => {
                persistReorder(
                    parentId,
                    el,
                    collectOrderedIds(el),
                    event,
                );
            },
        }));
    }
}

watch(
    () => props.canUpdate,
    async () => {
        await nextTick();
        initSortables();
    },
);

onMounted(() => {
    loadTree();
});

onBeforeUnmount(() => {
    destroySortables();
});

defineExpose({ reload: loadTree });
</script>

<style scoped>
.service-category-reorder__list {
    margin: 0;
    padding: 0;
}

.service-category-reorder__list--nested {
    padding-inline-start: 1.25rem;
    border-inline-start: 2px solid var(--default-border, #dee2e6);
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
