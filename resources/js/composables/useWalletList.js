import { onBeforeUnmount, reactive, ref, watch } from 'vue';
import adminAxios from '../api/adminAxios';
import useToast, { extractApiErrorMessage } from './useToast';

/**
 * Paginated + filtered list against /api/admin/v1/{path}, shared by the wallet
 * screens. Changing any filter reloads from page 1 (search is debounced); a
 * response that arrives after a newer request was made is ignored, so a fast
 * typist never sees stale rows.
 */
export default function useWalletList(path, { defaults = {}, perPage: initialPerPage = 15, statusCounts: withStatusCounts = false } = {}) {
    const { showError } = useToast();
    const rows = ref([]);
    const loading = ref(false);
    const pagination = ref(null);
    const page = ref(1);
    // { total, active, inactive } of the whole list, when the page asked for them (they ride on the same request).
    const statusCounts = ref(null);
    // Rows per page (the admin picks it under the table).
    const perPage = ref(initialPerPage);
    const filters = reactive({ ...defaults });
    let sequence = 0;
    let timer = null;

    function params(pageNumber) {
        const query = { per_page: perPage.value, page: pageNumber };

        if (withStatusCounts) {
            query.status_counts = 1;
        }

        Object.entries(filters).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) {
                query[key] = value;
            }
        });

        return query;
    }

    async function fetch(pageNumber = page.value) {
        const current = ++sequence;
        loading.value = true;

        try {
            const { data } = await adminAxios.get(`/api/admin/v1/${path}`, { params: params(pageNumber) });

            if (current !== sequence) {
                return;
            }

            rows.value = data.data ?? [];
            pagination.value = data.pagination ?? null;
            statusCounts.value = data.status_counts ?? statusCounts.value;
            page.value = pageNumber;
        } catch (error) {
            if (current === sequence) {
                showError(extractApiErrorMessage(error));
            }
        } finally {
            if (current === sequence) {
                loading.value = false;
            }
        }
    }

    watch(filters, () => {
        clearTimeout(timer);
        timer = setTimeout(() => fetch(1), 350);
    }, { deep: true });

    watch(perPage, () => fetch(1));

    onBeforeUnmount(() => clearTimeout(timer));

    return { rows, loading, pagination, page, filters, fetch, perPage, statusCounts };
}
