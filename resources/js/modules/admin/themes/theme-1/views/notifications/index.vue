<template>
    <div class="container-fluid">
        <!-- Page Header -->
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">{{ t('notifications.title') }}</h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <RouterLink :to="{ name: 'admin.dashboard' }">{{ t('global.home') }}</RouterLink>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('notifications.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="row justify-content-center">
            <div class="col-xl-9 col-lg-10 col-md-12">
                <div class="card custom-card">
                    <div class="card-header justify-content-between align-items-center">
                        <div class="card-title mb-0">
                            {{ t('notifications.all_notifications') }}
                        </div>
                        <div>
                            <button
                                v-if="notifications.length > 0"
                                type="button"
                                class="btn btn-sm btn-outline-primary"
                                :disabled="isClearing"
                                @click="markAllAsRead"
                            >
                                <i class="bx bx-check-double me-1"></i>
                                {{ t('notifications.mark_all_read') }}
                            </button>
                        </div>
                    </div>

                    <div class="card-body p-0">
                        <div v-if="loading" class="text-center py-5">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>

                        <ul v-else-if="notifications.length > 0" class="list-group list-group-flush mb-0">
                            <li
                                v-for="(notification, index) in notifications"
                                :key="notification.id"
                                class="list-group-item p-3 transition"
                                :class="{ 'bg-light-subtle': !notification.read_at }"
                            >
                                <div class="d-flex align-items-start gap-3">
                                    <span class="avatar avatar-md rounded-circle flex-shrink-0" :class="notification.read_at ? 'bg-light text-muted' : 'bg-primary-transparent text-primary'">
                                        <img
                                            v-if="notification.image"
                                            :src="notification.image"
                                            class="rounded-circle avatar-img"
                                            alt="img"
                                        />
                                        <i v-else :class="[notificationIcon(notification), 'fs-20']"></i>
                                    </span>

                                    <div class="flex-grow-1 min-w-0">
                                        <div class="d-flex align-items-baseline justify-content-between flex-wrap gap-2">
                                            <h6 class="mb-1 fs-14 fw-semibold text-dark text-break">
                                                {{ notification.title }}
                                                <span v-if="!notification.read_at" class="badge bg-primary-transparent fs-10 ms-2">
                                                    {{ t('notifications.unread') }}
                                                </span>
                                            </h6>
                                            <small class="text-muted fs-11">
                                                {{ notification.created_at }}
                                            </small>
                                        </div>
                                        <p class="mb-0 text-muted fs-13 text-break">
                                            {{ notification.message }}
                                        </p>
                                        <RouterLink
                                            v-if="notificationLink(notification)"
                                            class="fs-12 d-inline-block mt-1"
                                            :to="notificationLink(notification)"
                                            @click="markItemAsRead(notification, index)"
                                        >
                                            {{ t('notifications.open') }}
                                        </RouterLink>
                                    </div>

                                    <div class="flex-shrink-0" v-if="!notification.read_at">
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-icon btn-light rounded-circle"
                                            :title="t('notifications.mark_as_read')"
                                            @click="markItemAsRead(notification, index)"
                                        >
                                            <i class="bx bx-check fs-16"></i>
                                        </button>
                                    </div>
                                </div>
                            </li>
                        </ul>

                        <div v-else class="text-center py-5">
                            <span class="avatar avatar-xxl avatar-rounded bg-light text-muted mb-3">
                                <i class="ri-notification-off-line fs-1"></i>
                            </span>
                            <h6 class="fw-semibold text-dark">{{ t('notifications.no_notifications') }}</h6>
                            <p class="text-muted fs-12 mb-0">{{ t('notifications.no_notifications_desc') }}</p>
                        </div>
                    </div>

                    <!-- Pagination Footer -->
                    <div v-if="pagination && pagination.total > pagination.per_page" class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span class="fs-12 text-muted">
                            {{ pagination.from }} - {{ pagination.to }} / {{ pagination.total }}
                        </span>
                        <div class="btn-group btn-group-sm">
                            <button
                                type="button"
                                class="btn btn-outline-light text-dark"
                                :disabled="pagination.current_page <= 1"
                                @click="changePage(pagination.current_page - 1)"
                            >
                                <i class="bx bx-chevron-right" v-if="isRtl"></i>
                                <i class="bx bx-chevron-left" v-else></i>
                            </button>
                            <button
                                type="button"
                                class="btn btn-outline-light text-dark"
                                :disabled="pagination.current_page >= pagination.last_page"
                                @click="changePage(pagination.current_page + 1)"
                            >
                                <i class="bx bx-chevron-left" v-if="isRtl"></i>
                                <i class="bx bx-chevron-right" v-else></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import { notificationIcon, notificationLink } from '../../../../../../utils/notificationLink';

const { t, locale } = useI18n();

const isRtl = computed(() => locale.value === 'ar');

const notifications = ref([]);
const loading = ref(false);
const isClearing = ref(false);
const pagination = ref(null);
const currentPage = ref(1);

async function fetchNotifications(page = 1) {
    loading.value = true;
    try {
        const response = await adminAxios.get('/api/admin/v1/notifications', {
            params: { page, per_page: 15 },
        });
        notifications.value = response.data?.data || [];
        pagination.value = response.data?.pagination || null;
        currentPage.value = page;
    } catch (error) {
        console.error('[NotificationsView] Failed to load notifications:', error);
    } finally {
        loading.value = false;
    }
}

async function markItemAsRead(notification, index) {
    try {
        await adminAxios.post(`/api/admin/v1/notifications/${notification.id}/read`);
        notifications.value[index].read_at = new Date().toISOString().slice(0, 16).replace('T', ' ');
    } catch (error) {
        console.error('[NotificationsView] Failed to mark as read:', error);
    }
}

async function markAllAsRead() {
    isClearing.value = true;
    try {
        await adminAxios.post('/api/admin/v1/notifications/read-all');
        notifications.value.forEach((item) => {
            if (!item.read_at) {
                item.read_at = new Date().toISOString().slice(0, 16).replace('T', ' ');
            }
        });
    } catch (error) {
        console.error('[NotificationsView] Failed to mark all as read:', error);
    } finally {
        isClearing.value = false;
    }
}

function changePage(page) {
    fetchNotifications(page);
}

onMounted(() => {
    fetchNotifications();
});
</script>

<style scoped>
.transition {
    transition: background-color 0.2s ease;
}
</style>
