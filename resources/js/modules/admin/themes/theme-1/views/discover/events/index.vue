<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('discover.events.title') }}
                <span v-if="meta.total != null" class="badge bg-primary-transparent ms-2 fs-12 align-middle">{{ meta.total }}</span>
            </h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item">{{ t('sidebar.discover') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('discover.events.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2 catalog-toolbar-filters">
                    <div class="input-group input-group-sm catalog-toolbar-search">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="filters.search" type="search" class="form-control" :placeholder="t('discover.events.search')" @keyup.enter="load(1)">
                        <button v-if="filters.search" type="button" class="btn btn-light border catalog-search-clear" :title="t('countries.clear_search')" @click="clearSearch">
                            <i class="ri-close-line"></i>
                        </button>
                    </div>
                    <Select
                        v-model="filters.status"
                        :options="statusOptions"
                        option-label="label"
                        option-value="value"
                        :placeholder="t('discover.events.any_status')"
                        show-clear
                        class="wallet-filter-select"
                        @change="load(1)"
                    />
                    <Select
                        v-model="filters.city_id"
                        :options="cityOptions"
                        option-label="label"
                        option-value="value"
                        :placeholder="t('discover.events.any_city')"
                        filter
                        :filter-placeholder="t('search_placeholder')"
                        show-clear
                        class="wallet-filter-select"
                        @change="load(1)"
                    />
                    <Select
                        v-model="filters.when"
                        :options="whenOptions"
                        option-label="label"
                        option-value="value"
                        :placeholder="t('discover.events.any_time')"
                        show-clear
                        class="wallet-filter-select"
                        @change="load(1)"
                    />
                </div>
                <div class="catalog-toolbar-actions d-flex flex-wrap align-items-center gap-2">
                    <ul class="nav nav-pills nav-style-3 gap-1">
                        <li v-for="tab in reviewTabs" :key="tab.key" class="nav-item">
                            <button type="button" class="nav-link py-1 px-3" :class="{ active: filters.review_status === tab.key }" @click="setReview(tab.key)">
                                {{ t(`discover.review.${tab.key || 'all'}`) }}
                                <span v-if="tab.key === 'pending' && pendingCount" class="badge bg-warning ms-1">{{ pendingCount }}</span>
                            </button>
                        </li>
                    </ul>
                    <button v-if="canMultipleDelete && selectedCount" type="button" class="btn btn-danger btn-sm btn-wave" @click="confirmDeleteSelected">
                        <i class="ri-delete-bin-line me-1 align-middle"></i>{{ t('catalog.bulk_delete_count', { count: selectedCount }) }}
                    </button>
                    <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                        <i class="ri-add-line me-1 align-middle"></i>{{ t('discover.events.add') }}
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th v-if="canMultipleDelete" scope="col" class="ps-4" style="width: 48px;">
                                    <input class="form-check-input" type="checkbox" :checked="allSelected" :disabled="loading || !rows.length" @change="onSelectAll($event.target.checked)">
                                </th>
                                <th :class="{ 'ps-4': !canMultipleDelete }">{{ t('discover.events.event') }}</th>
                                <th>{{ t('discover.events.when') }}</th>
                                <th>{{ t('discover.events.source') }}</th>
                                <th>{{ t('discover.events.status') }}</th>
                                <th>{{ t('discover.events.review') }}</th>
                                <th>{{ t('discover.events.interested') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="8" :layout="skeletonLayout" />
                            <tr v-else-if="!rows.length">
                                <td :colspan="canMultipleDelete ? 8 : 7" class="border-0">
                                    <div class="text-center py-5">
                                        <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3"><i class="ri-calendar-event-line fs-2 text-primary"></i></span>
                                        <p class="text-muted mb-0">{{ t('wallet.common.empty') }}</p>
                                    </div>
                                </td>
                            </tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id">
                                    <td v-if="canMultipleDelete" class="ps-4">
                                        <input class="form-check-input" type="checkbox" :checked="isSelected(row.id)" @change="onRowSelect(row.id, $event.target.checked)">
                                    </td>
                                    <td :class="{ 'ps-4': !canMultipleDelete }">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="avatar avatar-md avatar-rounded" :style="{ background: row.category?.color || '#eef' }">
                                                <img v-if="row.cover" :src="row.cover" alt="" style="object-fit: cover;">
                                                <span v-else class="fs-18">{{ row.category?.emoji || '📍' }}</span>
                                            </span>
                                            <div>
                                                <div class="fw-semibold text-truncate" style="max-width: 260px;">{{ row.title }}</div>
                                                <div class="text-muted fs-12">{{ row.category?.name }} · {{ row.city?.name }}<span v-if="row.venue"> · {{ row.venue }}</span></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div dir="ltr" class="text-start">{{ row.local_date }} {{ row.local_time }}</div>
                                        <div class="text-muted fs-11">{{ row.timezone }}</div>
                                    </td>
                                    <td>
                                        <span v-if="row.source === 'admin'" class="badge bg-primary-transparent"><i class="ri-shield-check-line me-1"></i>{{ t('discover.events.by_admin') }}</span>
                                        <template v-else>
                                            <div class="fs-13">{{ row.organizer?.name }}</div>
                                            <span class="badge" :class="row.organizer?.verified ? 'bg-success-transparent' : 'bg-secondary-transparent'">{{ row.organizer?.verified ? t('discover.organizers.verified') : t('discover.organizers.unverified') }}</span>
                                        </template>
                                    </td>
                                    <td><span class="badge" :class="statusClass(row.status)">{{ t(`discover.status.${row.status}`) }}</span></td>
                                    <td>
                                        <span class="badge" :class="reviewClass(row.review_status)">{{ t(`discover.review.${row.review_status}`) }}</span>
                                        <div v-if="row.review_note" class="text-muted fs-11 text-truncate" style="max-width: 160px;" :title="row.review_note">{{ row.review_note }}</div>
                                    </td>
                                    <td><span class="badge bg-info-transparent">{{ row.interested_count }}</span></td>
                                    <td class="text-end pe-4">
                                        <div class="btn-list justify-content-end">
                                            <template v-if="canUpdate && row.review_status !== 'approved'">
                                                <button type="button" class="btn btn-sm btn-success-light" @click="review(row, 'approved')"><i class="ri-check-line me-1"></i>{{ t('discover.events.approve') }}</button>
                                            </template>
                                            <button v-if="canUpdate && row.review_status === 'pending'" type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('discover.events.reject')" @click="review(row, 'rejected')"><i class="ri-close-line"></i></button>
                                            <button v-if="canUpdate" type="button" class="btn btn-sm btn-warning-light btn-icon" :title="t('discover.events.change_status')" @click="openStatus(row)"><i class="ri-time-line"></i></button>
                                            <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" @click="openEdit(row)"><i class="ri-pencil-line"></i></button>
                                            <a v-if="row.source_url" :href="row.source_url" target="_blank" rel="noopener" class="btn btn-sm btn-light btn-icon"><i class="ri-external-link-line"></i></a>
                                            <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" @click="remove(row)"><i class="ri-delete-bin-line"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="meta.last_page > 1" class="card-footer border-top-0">
                <div class="d-flex align-items-center flex-wrap gap-3">
                    <div class="text-muted fs-13">{{ meta.current_page }} / {{ meta.last_page }}</div>
                    <nav class="pagination-style-4 ms-md-auto">
                        <ul class="pagination mb-0">
                            <li class="page-item" :class="{ disabled: meta.current_page <= 1 }">
                                <button type="button" class="page-link" @click="load(meta.current_page - 1)">{{ t('countries.previous') }}</button>
                            </li>
                            <li v-for="page in pageNumbers" :key="page" class="page-item" :class="{ active: page === meta.current_page }">
                                <button type="button" class="page-link" @click="load(page)">{{ page }}</button>
                            </li>
                            <li class="page-item" :class="{ disabled: meta.current_page >= meta.last_page }">
                                <button type="button" class="page-link text-primary" @click="load(meta.current_page + 1)">{{ t('countries.next') }}</button>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>
        </div>

        <!-- Add / edit -->
        <WalletModal :show="showForm" :title="editingId ? t('discover.events.edit') : t('discover.events.add')" size="lg" @close="showForm = false">
            <form id="discover-event-form" novalidate @submit.prevent="save">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">{{ t('discover.events.f_title') }} <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ri-calendar-event-line"></i></span>
                                <input v-model="form.title" type="text" class="form-control" :class="{ 'is-invalid': !!fieldError('title') }">
                            </div>
                        <div v-if="fieldError('title')" class="text-danger fs-12 mt-1">{{ fieldError('title') }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('discover.events.f_category') }} <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ri-price-tag-3-line"></i></span>
                                <Select v-model="form.category_id" :options="categoryOptions" option-label="label" option-value="value" filter :filter-placeholder="t('search_placeholder')" :invalid="!!fieldError('category_id')" append-to="self" />
                            </div>
                        <div v-if="fieldError('category_id')" class="text-danger fs-12 mt-1">{{ fieldError('category_id') }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('discover.events.f_city') }} <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ri-building-2-line"></i></span>
                                <Select v-model="form.city_id" :options="cityOptions" option-label="label" option-value="value" filter :filter-placeholder="t('search_placeholder')" :invalid="!!fieldError('city_id')" append-to="self" />
                            </div>
                        <div v-if="fieldError('city_id')" class="text-danger fs-12 mt-1">{{ fieldError('city_id') }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('discover.events.f_starts') }} <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ri-calendar-line"></i></span>
                                <AdminDatePicker v-model="form.starts_at" show-time :invalid="!!fieldError('starts_at')" />
                            </div>
                        <div class="text-muted fs-11 mt-1">{{ t('discover.events.local_hint', { zone: cityZone || '—' }) }}</div>
                        <div v-if="fieldError('starts_at')" class="text-danger fs-12">{{ fieldError('starts_at') }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('discover.events.f_ends') }}</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ri-calendar-check-line"></i></span>
                                <AdminDatePicker v-model="form.ends_at" show-time :invalid="!!fieldError('ends_at')" />
                            </div>
                        <div v-if="fieldError('ends_at')" class="text-danger fs-12 mt-1">{{ fieldError('ends_at') }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('discover.events.f_venue') }}</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ri-store-2-line"></i></span>
                                <input v-model="form.venue" type="text" class="form-control" :class="{ 'is-invalid': !!fieldError('venue') }">
                            </div>
                        <div v-if="fieldError('venue')" class="text-danger fs-12 mt-1">{{ fieldError('venue') }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('discover.events.f_address') }}</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ri-map-pin-2-line"></i></span>
                                <input v-model="form.address" type="text" class="form-control" :class="{ 'is-invalid': !!fieldError('address') }">
                            </div>
                        <div v-if="fieldError('address')" class="text-danger fs-12 mt-1">{{ fieldError('address') }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('discover.events.f_source_url') }}</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ri-links-line"></i></span>
                                <input v-model="form.source_url" type="url" class="form-control" dir="ltr" :class="{ 'is-invalid': !!fieldError('source_url') }">
                            </div>
                        <div v-if="fieldError('source_url')" class="text-danger fs-12 mt-1">{{ fieldError('source_url') }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('discover.events.f_booking_url') }}</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ri-ticket-line"></i></span>
                                <input v-model="form.booking_url" type="url" class="form-control" dir="ltr" :class="{ 'is-invalid': !!fieldError('booking_url') }">
                            </div>
                        <div v-if="fieldError('booking_url')" class="text-danger fs-12 mt-1">{{ fieldError('booking_url') }}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-switch mt-md-4"><input id="de-free" v-model="form.is_free" class="form-check-input" type="checkbox"><label class="form-check-label" for="de-free">{{ t('discover.events.f_free') }}</label></div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ t('discover.events.f_price') }}</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ri-price-tag-line"></i></span>
                                <input v-model="form.price_text" type="text" class="form-control" :class="{ 'is-invalid': !!fieldError('price_text') }" :disabled="form.is_free">
                            </div>
                        <div v-if="fieldError('price_text')" class="text-danger fs-12 mt-1">{{ fieldError('price_text') }}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-switch mt-md-4"><input id="de-family" v-model="form.family_friendly" class="form-check-input" type="checkbox"><label class="form-check-label" for="de-family">{{ t('discover.events.f_family') }}</label></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ t('discover.events.f_description') }}</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ri-file-text-line"></i></span>
                                <textarea v-model="form.description" rows="3" class="form-control" :class="{ 'is-invalid': !!fieldError('description') }"></textarea>
                            </div>
                        <div v-if="fieldError('description')" class="text-danger fs-12 mt-1">{{ fieldError('description') }}</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ t('discover.events.f_cover') }}</label>
                        <div class="d-flex align-items-center gap-3">
                            <span class="avatar avatar-xl bg-light border"><img v-if="coverPreview" :src="coverPreview" alt="" style="object-fit: cover;"><i v-else class="ri-image-add-line fs-20 text-muted"></i></span>
                            <input type="file" accept="image/png,image/jpeg,image/webp" class="form-control" @change="pickCover">
                        </div>
                    </div>
                </div>
                <div v-if="errors.general" class="text-danger mt-2 fs-13">{{ errors.general }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="saving" @click="showForm = false">{{ t('cancel') }}</button>
                <button type="submit" form="discover-event-form" class="btn btn-primary" :disabled="saving"><span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}</button>
            </template>
        </WalletModal>

        <!-- Status / time change (spec 175) -->
        <WalletModal :show="!!statusRow" :title="t('discover.events.change_status')" size="md" @close="statusRow = null">
            <form v-if="statusRow" id="discover-status-form" novalidate @submit.prevent="saveStatus">
                <p class="fw-semibold mb-2">{{ statusRow.title }}</p>
                <div class="mb-3">
                    <label class="form-label">{{ t('discover.events.status') }}</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="ri-flag-line"></i></span>
                            <Select v-model="statusForm.status" :options="statusOptions" option-label="label" option-value="value" :invalid="!!statusError('status')" append-to="self" />
                        </div>
                    <div v-if="statusError('status')" class="text-danger fs-12 mt-1">{{ statusError('status') }}</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ t('discover.events.new_time') }}</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="ri-calendar-line"></i></span>
                            <AdminDatePicker v-model="statusForm.starts_at" show-time />
                        </div>
                    <div class="text-muted fs-11 mt-1">{{ t('discover.events.local_hint', { zone: statusRow.timezone }) }}</div>
                </div>
                <div class="mb-2">
                    <label class="form-label">{{ t('discover.events.note') }}</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="ri-chat-1-line"></i></span>
                            <input v-model="statusForm.note" type="text" class="form-control" :class="{ 'is-invalid': !!statusError('note') }">
                        </div>
                    <div v-if="statusError('note')" class="text-danger fs-12 mt-1">{{ statusError('note') }}</div>
                </div>
                <div class="alert alert-info fs-12 mb-0">{{ t('discover.events.notify_hint', { count: statusRow.interested_count }) }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="saving" @click="statusRow = null">{{ t('cancel') }}</button>
                <button type="submit" form="discover-status-form" class="btn btn-primary" :disabled="saving"><span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}</button>
            </template>
        </WalletModal>

        <ConfirmDeleteModal
            :show="deleteConfirm.state.show"
            :title="deleteConfirm.state.title"
            :message="deleteConfirm.state.message"
            :loading="deleteConfirm.state.loading"
            @close="deleteConfirm.close()"
            @confirm="deleteSelected"
        />
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import Select from 'primevue/select';
import useVuelidate from '@vuelidate/core';
import { helpers, url } from '@vuelidate/validators';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import ConfirmDeleteModal from '../../../../../../../components/ui/ConfirmDeleteModal.vue';
import { useConfirmDelete } from '../../../../../../../composables/useConfirmDelete';
import AdminDatePicker from '../../../../../../../components/ui/AdminDatePicker.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import useValidation from '../../../../../../../composables/useValidation';
import { usePermission } from '../../../../../../../composables/usePermission';

