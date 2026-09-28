/**
 * Shared page/perPage/pagination state for an admin list screen, paired
 * with the AdminPaginationFooter.vue component. See that component's
 * doc-comment for why this exists (real bug found & fixed 27 Sep: every
 * AI-module admin screen fetched its list with no page/per_page param at
 * all, so the backend's default 10-per-page silently hid every row past
 * the 10th with no indication more existed).
 *
 * Usage in a screen's <script setup>:
 *
 *   const { page, perPage, pagination, paginationParams, applyPagination,
 *           onChangePage, onChangePerPage } = useAdminPagination();
 *
 *   async function fetchThings() {
 *       loading.value = true;
 *       try {
 *           const { data } = await adminAxios.get(url, { params: paginationParams.value });
 *           things.value = data.data ?? [];
 *           applyPagination(data);
 *       } finally {
 *           loading.value = false;
 *       }
 *   }
 *
 *   function onChangePage(target) {
 *       page.value = target;
 *       fetchThings();
 *   }
 *
 *   function onChangePerPage(value) {
 *       perPage.value = value;
 *       page.value = 1;
 *       fetchThings();
 *   }
 *
 * Template:
 *   <AdminPaginationFooter
 *       :pagination="pagination"
 *       :current-page="page"
 *       :per-page="perPage"
 *       :loading="loading"
 *       @change-page="onChangePage"
 *       @change-per-page="onChangePerPage"
 *   />
 *
 * `onChangePage`/`onChangePerPage` are deliberately left for the screen to
 * define itself (rather than this composable calling the fetch function
 * for you) so every screen's existing `loading` ref and fetch function
 * keep working exactly as they already do - this only adds the missing
 * page/per_page params and the returned pagination meta, nothing else
 * about each screen's existing fetch logic needs to change.
 */
import { ref, computed } from 'vue';

export default function useAdminPagination(defaultPerPage = 10) {
    const page = ref(1);
    const perPage = ref(defaultPerPage);
    const pagination = ref(null);

    const paginationParams = computed(() => ({
        page: page.value,
        per_page: perPage.value,
    }));

    function applyPagination(responseData) {
        pagination.value = responseData?.pagination ?? null;
    }

    return {
        page,
        perPage,
        pagination,
        paginationParams,
        applyPagination,
    };
}
