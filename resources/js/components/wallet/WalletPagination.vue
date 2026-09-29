<template>
    <div v-if="pagination && pagination.last_page > 1" class="card-footer border-top-0">
        <div class="d-flex align-items-center flex-wrap gap-3">
            <span class="text-muted fs-13">
                {{ t('wallet.common.showing', { from: pagination.from ?? 0, to: pagination.to ?? 0, total: pagination.total ?? 0 }) }}
            </span>
            <nav class="ms-md-auto pagination-style-4">
                <ul class="pagination mb-0">
                    <li class="page-item" :class="{ disabled: pagination.current_page <= 1 }">
                        <button type="button" class="page-link" @click="$emit('change', pagination.current_page - 1)">
                            {{ t('currencies.previous') }}
                        </button>
                    </li>
                    <li v-for="p in pages" :key="p" class="page-item" :class="{ active: p === pagination.current_page }">
                        <button type="button" class="page-link" @click="$emit('change', p)">{{ p }}</button>
                    </li>
                    <li class="page-item" :class="{ disabled: pagination.current_page >= pagination.last_page }">
                        <button type="button" class="page-link text-primary" @click="$emit('change', pagination.current_page + 1)">
                            {{ t('currencies.next') }}
                        </button>
                    </li>
                </ul>
            </nav>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({ pagination: { type: Object, default: null } });

defineEmits(['change']);

const { t } = useI18n();

const pages = computed(() => {
    const last = props.pagination?.last_page ?? 1;
    const current = props.pagination?.current_page ?? 1;
    const end = Math.min(last, Math.max(1, current - 2) + 4);
    const start = Math.max(1, end - 4);

    return Array.from({ length: end - start + 1 }, (_, i) => start + i);
});
</script>