const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();

const canCreate = computed(() => can('discover-events.create'));
const canUpdate = computed(() => can('discover-events.update'));
const canDelete = computed(() => can('discover-events.delete'));
const skeletonLayout = computed(() => [...(canMultipleDelete.value ? ['check'] : []), 'avatar-text', 'two-lines', 'two-lines', 'badge', 'two-lines', 'badge', 'actions']);
const canMultipleDelete = computed(() => can('discover-events.multiple-delete'));

const deleteConfirm = useConfirmDelete();
const selectedIds = ref([]);
const selectedCount = computed(() => selectedIds.value.length);
const allSelected = computed(() => rows.value.length > 0 && rows.value.every((r) => selectedIds.value.includes(r.id)));
const isSelected = (id) => selectedIds.value.includes(id);
function onSelectAll(checked) {
    selectedIds.value = checked ? rows.value.map((r) => r.id) : [];
}
function onRowSelect(id, checked) {
    selectedIds.value = checked ? [...new Set([...selectedIds.value, id])] : selectedIds.value.filter((x) => x !== id);
}
function confirmDeleteSelected() {
    deleteConfirm.open({ title: t('countries.delete_selected_title'), message: t('countries.confirm_delete_selected') });
}
async function deleteSelected() {
    deleteConfirm.setLoading(true);
    try {
        await adminAxios.post('/api/admin/v1/discover-events/delete-multiple', { ids: selectedIds.value });
        showSuccess(t('chat.common.deleted'));
        selectedIds.value = [];
        deleteConfirm.setLoading(false);
        deleteConfirm.close();
        load(1);
    } catch (error) {
        deleteConfirm.setLoading(false);
        showError(extractApiErrorMessage(error));
    }
}

