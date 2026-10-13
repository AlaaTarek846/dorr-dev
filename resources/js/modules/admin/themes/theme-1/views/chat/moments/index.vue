<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('chat.moments.title') }}
                <span v-if="pagination?.total != null" class="badge bg-primary-transparent ms-2 fs-12 align-middle">
                    {{ pagination.total }}
                </span>
            </h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item">{{ t('sidebar.chat') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('chat.moments.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-12">
                <div class="card custom-card">
                    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                        <div class="d-flex flex-wrap align-items-center gap-1 catalog-toolbar-filters">
                            <div class="input-group input-group-sm catalog-toolbar-search">
                                <span class="input-group-text bg-white">
                                    <i class="ri-search-line text-muted"></i>
                                </span>
                                <input
                                    v-model="search"
                                    type="search"
                                    class="form-control"
                                    :placeholder="t('chat.moments.search')"
                                >
                                <button
                                    v-if="search"
                                    type="button"
                                    class="btn btn-light border catalog-search-clear"
                                    :title="t('chat.moments.clear_search')"
                                    @click="clearSearch"
                                >
                                    <i class="ri-close-line"></i>
                                </button>
                            </div>

                            <button
                                v-if="showAllFilter"
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'all' ? 'catalog-filter-btn--all' : 'catalog-filter-btn--all-idle'"
                                @click="setStatusFilter('all')"
                            >
                                {{ t('chat.moments.filter_all') }} ({{ counts.total }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'active' ? 'catalog-filter-btn--active' : 'catalog-filter-btn--active-idle'"
                                @click="setStatusFilter('active')"
                            >
                                {{ t('chat.moments.filter_active') }} ({{ counts.active }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'inactive' ? 'catalog-filter-btn--inactive' : 'catalog-filter-btn--inactive-idle'"
                                @click="setStatusFilter('inactive')"
                            >
                                {{ t('chat.moments.filter_inactive') }} ({{ counts.inactive }})
                            </button>

                            <MultiSelect
                                v-model="kindFilter"
                                :options="kindOptions"
                                option-label="label"
                                option-value="value"
                                :placeholder="t('chat.moments.filter_all_kinds')"
                                filter
                                :filter-placeholder="t('search_placeholder')"
                                display="chip"
                                :max-selected-labels="2"
                                append-to="body"
                                class="wallet-filter-select"
                            />
                        </div>

                        <div class="catalog-toolbar-actions d-flex flex-wrap align-items-center gap-2">
                            <button
                                v-if="canMultipleDelete && selectedCount"
                                type="button"
                                class="btn btn-danger btn-sm btn-wave"
                                @click="confirmDeleteSelected"
                            >
                                <i class="ri-delete-bin-line me-1 align-middle"></i>
                                {{ t('catalog.bulk_delete_count', { count: selectedCount }) }}
                            </button>
                            <button
                                v-if="canCreate"
                                type="button"
                                class="btn btn-primary btn-sm btn-wave"
                                @click="openCreate"
                            >
                                <i class="ri-add-line me-1 align-middle"></i>
                                {{ t('chat.moments.add') }}
                            </button>
                        </div>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table text-nowrap table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th v-if="canMultipleDelete" scope="col" class="ps-4" style="width: 48px;">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                :checked="allSelected"
                                                :disabled="loading || !pagedRows.length"
                                                @change="onSelectAll($event.target.checked)"
                                            >
                                        </th>
                                        <th scope="col" :class="{ 'ps-4': !canMultipleDelete }">{{ t('chat.common.name') }}</th>
                                        <th scope="col">{{ t('chat.moments.kind') }}</th>
                                        <th scope="col">{{ t('chat.moments.next') }}</th>
                                        <th scope="col">{{ t('chat.moments.countries') }}</th>
                                        <th scope="col">{{ t('chat.moments.status') }}</th>
                                        <th scope="col">{{ t('chat.moments.created_at') }}</th>
                                        <th v-if="showActionsColumn" scope="col" class="text-end pe-4">{{ t('chat.moments.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="8" :columns="tableColumnCount" />

                                    <tr v-else-if="!pagedRows.length">
                                        <td :colspan="tableColumnCount" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                                    <i class="ri-cake-2-line fs-2 text-primary"></i>
                                                </span>
                                                <p class="fw-semibold mb-1">{{ t('chat.moments.empty_title') }}</p>
                                                <p class="text-muted mb-3">{{ t('chat.moments.empty') }}</p>
                                                <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                                    <i class="ri-add-line me-1 align-middle"></i>
                                                    {{ t('chat.moments.add') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                    <tr
                                        v-for="row in pagedRows"
                                        :key="row.id"
                                        class="crm-contact"
                                    >
                                        <td v-if="canMultipleDelete" class="ps-4">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                :checked="isSelected(row.id)"
                                                @change="onRowSelect(row.id, $event.target.checked)"
                                            >
                                        </td>
                                        <td :class="{ 'ps-4': !canMultipleDelete }">
                                            <div class="d-flex align-items-center gap-2">
                                                <span
                                                    class="avatar avatar-md avatar-rounded moment-thumb"
                                                    :style="thumbStyle(row)"
                                                >
                                                    {{ row.emoji || '✨' }}
                                                </span>
                                                <div>
                                                    <button
                                                        v-if="canUpdate"
                                                        type="button"
                                                        class="btn btn-link p-0 text-start fw-semibold text-default"
                                                        @click="openEdit(row)"
                                                    >
                                                        {{ displayName(row) }}
                                                    </button>
                                                    <span v-else class="fw-semibold text-default">{{ displayName(row) }}</span>
                                                    <span class="d-block text-muted fs-11">
                                                        #{{ row.id }}
                                                        <span v-if="!row.default_on" class="badge bg-warning-transparent ms-1">{{ t('chat.moments.opt_in') }}</span>
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary-transparent">{{ t(`chat.moments.kinds.${row.kind}`) }}</span>
                                        </td>
                                        <td>
                                            <span class="d-block">{{ formatNextDate(row.next) }}</span>
                                            <span class="d-block text-muted fs-11">{{ ruleLabel(row) }}</span>
                                        </td>
                                        <td class="text-wrap" style="max-width: 18rem;">
                                            {{ countryNames(row) }}
                                        </td>
                                        <td>
                                            <div
                                                v-if="canUpdate"
                                                class="toggle toggle-success mb-0 catalog-status-toggle"
                                                :class="{
                                                    on: row.status,
                                                    'catalog-status-toggle--loading': isTogglingStatus(row.id),
                                                }"
                                                role="button"
                                                tabindex="0"
                                                :aria-busy="isTogglingStatus(row.id)"
                                                @click="toggleStatus(row)"
                                                @keydown.enter.space.prevent="toggleStatus(row)"
                                            >
                                                <span></span>
                                            </div>
                                            <span v-else class="badge" :class="row.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                                                {{ row.status ? t('chat.moments.filter_active') : t('chat.moments.filter_inactive') }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="d-block">{{ formatDate(row.created_at) }}</span>
                                            <span v-if="row.updated_at" class="d-block text-muted fs-11">
                                                {{ t('chat.moments.updated') }}: {{ formatDate(row.updated_at) }}
                                            </span>
                                        </td>
                                        <td v-if="showActionsColumn" class="text-end pe-4">
                                            <div class="btn-list justify-content-end">
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-success-light btn-icon"
                                                    :title="t('chat.moments.dates')"
                                                    @click="openDates(row)"
                                                >
                                                    <i class="ri-calendar-event-line"></i>
                                                </button>
                                                <button
                                                    v-if="canUpdate"
                                                    type="button"
                                                    class="btn btn-sm btn-info-light btn-icon"
                                                    :title="t('chat.moments.edit')"
                                                    @click="openEdit(row)"
                                                >
                                                    <i class="ri-pencil-line"></i>
                                                </button>
                                                <button
                                                    v-if="canDelete"
                                                    type="button"
                                                    class="btn btn-sm btn-danger-light btn-icon"
                                                    :title="t('chat.moments.confirm_delete', { name: row.name })"
                                                    @click="confirmDelete(row)"
                                                >
                                                    <i class="ri-delete-bin-line"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-if="pagination && !loading" class="card-footer border-top-0">
                        <div class="d-flex align-items-center flex-wrap gap-3">
                            <div class="d-flex align-items-center gap-2 text-muted fs-13">
                                <span>{{ entriesLabel }}</span>
                                <i :class="paginationArrowIcon"></i>
                            </div>

                            <div class="d-flex align-items-center gap-2 ms-md-auto">
                                <label class="text-muted fs-13 mb-0" for="moments-per-page">{{ t('chat.moments.per_page') }}</label>
                                <select
                                    id="moments-per-page"
                                    v-model.number="perPage"
                                    class="form-select form-select-sm w-auto"
                                >
                                    <option :value="15">15</option>
                                    <option :value="25">25</option>
                                    <option :value="50">50</option>
                                </select>
                            </div>

                            <nav aria-label="Moments pagination" class="pagination-style-4">
                                <ul class="pagination mb-0">
                                    <li class="page-item" :class="{ disabled: !pagination.prev_page_url }">
                                        <button type="button" class="page-link" @click="changePage(currentPage - 1)">
                                            {{ t('chat.moments.previous') }}
                                        </button>
                                    </li>
                                    <li
                                        v-for="page in pageNumbers"
                                        :key="page"
                                        class="page-item"
                                        :class="{ active: page === currentPage }"
                                    >
                                        <button type="button" class="page-link" @click="changePage(page)">
                                            {{ page }}
                                        </button>
                                    </li>
                                    <li class="page-item" :class="{ disabled: !pagination.next_page_url }">
                                        <button type="button" class="page-link text-primary" @click="changePage(currentPage + 1)">
                                            {{ t('chat.moments.next_page') }}
                                        </button>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <WalletModal :show="showModal" :title="editingId ? t('chat.moments.edit') : t('chat.moments.add')" size="xl" smooth @close="showModal = false">
            <form id="moment-form" class="row g-4" @submit.prevent="save">
                <div class="col-lg-7">
                    <CatalogTranslationTabs
                        :languages="languages"
                        :active-locale="activeLocale"
                        :translation-tab-class="tabClass"
                        :translation-tab-feedback="tabFeedback"
                        @update:active-locale="activeLocale = $event"
                    />

                    <template v-if="form.translations[activeLocale]">
                        <div class="mb-3">
                            <label for="moment-name" class="form-label">
                                {{ t('chat.common.name') }}
                                <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="ri-text"></i>
                                </span>
                                <input
                                    id="moment-name"
                                    v-model="form.translations[activeLocale].name"
                                    type="text"
                                    maxlength="120"
                                    class="form-control"
                                    :class="nameInputClass"
                                    :placeholder="t('chat.moments.name_placeholder')"
                                    @input="onNameInput"
                                >
                                <FormFieldFeedback v-bind="nameFeedback" />
                            </div>
                            <div v-if="errors.translations" class="invalid-feedback d-block">{{ errors.translations }}</div>
                        </div>

                        <div class="mb-3">
                            <label for="moment-greeting" class="form-label">{{ t('chat.moments.greeting') }}</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light align-self-start">
                                    <i class="ri-chat-smile-2-line"></i>
                                </span>
                                <textarea
                                    id="moment-greeting"
                                    v-model="form.translations[activeLocale].greeting"
                                    rows="2"
                                    maxlength="300"
                                    class="form-control"
                                    :placeholder="t('chat.moments.greeting_placeholder')"
                                ></textarea>
                            </div>
                        </div>
                    </template>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="moment-kind" class="form-label">{{ t('chat.moments.kind') }}</label>
                            <Select
                                id="moment-kind"
                                v-model="form.kind"
                                :options="kindOptions"
                                option-label="label"
                                option-value="value"
                                filter
                                :filter-placeholder="t('search_placeholder')"
                                append-to="body"
                                class="w-100"
                            />
                        </div>
                        <div class="col-md-6">
                            <label for="moment-rule" class="form-label">{{ t('chat.moments.rule') }}</label>
                            <Select
                                id="moment-rule"
                                v-model="form.date_rule"
                                :options="ruleOptions"
                                option-label="label"
                                option-value="value"
                                filter
                                :filter-placeholder="t('search_placeholder')"
                                append-to="body"
                                class="w-100"
                            />
                        </div>
                        <template v-if="form.date_rule !== 'manual'">
                            <div class="col-md-6">
                                <label for="moment-day" class="form-label">{{ t('chat.moments.day') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-calendar-2-line"></i>
                                    </span>
                                    <input
                                        id="moment-day"
                                        v-model.number="form.day"
                                        type="number"
                                        min="1"
                                        max="31"
                                        class="form-control"
                                    >
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="moment-month" class="form-label">{{ t('chat.moments.month') }}</label>
                                <Select
                                    id="moment-month"
                                    v-model="form.month"
                                    :options="monthOptions"
                                    option-label="label"
                                    option-value="value"
                                    filter
                                    :filter-placeholder="t('search_placeholder')"
                                    append-to="body"
                                    class="w-100"
                                />
                            </div>
                        </template>
                        <div v-else class="col-12">
                            <small class="text-muted">{{ t('chat.moments.manual_hint') }}</small>
                        </div>
                        <div class="col-md-4">
                            <label for="moment-duration" class="form-label">{{ t('chat.moments.duration') }}</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="ri-time-line"></i>
                                </span>
                                <input
                                    id="moment-duration"
                                    v-model.number="form.duration_days"
                                    type="number"
                                    min="1"
                                    max="60"
                                    class="form-control"
                                >
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="moment-before" class="form-label">{{ t('chat.moments.before') }}</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="ri-timer-line"></i>
                                </span>
                                <input
                                    id="moment-before"
                                    v-model.number="form.show_before_days"
                                    type="number"
                                    min="0"
                                    max="60"
                                    class="form-control"
                                >
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="moment-after" class="form-label">{{ t('chat.moments.after') }}</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="ri-timer-flash-line"></i>
                                </span>
                                <input
                                    id="moment-after"
                                    v-model.number="form.show_after_days"
                                    type="number"
                                    min="0"
                                    max="30"
                                    class="form-control"
                                >
                            </div>
                        </div>
                        <div class="col-12">
                            <label for="moment-countries" class="form-label">{{ t('chat.moments.countries') }}</label>
                            <MultiSelect
                                id="moment-countries"
                                v-model="form.countries"
                                :options="countries"
                                option-label="label"
                                option-value="code"
                                filter
                                :filter-placeholder="t('search_placeholder')"
                                display="chip"
                                :max-selected-labels="3"
                                append-to="body"
                                class="w-100"
                                :placeholder="t('chat.moments.everywhere')"
                            />
                            <small class="text-muted">{{ t('chat.moments.countries_hint') }}</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label d-block mb-2">{{ t('chat.moments.default_on') }}</label>
                            <div
                                class="toggle toggle-primary mb-0 catalog-modal-toggle"
                                :class="{ on: form.default_on }"
                                role="button"
                                tabindex="0"
                                @click="form.default_on = !form.default_on"
                                @keydown.enter.space.prevent="form.default_on = !form.default_on"
                            >
                                <span></span>
                            </div>
                            <small class="d-block text-muted mt-2">{{ t('chat.moments.default_on_hint') }}</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label d-block mb-2">{{ t('chat.moments.status') }}</label>
                            <div
                                class="toggle toggle-success mb-0 catalog-modal-toggle"
                                :class="{ on: form.status }"
                                role="button"
                                tabindex="0"
                                @click="form.status = !form.status"
                                @keydown.enter.space.prevent="form.status = !form.status"
                            >
                                <span></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <MomentPreview :look="previewLook" :name="form.translations[activeLocale]?.name" :greeting="form.translations[activeLocale]?.greeting" />
                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label for="moment-primary" class="form-label">{{ t('chat.moments.primary') }}</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light p-1">
                                    <input
                                        v-model="form.primary_color"
                                        type="color"
                                        class="form-control form-control-color border-0 bg-transparent p-0 moment-color-swatch"
                                    >
                                </span>
                                <input
                                    id="moment-primary"
                                    v-model="form.primary_color"
                                    type="text"
                                    maxlength="7"
                                    dir="ltr"
                                    class="form-control"
                                >
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="moment-secondary" class="form-label">{{ t('chat.moments.secondary') }}</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light p-1">
                                    <input
                                        v-model="form.secondary_color"
                                        type="color"
                                        class="form-control form-control-color border-0 bg-transparent p-0 moment-color-swatch"
                                    >
                                </span>
                                <input
                                    id="moment-secondary"
                                    v-model="form.secondary_color"
                                    type="text"
                                    maxlength="7"
                                    dir="ltr"
                                    class="form-control"
                                >
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="moment-emoji" class="form-label">{{ t('chat.moments.emoji') }}</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="ri-emotion-happy-line"></i>
                                </span>
                                <input
                                    id="moment-emoji"
                                    v-model="form.emoji"
                                    type="text"
                                    maxlength="8"
                                    class="form-control text-center"
                                >
                            </div>
                        </div>
                        <div class="col-md-8">
                            <label for="moment-animation" class="form-label">{{ t('chat.moments.animation') }}</label>
                            <Select
                                id="moment-animation"
                                v-model="form.animation"
                                :options="animationOptions"
                                option-label="label"
                                option-value="value"
                                filter
                                :filter-placeholder="t('search_placeholder')"
                                append-to="body"
                                class="w-100"
                            />
                        </div>
                        <div class="col-12">
                            <label for="moment-theme" class="form-label">{{ t('chat.moments.theme') }}</label>
                            <Select
                                id="moment-theme"
                                v-model="form.theme"
                                :options="themeOptions"
                                option-label="label"
                                option-value="value"
                                filter
                                :filter-placeholder="t('search_placeholder')"
                                append-to="body"
                                class="w-100"
                            />
                        </div>
                        <div class="col-12">
                            <label class="form-label d-block">{{ t('chat.moments.card') }}</label>
                            <div class="service-category-image-row">
                                <span class="service-category-image-box moment-card-box" :style="thumbStyle(previewLook)">
                                    <img v-if="cardPreview" :src="cardPreview" alt="" class="service-category-image-box__img">
                                    <span v-else class="moment-card-box__emoji">{{ form.emoji || '✨' }}</span>
                                    <label class="service-category-image-box__badge">
                                        <input
                                            ref="cardInput"
                                            type="file"
                                            accept="image/png,image/jpeg,image/webp"
                                            class="position-absolute w-100 h-100 op-0"
                                            @change="pickCard"
                                        >
                                        <i class="fe fe-camera"></i>
                                    </label>
                                </span>
                                <div class="service-category-image-actions">
                                    <button
                                        type="button"
                                        class="btn btn-primary btn-sm btn-wave service-category-image-actions__btn"
                                        @click="cardInput?.click()"
                                    >
                                        <i class="ri-image-edit-line"></i>
                                        <span>{{ t('chat.moments.card_change') }}</span>
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-wave service-category-image-actions__btn"
                                        :class="cardPreview ? 'btn-outline-danger' : 'btn-light'"
                                        :disabled="! cardPreview"
                                        @click="removeCard"
                                    >
                                        <i class="ri-delete-bin-line"></i>
                                        <span>{{ t('chat.moments.card_remove') }}</span>
                                    </button>
                                </div>
                            </div>
                            <small class="text-muted">{{ t('chat.moments.card_hint') }}</small>
                        </div>
                    </div>
                </div>
                <div v-if="errors.general" class="col-12 text-danger fs-13">{{ errors.general }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="saving" @click="showModal = false">{{ t('close') }}</button>
                <button type="submit" form="moment-form" class="btn btn-primary btn-wave" :disabled="saving">
                    <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                    {{ saving ? t('saving') : t('save_changes') }}
                </button>
            </template>
        </WalletModal>

        <WalletModal :show="!!datesFor" :title="datesFor ? `${t('chat.moments.dates')} — ${datesFor.moment.name}` : ''" size="lg" @close="datesFor = null">
            <div v-if="datesFor" class="moment-dates">
                <p class="text-muted fs-13 mb-3">{{ t('chat.moments.dates_intro') }}</p>
                <section v-for="y in datesFor.years" :key="y.year" class="moment-dates-year">
                    <div class="moment-dates-year__head">
                        <span class="fw-semibold">{{ y.year }}</span>
                        <span class="text-muted fs-12">{{ t('chat.moments.computed') }}: {{ formatDates(y.computed) }}</span>
                    </div>
                    <div class="moment-dates-row">
                        <div class="moment-dates-row__meta">
                            <span class="fw-semibold">{{ t('chat.moments.everywhere') }}</span>
                        </div>
                        <div class="moment-dates-row__field">
                            <AdminDatePicker
                                :model-value="y.everywhere || ''"
                                :disabled="!canUpdate"
                                :placeholder="t('chat.moments.pick_date')"
                                append-to="body"
                                @update:model-value="(value) => onCorrection(y.year, null, value, y.everywhere)"
                            />
                            <button
                                v-if="y.everywhere && canUpdate"
                                type="button"
                                class="btn btn-sm btn-light btn-icon"
                                @click="setDate(y.year, null, null)"
                            >
                                <i class="ri-close-line"></i>
                            </button>
                        </div>
                    </div>
                    <div v-for="c in y.countries" :key="c.country_id" class="moment-dates-row">
                        <div class="moment-dates-row__meta">
                            <span class="fw-semibold">{{ countryDisplayName(c) }}</span>
                            <span v-if="c.effective?.length" class="d-block text-muted fs-11">
                                {{ t('chat.moments.effective') }}: {{ formatDates(c.effective) }}
                            </span>
                        </div>
                        <div class="moment-dates-row__field">
                            <AdminDatePicker
                                :model-value="c.correction || ''"
                                :disabled="!canUpdate"
                                :placeholder="t('chat.moments.pick_date')"
                                append-to="body"
                                @update:model-value="(value) => onCorrection(y.year, c.country_id, value, c.correction)"
                            />
                            <button
                                v-if="c.correction && canUpdate"
                                type="button"
                                class="btn btn-sm btn-light btn-icon"
                                @click="setDate(y.year, c.country_id, null)"
                            >
                                <i class="ri-close-line"></i>
                            </button>
                        </div>
                    </div>
                </section>
            </div>
        </WalletModal>

        <ConfirmDeleteModal
            :show="deleteConfirm.state.show"
            :title="deleteConfirm.state.title"
            :message="deleteConfirm.state.message"
            :loading="deleteConfirm.state.loading"
            @close="deleteConfirm.close()"
            @confirm="handleDeleteConfirm"
        />
    </div>
</template>

<script setup>
import { computed, defineComponent, h, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import MultiSelect from 'primevue/multiselect';
import Select from 'primevue/select';
import adminAxios from '../../../../../../../api/adminAxios';
import CatalogTranslationTabs from '../../../../../../../components/catalog/CatalogTranslationTabs.vue';
import AdminDatePicker from '../../../../../../../components/ui/AdminDatePicker.vue';
import ConfirmDeleteModal from '../../../../../../../components/ui/ConfirmDeleteModal.vue';
import FormFieldFeedback from '../../../../../../../components/ui/FormFieldFeedback.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../composables/useToast';
import { useConfirmDelete } from '../../../../../../../composables/useConfirmDelete';
import { usePermission } from '../../../../../../../composables/usePermission';
import { useAvailableLanguagesStore } from '../../../../../../../stores/availableLanguages';

const { t, locale } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const languagesStore = useAvailableLanguagesStore();
const deleteConfirm = useConfirmDelete();

const canCreate = computed(() => can('chat-moments.create'));
const canUpdate = computed(() => can('chat-moments.update'));
const canDelete = computed(() => can('chat-moments.delete'));
const canMultipleDelete = computed(() => can('chat-moments.multiple-delete') || canDelete.value);
const showActionsColumn = computed(() => canUpdate.value || canDelete.value);
const tableColumnCount = computed(() => {
    let count = 6;

    if (canMultipleDelete.value) {
        count += 1;
    }

    if (showActionsColumn.value) {
        count += 1;
    }

    return count;
});

const kinds = ['religious', 'national', 'international', 'social', 'cultural', 'seasonal'];
const animations = ['confetti', 'lanterns', 'fireworks', 'hearts', 'stars', 'balloons', 'flags', 'snow', 'petals', 'sparkles'];
const themes = ['ramadan', 'eid', 'hajj', 'hijri_new_year', 'mawlid', 'national', 'new_year', 'christmas', 'spring', 'love', 'mother', 'father', 'women', 'teacher', 'children', 'friendship', 'work', 'culture', 'generic'];
const hijriMonths = ['محرم', 'صفر', 'ربيع الأول', 'ربيع الآخر', 'جمادى الأولى', 'جمادى الآخرة', 'رجب', 'شعبان', 'رمضان', 'شوال', 'ذو القعدة', 'ذو الحجة'];

const kindOptions = computed(() => kinds.map((value) => ({
    value,
    label: t(`chat.moments.kinds.${value}`),
})));
const ruleOptions = computed(() => [
    { value: 'gregorian', label: t('chat.moments.rules.gregorian') },
    { value: 'hijri', label: t('chat.moments.rules.hijri') },
    { value: 'manual', label: t('chat.moments.rules.manual') },
]);
const animationOptions = computed(() => animations.map((value) => ({
    value,
    label: t(`chat.moments.animations.${value}`),
})));
const themeOptions = computed(() => themes.map((value) => ({
    value,
    label: t(`chat.moments.themes.${value}`),
})));
const monthOptions = computed(() => Array.from({ length: 12 }, (_, index) => {
    const value = index + 1;

    if (form.date_rule === 'hijri') {
        return { value, label: hijriMonths[index] };
    }

    return {
        value,
        label: new Date(2000, index, 1).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US', { month: 'long' }),
    };
}));
const nameFilled = computed(() => !!(form.translations[activeLocale.value]?.name || '').trim());
const nameFeedback = computed(() => ({
    show: nameFilled.value || !!errors.translations,
    valid: nameFilled.value,
    invalid: !!errors.translations && !nameFilled.value,
}));
const nameInputClass = computed(() => ({
    'is-invalid': nameFeedback.value.show && nameFeedback.value.invalid,
    'is-valid': nameFeedback.value.show && nameFeedback.value.valid,
}));

/** The card as the phone draws it: the colours as a gradient, the emoji, the animation's sparkle. */
const MomentPreview = defineComponent({
    props: { look: { type: Object, required: true }, name: { type: String, default: '' }, greeting: { type: String, default: '' }, small: { type: Boolean, default: false } },
    setup(props) {
        return () => h('div', {
            class: ['moment-preview', props.small ? 'small' : '', `anim-${props.look.animation || 'confetti'}`],
            style: props.look.card_image
                ? { backgroundImage: `url(${props.look.card_image})` }
                : { background: `linear-gradient(150deg, ${props.look.primary_color || '#001B53'}, ${props.look.secondary_color || '#FA7552'})` },
        }, [
            h('div', { class: 'moment-sparkles' }, Array.from({ length: 10 }, (_, i) => h('span', { style: { left: `${(i * 37) % 100}%`, animationDelay: `${(i % 5) * 0.4}s` } }, props.look.emoji || '✨'))),
            h('div', { class: 'moment-text' }, [
                h('div', { class: 'moment-emoji' }, props.look.emoji || '✨'),
                h('div', { class: 'moment-name' }, props.name || '—'),
                props.greeting ? h('div', { class: 'moment-greeting' }, props.greeting) : null,
            ]),
        ]);
    },
});

const languages = computed(() => languagesStore.items);
const activeLocale = ref('');
const countries = ref([]);
const rows = ref([]);
const loading = ref(false);
const search = ref('');
const kindFilter = ref([]);
const statusFilter = ref('all');
const currentPage = ref(1);
const perPage = ref(15);
const togglingIds = ref([]);
const selectedIds = ref([]);
const selectedCount = computed(() => selectedIds.value.length);
const allSelected = computed(() => (
    pagedRows.value.length > 0 && pagedRows.value.every((row) => selectedIds.value.includes(Number(row.id)))
));

const counts = computed(() => ({
    total: rows.value.length,
    active: rows.value.filter((row) => row.status).length,
    inactive: rows.value.filter((row) => !row.status).length,
}));

const showAllFilter = computed(() => statusFilter.value !== 'all');

const filteredRows = computed(() => {
    const needle = search.value.trim().toLowerCase();
    const kindsSelected = kindFilter.value ?? [];

    return rows.value.filter((row) => {
        if (statusFilter.value === 'active' && !row.status) {
            return false;
        }

        if (statusFilter.value === 'inactive' && row.status) {
            return false;
        }

        if (kindsSelected.length && !kindsSelected.includes(row.kind)) {
            return false;
        }

        if (!needle) {
            return true;
        }

        return row.key?.includes(needle)
            || (row.translations ?? []).some((item) => (item.name || '').toLowerCase().includes(needle));
    });
});

const pagination = computed(() => {
    const total = filteredRows.value.length;
    const lastPage = Math.max(1, Math.ceil(total / perPage.value) || 1);
    const page = Math.min(currentPage.value, lastPage);
    const from = total ? ((page - 1) * perPage.value) + 1 : 0;
    const to = Math.min(page * perPage.value, total);

    return {
        total,
        from,
        to,
        last_page: lastPage,
        prev_page_url: page > 1 ? true : null,
        next_page_url: page < lastPage ? true : null,
    };
});

const pagedRows = computed(() => {
    const page = Math.min(currentPage.value, pagination.value.last_page);
    const start = (page - 1) * perPage.value;

    return filteredRows.value.slice(start, start + perPage.value);
});

const pageNumbers = computed(() => {
    const total = pagination.value.last_page;
    const current = Math.min(currentPage.value, total);
    const pages = [];
    let start = Math.max(1, current - 2);
    let end = Math.min(total, start + 4);

    if (end - start < 4) {
        start = Math.max(1, end - 4);
    }

    for (let page = start; page <= end; page += 1) {
        pages.push(page);
    }

    return pages;
});

const entriesLabel = computed(() => t('chat.moments.showing_entries', {
    from: pagination.value.from ?? 0,
    to: pagination.value.to ?? 0,
    total: pagination.value.total ?? 0,
}));

const paginationArrowIcon = computed(() => (
    locale.value === 'ar'
        ? 'ri-arrow-left-s-line fw-semibold'
        : 'ri-arrow-right-s-line fw-semibold'
));

const showModal = ref(false);
const editingId = ref(null);
const saving = ref(false);
const errors = reactive({});
const blank = () => ({
    kind: 'social', date_rule: 'gregorian', month: 1, day: 1, duration_days: 1, show_before_days: 3, show_after_days: 0, countries: [], default_on: true,
    theme: 'generic', primary_color: '#001B53', secondary_color: '#FA7552', emoji: '✨', animation: 'confetti', status: true, translations: {}, card: null, remove_card: false,
});
const form = reactive(blank());
const cardPreview = ref(null);
const cardInput = ref(null);
const previewLook = computed(() => ({ ...form, card_image: cardPreview.value }));
const datesFor = ref(null);

watch([search, kindFilter, statusFilter, perPage], () => {
    currentPage.value = 1;
    selectedIds.value = [];
});

watch(filteredRows, () => {
    if (currentPage.value > pagination.value.last_page) {
        currentPage.value = pagination.value.last_page;
    }
});

function setStatusFilter(value) {
    statusFilter.value = value;
}

function clearSearch() {
    search.value = '';
}

function changePage(page) {
    if (page < 1 || page > pagination.value.last_page) {
        return;
    }

    currentPage.value = page;
}

function isTogglingStatus(id) {
    return togglingIds.value.includes(Number(id));
}

function displayName(row) {
    const translation = row.translations?.find((item) => item.locale === locale.value);

    return translation?.name || row.name || '-';
}

function countryNames(row) {
    const codes = row.countries ?? [];
    const catalog = countries.value;

    if (! codes.length) {
        return t('chat.moments.everywhere');
    }

    if (catalog.length && codes.length >= catalog.length && catalog.every((item) => codes.includes(item.code))) {
        return t('chat.moments.everywhere');
    }

    return codes
        .map((code) => catalog.find((item) => item.code === code)?.name || code)
        .join(', ');
}

function formatDate(value) {
    if (! value) {
        return '-';
    }

    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatNextDate(value) {
    if (! value) {
        return '—';
    }

    const parts = String(value).split('-').map(Number);
    const [year, month, day] = parts;

    if (! year || ! month || ! day) {
        return value;
    }

    return new Date(year, month - 1, day).toLocaleDateString(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function formatDates(values) {
    if (! values?.length) {
        return '—';
    }

    return values.map((value) => formatNextDate(value)).join(' · ');
}

function countryDisplayName(row) {
    if (row.name) {
        return row.name;
    }

    return countries.value.find((item) => item.code === row.code)?.name || '';
}

function thumbStyle(row) {
    if (row.card_image) {
        return { backgroundImage: `url(${row.card_image})`, backgroundSize: 'cover', backgroundPosition: 'center' };
    }

    return { background: `linear-gradient(150deg, ${row.primary_color || '#001B53'}, ${row.secondary_color || '#FA7552'})` };
}

function ruleLabel(row) {
    if (row.date_rule === 'manual') {
        return t('chat.moments.rules.manual');
    }

    if (row.date_rule === 'hijri') {
        return `${t('chat.moments.rules.hijri')} · ${row.day} ${hijriMonths[(row.month || 1) - 1]}`;
    }

    return t('chat.moments.rules.gregorian');
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
    languages.value.forEach((lang) => { form.translations[lang.code] ??= { name: '', greeting: '' }; });
    if (!activeLocale.value && languages.value.length) activeLocale.value = languages.value[0].code;
}

function resetErrors() {
    Object.keys(errors).forEach((key) => delete errors[key]);
}

function onNameInput() {
    delete errors.translations;
}

function pickCard(event) {
    const file = event.target.files?.[0] || null;
    form.card = file;
    form.remove_card = false;
    if (file) cardPreview.value = URL.createObjectURL(file);
    if (cardInput.value) cardInput.value.value = '';
}

function removeCard() {
    form.card = null;
    form.remove_card = true;
    cardPreview.value = null;
}

function openCreate() {
    editingId.value = null;
    resetErrors();
    Object.assign(form, blank());
    cardPreview.value = null;
    ensureTranslations();
    activeLocale.value = languages.value[0]?.code || '';
    showModal.value = true;
}

function openEdit(row) {
    editingId.value = row.id;
    resetErrors();
    Object.assign(form, blank(), {
        kind: row.kind, date_rule: row.date_rule, month: row.month || 1, day: row.day || 1, duration_days: row.duration_days, show_before_days: row.show_before_days,
        show_after_days: row.show_after_days, countries: [...(row.countries || [])], default_on: row.default_on, theme: row.theme, primary_color: row.primary_color || '#001B53',
        secondary_color: row.secondary_color || '#FA7552', emoji: row.emoji || '', animation: row.animation || 'confetti', status: row.status, translations: {},
    });
    cardPreview.value = row.card_image;
    ensureTranslations();
    (row.translations ?? []).forEach((tr) => { form.translations[tr.locale] = { name: tr.name ?? '', greeting: tr.greeting ?? '' }; });
    activeLocale.value = languages.value[0]?.code || '';
    showModal.value = true;
}

async function save() {
    resetErrors();
    saving.value = true;
    const body = new FormData();
    ['kind', 'date_rule', 'duration_days', 'show_before_days', 'show_after_days', 'theme', 'primary_color', 'secondary_color', 'emoji', 'animation'].forEach((k) => body.append(k, form[k] ?? ''));
    if (form.date_rule !== 'manual') {
        body.append('month', String(form.month));
        body.append('day', String(form.day));
    }
    body.append('default_on', form.default_on ? '1' : '0');
    body.append('status', form.status ? '1' : '0');
    (form.countries || []).forEach((c, i) => body.append(`countries[${i}]`, c));
    if (!(form.countries || []).length) body.append('countries', '');
    languages.value.forEach((lang, i) => {
        body.append(`translations[${i}][locale]`, lang.code);
        body.append(`translations[${i}][name]`, form.translations[lang.code]?.name || '');
        body.append(`translations[${i}][greeting]`, form.translations[lang.code]?.greeting || '');
    });
    if (form.card) body.append('card', form.card);
    if (form.remove_card) body.append('remove_card', '1');

    try {
        await adminAxios.post(editingId.value ? `/api/admin/v1/chat-moments/${editingId.value}` : '/api/admin/v1/chat-moments', body);
        showSuccess(t('chat.moments.saved'));
        showModal.value = false;
        load();
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};
        Object.entries(bag).forEach(([key, messages]) => { errors[key.startsWith('translations') ? 'translations' : key] ??= messages[0]; });
        errors.general = Object.keys(bag).length ? Object.values(bag).map((m) => m[0]).join(' · ') : extractApiErrorMessage(error);
    } finally {
        saving.value = false;
    }
}

function isSelected(id) {
    return selectedIds.value.includes(Number(id));
}

function onRowSelect(id, checked) {
    const normalizedId = Number(id);

    if (checked) {
        if (! selectedIds.value.includes(normalizedId)) {
            selectedIds.value = [...selectedIds.value, normalizedId];
        }

        return;
    }

    selectedIds.value = selectedIds.value.filter((item) => item !== normalizedId);
}

function onSelectAll(checked) {
    const pageIds = pagedRows.value.map((row) => Number(row.id));

    if (! checked) {
        selectedIds.value = selectedIds.value.filter((id) => ! pageIds.includes(id));

        return;
    }

    selectedIds.value = [...new Set([...selectedIds.value, ...pageIds])];
}

async function toggleStatus(row) {
    if (isTogglingStatus(row.id)) {
        return;
    }

    togglingIds.value = [...togglingIds.value, Number(row.id)];

    try {
        const response = await adminAxios.patch(`/api/admin/v1/chat-moments/${row.id}/status`, { status: !row.status });
        row.status = !row.status;
        row.updated_at = response.data?.data?.updated_at ?? row.updated_at;
        showSuccess(extractApiMessage(response, t('toast.updated')));
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        togglingIds.value = togglingIds.value.filter((id) => id !== Number(row.id));
    }
}

function confirmDelete(row) {
    deleteConfirm.open({
        title: t('chat.moments.delete_title'),
        message: t('chat.moments.confirm_delete', { name: displayName(row) }),
        payload: { type: 'single', row },
    });
}

function confirmDeleteSelected() {
    deleteConfirm.open({
        title: t('chat.moments.delete_selected_title'),
        message: t('chat.moments.confirm_delete_selected'),
        payload: { type: 'multiple' },
    });
}

async function handleDeleteConfirm() {
    const payload = deleteConfirm.state.payload;

    deleteConfirm.setLoading(true);

    try {
        if (payload?.type === 'multiple') {
            const ids = [...selectedIds.value];
            const response = await adminAxios.post('/api/admin/v1/chat-moments/delete-multiple', { ids });
            showSuccess(extractApiMessage(response, t('toast.deleted')));
            selectedIds.value = [];
        } else if (payload?.row) {
            const response = await adminAxios.delete(`/api/admin/v1/chat-moments/${payload.row.id}`);
            showSuccess(extractApiMessage(response, t('chat.common.deleted')));
            selectedIds.value = selectedIds.value.filter((id) => id !== Number(payload.row.id));
        } else {
            deleteConfirm.setLoading(false);

            return;
        }

        deleteConfirm.close();
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
        deleteConfirm.setLoading(false);
    }
}

async function openDates(row) {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/chat-moments/${row.id}/dates`);
        datesFor.value = data.data;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

function onCorrection(year, countryId, value, current) {
    const next = value || null;
    const prev = current || null;

    if (next === prev) {
        return;
    }

    setDate(year, countryId, next);
}

async function setDate(year, countryId, date) {
    try {
        const { data } = await adminAxios.put(`/api/admin/v1/chat-moments/${datesFor.value.moment.id}/dates`, { year, country_id: countryId, date: date || null });
        const first = datesFor.value.years[0]?.year;
        datesFor.value = data.data;
        if (first && first !== year) {
            const again = await adminAxios.get(`/api/admin/v1/chat-moments/${datesFor.value.moment.id}/dates`, { params: { year: first } });
            datesFor.value = again.data.data;
        }
        showSuccess(t('chat.moments.date_saved'));
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/chat-moments');
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
        countries.value = (data.data ?? []).map((c) => ({ code: c.code, name: c.name, label: `${c.name} (${c.code})` }));
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
});
</script>

<style>
.moment-dates-year {
    border: 1px solid var(--default-border, #dee2e6);
    border-radius: 0.5rem;
    overflow: hidden;
}
.moment-dates-year + .moment-dates-year {
    margin-top: 0.75rem;
}
.moment-dates-year__head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.55rem 0.85rem;
    background: var(--light, #f8f9fa);
    border-bottom: 1px solid var(--default-border, #dee2e6);
}
.moment-dates-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.55rem 0.85rem;
    border-bottom: 1px solid var(--default-border, #eee);
}
.moment-dates-row:last-child {
    border-bottom: 0;
}
.moment-dates-row__meta {
    min-width: 0;
    flex: 1;
}
.moment-dates-row__field {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    flex: 0 0 13.75rem;
    width: 13.75rem;
}
.moment-dates-row__field .admin-date-picker {
    width: 100%;
    min-width: 0;
}
.moment-color-swatch {
    width: 1.75rem;
    height: 1.75rem;
    min-width: 1.75rem;
    cursor: pointer;
}
.service-category-image-row {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    flex-wrap: wrap;
}
.service-category-image-actions {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    min-width: 9.75rem;
}
.service-category-image-actions__btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    padding-inline: 0.875rem;
    font-weight: 500;
    white-space: nowrap;
}
.service-category-image-actions__btn i {
    font-size: 1rem;
    line-height: 1;
}
.service-category-image-actions__btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
.service-category-image-box {
    position: relative;
    display: inline-flex;
    width: 5rem;
    height: 5rem;
    flex-shrink: 0;
    overflow: hidden;
    border-radius: 0.5rem;
    background: #eef1f5;
    border: 1px solid var(--default-border, #dee2e6);
}
.moment-card-box {
    width: 4.5rem;
    height: 6.5rem;
    align-items: center;
    justify-content: center;
    color: #fff;
    background-size: cover;
    background-position: center;
}
.moment-card-box__emoji {
    font-size: 1.5rem;
    line-height: 1;
}
.service-category-image-box__img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.service-category-image-box__badge {
    position: absolute;
    inset-inline-end: 0.35rem;
    inset-block-end: 0.35rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.75rem;
    height: 1.75rem;
    margin: 0;
    border-radius: 999px;
    background: rgb(var(--primary-rgb, 132, 90, 223));
    color: #fff;
    cursor: pointer;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
}
.service-category-image-box__badge i {
    font-size: 0.875rem;
    line-height: 1;
}
.moment-thumb { color: #fff; font-size: 1.15rem; display: inline-flex; align-items: center; justify-content: center; }
.moment-preview { position: relative; height: 230px; border-radius: 16px; overflow: hidden; background-size: cover; background-position: center; display: flex; align-items: center; justify-content: center; }
.moment-preview.small { height: 150px; border-radius: 0; }
.moment-text { position: relative; text-align: center; color: #fff; padding: 12px; text-shadow: 0 2px 8px rgba(0, 0, 0, 0.35); }
.moment-emoji { font-size: 44px; animation: moment-bob 2.4s ease-in-out infinite; }
.moment-preview.small .moment-emoji { font-size: 34px; }
.moment-name { font-weight: 800; font-size: 20px; }
.moment-preview.small .moment-name { font-size: 16px; }
.moment-greeting { font-size: 13px; opacity: 0.92; margin-top: 4px; }
.moment-sparkles { position: absolute; inset: 0; pointer-events: none; }
.moment-sparkles span { position: absolute; top: -20%; font-size: 14px; opacity: 0.55; animation: moment-fall 4s linear infinite; }
.anim-fireworks .moment-sparkles span, .anim-sparkles .moment-sparkles span, .anim-stars .moment-sparkles span { animation-name: moment-twinkle; top: auto; }
.anim-balloons .moment-sparkles span, .anim-lanterns .moment-sparkles span { animation-name: moment-rise; top: 110%; }
@keyframes moment-fall { to { transform: translateY(320px) rotate(160deg); } }
@keyframes moment-rise { to { transform: translateY(-320px); } }
@keyframes moment-twinkle { 0%, 100% { opacity: 0.1; transform: scale(0.6); } 50% { opacity: 0.9; transform: scale(1.2); } }
@keyframes moment-bob { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }
.moment-sparkles span:nth-child(odd) { top: 20%; }
.moment-sparkles span:nth-child(3n) { top: 60%; }
</style>
