<template>
    <div v-if="pagination && !loading" class="card-footer border-top-0">
        <div class="d-flex align-items-center flex-wrap gap-3">
            <div class="d-flex align-items-center gap-2 text-muted fs-13">
                <span>{{ entriesLabel }}</span>
            </div>

            <div class="d-flex align-items-center gap-2 ms-md-auto">
                <label class="text-muted fs-13 mb-0" :for="perPageId">{{ t('pagination.per_page') }}</label>
                <select
                    :id="perPageId"
                    :value="perPage"
                    class="form-select form-select-sm w-auto"
                    @change="onPerPageChange($event.target.value)"
                >
                    <option v-for="option in perPageOptions" :key="option" :value="option">
                        {{ option }}
                    </option>
                </select>
            </div>

            <nav aria-label="Pagination" class="pagination-style-4">
                <ul class="pagination mb-0">
                    <li class="page-item" :class="{ disabled: !pagination.prev_page_url }">
                        <button type="button" class="page-link" @click="onChangePage(currentPage - 1)">
                            {{ t('pagination.previous') }}
                        </button>
                    </li>
                    <li
                        v-for="page in pageNumbers"
                        :key="page"
                        class="page-item"
                        :class="{ active: page === currentPage }"
                    >
                        <button type="button" class="page-link" @click="onChangePage(page)">
                            {{ page }}
                        </button>
                    </li>
                    <li class="page-item" :class="{ disabled: !pagination.next_page_url }">
                        <button type="button" class="page-link text-primary" @click="onChangePage(currentPage + 1)">
                            {{ t('pagination.next') }}
                        </button>
                    </li>
                </ul>
            </nav>
        </div>
    </div>
</template>

<script setup>
/**
 * Shared, reusable admin list pagination footer.
 *
 * Business gap fix: every AI-module admin index screen (45 of them, audited
 * 27 Sep) fetched its list with no `page`/`per_page` param and rendered no
 * pagination controls at all - since the backend's ApiPaginator defaults to
 * 10 rows per page (app/Support/Api/ApiPaginator.php:43), any table beyond
 * 10 real rows silently hid the rest with zero indication more existed. This
 * was especially severe for audit-log-style screens (ai-safety-events,
 * ai-security-events, ai-knowledge-chunks, ...) where "only the 10 newest"
 * defeats the screen's whole purpose.
 *
 * The working pattern already existed - copy-pasted independently in 8
 * non-AI admin screens (country, currency, employee, flag, language, ...)
 * with near-identical markup and a `pageNumbers`/`entriesLabel` computed
 * duplicated in each. Rather than copy-paste a 9th..52nd time across every
 * AI screen, this extracts that pattern once as a real reusable platform
 * component, per this project's own stated architecture principle: don't
 * build an isolated per-screen version of something that is clearly a
 * shared concept.
 *
 * Usage in a screen's index.vue:
 *   <AdminPaginationFooter
 *       :pagination="pagination"
 *       :current-page="page"
 *       :per-page="perPage"
 *       :loading="loading"
 *       @change-page="onChangePage"
 *       @change-per-page="onChangePerPage"
 *   />
 * paired with the useAdminPagination() composable for the page/perPage/
 * pagination refs and the params to send with the list request.
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    pagination: {
        type: Object,
        default: null,
    },
    currentPage: {
        type: Number,
        default: 1,
    },
    perPage: {
        type: Number,
        default: 10,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    perPageOptions: {
        type: Array,
        default: () => [10, 25, 50],
    },
});

const emit = defineEmits(['change-page', 'change-per-page']);

const { t } = useI18n();

const perPageId = computed(() => `admin-pagination-per-page-${Math.random().toString(36).slice(2, 8)}`);

const pageNumbers = computed(() => {
    if (! props.pagination?.last_page) {
        return [];
    }

    const total = props.pagination.last_page;
    const current = props.currentPage;
    let start = Math.max(1, current - 2);
    let end = Math.min(total, start + 4);

    if (end - start < 4) {
        start = Math.max(1, end - 4);
    }

    const pages = [];

    for (let page = start; page <= end; page += 1) {
        pages.push(page);
    }

    return pages;
});

const entriesLabel = computed(() => {
    if (! props.pagination) {
        return '';
    }

    return t('pagination.showing_entries', {
        from: props.pagination.from ?? 0,
        to: props.pagination.to ?? 0,
        total: props.pagination.total ?? 0,
    });
});

function onChangePage(page) {
    const lastPage = props.pagination?.last_page ?? 1;
    const target = Math.min(Math.max(1, page), lastPage);

    if (target === props.currentPage) {
        return;
    }

    emit('change-page', target);
}

function onPerPageChange(value) {
    emit('change-per-page', Number(value));
}
</script>