const statuses = ['confirmed', 'postponed', 'cancelled', 'sold_out', 'ended'];
const reviewTabs = [{ key: 'pending' }, { key: 'approved' }, { key: 'rejected' }, { key: '' }];

const rows = ref([]);
const meta = ref({});
const pendingCount = ref(0);
const loading = ref(false);
const filters = reactive({ review_status: 'pending', status: '', city_id: '', when: '', search: '' });
const categories = ref([]);
const cities = ref([]);

const showForm = ref(false);
const editingId = ref(null);
const saving = ref(false);
const errors = reactive({});
const blank = () => ({ title: '', category_id: null, city_id: null, starts_at: '', ends_at: '', venue: '', address: '', source_url: '', booking_url: '', is_free: false, price_text: '', family_friendly: false, description: '', cover: null });
const form = reactive(blank());

const { requiredField, maxString } = useValidation();
const urlRule = (labelKey) => helpers.withMessage(() => t('discover.validation.url', { field: t(labelKey) }), url);
const endsAfterStart = helpers.withMessage(
    () => t('discover.validation.ends_after_start'),
    (value) => !value || !form.starts_at || value > form.starts_at,
);
const rules = computed(() => ({
    title: { required: requiredField('discover.events.f_title'), maxLength: maxString('discover.events.f_title', 160) },
    category_id: { required: requiredField('discover.events.f_category') },
    city_id: { required: requiredField('discover.events.f_city') },
    starts_at: { required: requiredField('discover.events.f_starts') },
    ends_at: { afterStart: endsAfterStart },
    venue: { maxLength: maxString('discover.events.f_venue', 160) },
    address: { maxLength: maxString('discover.events.f_address', 255) },
    source_url: { url: urlRule('discover.events.f_source_url'), maxLength: maxString('discover.events.f_source_url', 2048) },
    booking_url: { url: urlRule('discover.events.f_booking_url'), maxLength: maxString('discover.events.f_booking_url', 2048) },
    price_text: { maxLength: maxString('discover.events.f_price', 80) },
    description: { maxLength: maxString('discover.events.f_description', 5000) },
}));
const v$ = useVuelidate(rules, form, { $autoDirty: true });

/** The first Vuelidate message of a field once it was touched, else what the server said about it. */
function fieldError(name) {
    const field = v$.value[name];

    if (field?.$dirty && field.$invalid) return field.$errors[0]?.$message || '';

    return errors[name] || '';
}
const coverPreview = ref(null);
const statusOptions = computed(() => statuses.map((s) => ({ value: s, label: t(`discover.status.${s}`) })));
const whenOptions = computed(() => [{ value: 'upcoming', label: t('discover.events.upcoming') }, { value: 'past', label: t('discover.events.past') }]);
const cityOptions = computed(() => cities.value.map((c) => ({ value: c.id, label: `${c.name} · ${c.country_code}` })));
const categoryOptions = computed(() => categories.value.map((c) => ({ value: c.id, label: `${c.emoji || ''} ${c.name}`.trim() })));
const pageNumbers = computed(() => {
    const last = meta.value.last_page || 1;
    const cur = meta.value.current_page || 1;
    const from = Math.max(1, Math.min(cur - 2, last - 4));
    const to = Math.min(last, from + 4);
    return Array.from({ length: to - from + 1 }, (_, i) => from + i);
});
const cityZone = computed(() => cities.value.find((c) => c.id === form.city_id)?.timezone);

const statusRow = ref(null);
const statusForm = reactive({ status: 'confirmed', starts_at: '', note: '' });
const statusRules = computed(() => ({
    status: { required: requiredField('discover.events.status') },
    note: { maxLength: maxString('discover.events.note', 300) },
}));
const sv$ = useVuelidate(statusRules, statusForm, { $autoDirty: true });

function statusError(name) {
    const field = sv$.value[name];

    return field?.$dirty && field.$invalid ? field.$errors[0]?.$message || '' : '';
}

function statusClass(s) {
    return { confirmed: 'bg-success-transparent', postponed: 'bg-warning-transparent', cancelled: 'bg-danger-transparent', sold_out: 'bg-info-transparent', ended: 'bg-secondary-transparent' }[s] || 'bg-light';
}

function reviewClass(s) {
    return { pending: 'bg-warning', approved: 'bg-success', rejected: 'bg-danger' }[s] || 'bg-light';
}

function clearSearch() {
    filters.search = '';
    load(1);
}

function setReview(key) {
    filters.review_status = key;
    load(1);
}

function resetErrors() {
    Object.keys(errors).forEach((key) => delete errors[key]);
}

function pickCover(event) {
    const file = event.target.files?.[0] || null;
    form.cover = file;
    if (file) coverPreview.value = URL.createObjectURL(file);
}

function openCreate() {
    v$.value.$reset();
    editingId.value = null;
    resetErrors();
    Object.assign(form, blank(), { category_id: categories.value[0]?.id ?? null, city_id: cities.value[0]?.id ?? null });
    coverPreview.value = null;
    showForm.value = true;
}

async function openEdit(row) {
    v$.value.$reset();
    resetErrors();
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/discover-events/${row.id}`);
        const e = data.data;
        editingId.value = e.id;
        Object.assign(form, blank(), {
            title: e.title, category_id: e.category_id, city_id: e.city_id, starts_at: e.starts_local || '', ends_at: e.ends_local || '',
            venue: e.venue || '', address: e.address || '', source_url: e.source_url || '', booking_url: e.booking_url || '',
            is_free: !!e.is_free, price_text: e.price_text || '', family_friendly: !!e.family_friendly, description: e.description || '',
        });
        coverPreview.value = e.cover;
        showForm.value = true;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function save() {
    resetErrors();
    if (!(await v$.value.$validate())) return;
    saving.value = true;
    const body = new FormData();
    Object.entries(form).forEach(([key, value]) => {
        if (key === 'cover') {
            if (value) body.append('cover', value);
        } else if (typeof value === 'boolean') {
            body.append(key, value ? '1' : '0');
        } else if (value !== null && value !== '') {
            body.append(key, String(value));
        } else if (editingId.value && ['ends_at', 'venue', 'address', 'source_url', 'booking_url', 'price_text', 'description'].includes(key)) {
            body.append(key, '');
        }
    });

    try {
        await adminAxios.post(editingId.value ? `/api/admin/v1/discover-events/${editingId.value}` : '/api/admin/v1/discover-events', body);
        showSuccess(t('discover.events.saved'));
        showForm.value = false;
        load(meta.value.current_page || 1);
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};
        Object.entries(bag).forEach(([key, messages]) => { errors[key] = messages[0]; });
        errors.general = Object.keys(bag).length ? '' : extractApiErrorMessage(error);
    } finally {
        saving.value = false;
    }
}

async function review(row, status) {
    const note = status === 'rejected' ? window.prompt(t('discover.events.reject_note')) : null;
    if (status === 'rejected' && note === null) return;
    try {
        await adminAxios.patch(`/api/admin/v1/discover-events/${row.id}/review`, { review_status: status, note: note || null });
        showSuccess(t('discover.events.saved'));
        load(meta.value.current_page || 1);
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

function openStatus(row) {
    sv$.value.$reset();
    statusRow.value = row;
    Object.assign(statusForm, { status: row.status, starts_at: '', note: '' });
}

async function saveStatus() {
    if (!(await sv$.value.$validate())) return;
    saving.value = true;
    try {
        await adminAxios.patch(`/api/admin/v1/discover-events/${statusRow.value.id}/status`, {
            status: statusForm.status, note: statusForm.note || null, starts_at: statusForm.starts_at || null,
        });
        showSuccess(t('discover.events.saved'));
        statusRow.value = null;
        load(meta.value.current_page || 1);
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        saving.value = false;
    }
}

async function remove(row) {
    if (!window.confirm(t('discover.events.confirm_delete', { name: row.title }))) return;
    try {
        await adminAxios.delete(`/api/admin/v1/discover-events/${row.id}`);
        showSuccess(t('chat.common.deleted'));
        load(meta.value.current_page || 1);
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function load(page = 1) {
    loading.value = true;
    selectedIds.value = [];
    try {
        const params = { page, per_page: 15 };
        Object.entries(filters).forEach(([key, value]) => { if (value !== '' && value !== null) params[key] = value; });
        const { data } = await adminAxios.get('/api/admin/v1/discover-events', { params });
        rows.value = data.data ?? [];
        meta.value = data.pagination ?? data.meta?.pagination ?? {};
        pendingCount.value = data.meta?.pending_count ?? data.pending_count ?? 0;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

async function loadCatalog() {
    try {
        const [cats, cts] = await Promise.all([adminAxios.get('/api/admin/v1/discover-categories'), adminAxios.get('/api/admin/v1/discover-cities')]);
        categories.value = (cats.data.data ?? []).filter((c) => c.status);
        cities.value = (cts.data.data ?? []).filter((c) => c.status);
    } catch {
        // The form shows empty lists; the table still works.
    }
}

onMounted(() => {
    load(1);
    loadCatalog();
});
</script>
