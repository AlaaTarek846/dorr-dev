<template>
    <div class="header-element notifications-dropdown">
        <!-- Start::header-link|dropdown-toggle -->
        <a
            href="javascript:void(0);"
            class="header-link dropdown-toggle"
            data-bs-toggle="dropdown"
            data-bs-auto-close="outside"
            id="notificationDropdownToggle"
            aria-expanded="false"
        >
            <i class="bx bx-bell header-link-icon"></i>
            <span
                v-if="count > 0"
                class="badge bg-secondary rounded-pill header-icon-badge pulse pulse-secondary"
                id="notification-icon-badge"
            >
                {{ count > 99 ? '99+' : count }}
            </span>
        </a>
        <!-- End::header-link|dropdown-toggle -->

        <!-- Start::main-header-dropdown -->
        <div class="main-header-dropdown dropdown-menu dropdown-menu-end" data-popper-placement="none">
            <div class="p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <p class="mb-0 fs-17 fw-semibold">{{ t('notifications.title') }}</p>
                    <div class="d-flex align-items-center gap-2">
                        <span v-if="count > 0" class="badge bg-secondary-transparent" id="notification-data">
                            {{ count }} {{ t('notifications.unread') }}
                        </span>
                        <button
                            v-if="count > 0"
                            type="button"
                            class="btn btn-sm btn-link text-muted p-0 fs-12 text-decoration-none"
                            :title="t('notifications.clear_all')"
                            @click.prevent="clearAll"
                        >
                            {{ t('notifications.clear_all') }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="dropdown-divider my-0"></div>

            <template v-if="notifications.length > 0">
                <ul class="list-unstyled mb-0 overflow-auto" style="max-height: 320px;" id="header-notification-scroll">
                    <li
                        v-for="(notification, index) in notifications"
                        :key="notification.id || index"
                        class="dropdown-item py-2 px-3 border-bottom"
                    >
                        <div class="d-flex align-items-start">
                            <div class="pe-2">
                                <span class="avatar avatar-md bg-primary-transparent avatar-rounded">
                                    <img
                                        v-if="notification.image"
                                        class="avatar-img rounded-circle"
                                        :src="notification.image"
                                        alt="img"
                                    />
                                    <i v-else :class="[notificationIcon(notification), 'fs-18 text-primary']"></i>
                                </span>
                            </div>
                            <div class="flex-grow-1 d-flex align-items-start justify-content-between">
                                <div class="pe-2">
                                    <p class="mb-1 fw-semibold fs-13 text-dark text-break">
                                        {{ notification.title }}
                                    </p>
                                    <p class="mb-1 text-muted fs-12 text-break">
                                        {{ notification.message }}
                                    </p>
                                    <span class="text-muted fw-normal fs-11 header-notification-text">
                                        {{ notification.created_at }}
                                    </span>
                                    <RouterLink
                                        v-if="notificationLink(notification)"
                                        class="fs-11 ms-2"
                                        :to="notificationLink(notification)"
                                        @click="clearItem(notification.id, index)"
                                    >
                                        {{ t('notifications.open') }}
                                    </RouterLink>
                                </div>
                                <div class="ms-auto">
                                    <a
                                        href="javascript:void(0);"
                                        class="min-w-fit-content text-muted dropdown-item-close1"
                                        :title="t('notifications.dismiss')"
                                        @click.prevent="clearItem(notification.id, index)"
                                    >
                                        <i class="ti ti-x fs-16"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </li>
                </ul>
            </template>

            <div v-else class="p-4 text-center empty-item1">
                <span class="avatar avatar-xl avatar-rounded bg-secondary-transparent">
                    <i class="ri-notification-off-line fs-2 text-muted"></i>
                </span>
                <h6 class="fw-semibold mt-3 mb-0 fs-14">{{ t('notifications.no_new_notifications') }}</h6>
            </div>

            <div class="p-3 empty-header-item1 border-top">
                <div class="d-grid">
                    <RouterLink
                        class="btn btn-primary btn-sm"
                        :to="{ name: 'admin.notifications.index' }"
                    >
                        {{ t('notifications.view_all') }}
                    </RouterLink>
                </div>
            </div>
        </div>
        <!-- End::main-header-dropdown -->
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../api/adminAxios';
import { useAuthStore } from '../../../stores/auth';
import { useToastStore } from '../../../stores/toast';
import { notificationIcon, notificationLink } from '../../../utils/notificationLink';

const { t } = useI18n();
const authStore = useAuthStore();
const toastStore = useToastStore();

const notifications = ref([]);
const count = ref(0);
let channelInstance = null;

async function fetchUnreadNotifications() {
    if (!authStore.isAuthenticated) {
        return;
    }

    try {
        const response = await adminAxios.get('/api/admin/v1/notifications/unread');
        const payload = response.data?.data;
        if (payload) {
            notifications.value = payload.notifications || [];
            count.value = payload.count ?? notifications.value.length;
        }
    } catch (error) {
        console.warn('[NotificationDropdown] Failed to fetch unread notifications:', error);
    }
}

async function clearItem(id, index) {
    try {
        await adminAxios.post(`/api/admin/v1/notifications/${id}/read`);
        notifications.value.splice(index, 1);
        count.value = Math.max(0, count.value - 1);
    } catch (error) {
        console.error('[NotificationDropdown] Failed to mark as read:', error);
    }
}

async function clearAll() {
    try {
        await adminAxios.post('/api/admin/v1/notifications/read-all');
        notifications.value = [];
        count.value = 0;
    } catch (error) {
        console.error('[NotificationDropdown] Failed to clear all:', error);
    }
}

function handleIncomingNotification(notification) {
    const payload = notification?.payload || notification?.data || notification || {};
    const title = payload.title || t('notifications.new_notification');
    const message = payload.message || '';
    const image = payload.image || '';

    const newNotificationItem = {
        id: payload.id || `temp-${Date.now()}`,
        title,
        message,
        image,
        event: payload.data?.event || null,
        data: payload.data || {},
        created_at: payload.timeDate || new Date().toISOString().slice(0, 16).replace('T', ' '),
        read_at: null,
    };

    notifications.value.unshift(newNotificationItem);
    count.value += 1;

    toastStore.push({
        message: `${title}: ${message}`,
        type: 'info',
        duration: 8000,
    });
}

function setupEchoListener() {
    const adminId = authStore.admin?.id;
    if (!adminId || !window.Echo) {
        return;
    }

    teardownEchoListener();

    try {
        channelInstance = window.Echo.private(`App.Models.Admin.${adminId}`);
        channelInstance.notification(handleIncomingNotification);
    } catch (error) {
        console.warn('[NotificationDropdown] Echo subscription error:', error);
    }
}

function teardownEchoListener() {
    const adminId = authStore.admin?.id;
    if (adminId && window.Echo) {
        try {
            window.Echo.leave(`App.Models.Admin.${adminId}`);
        } catch (error) {
            // Ignore teardown errors
        }
    }
    channelInstance = null;
}

watch(
    () => authStore.admin?.id,
    (newId) => {
        if (newId) {
            fetchUnreadNotifications();
            setupEchoListener();
        } else {
            teardownEchoListener();
            notifications.value = [];
            count.value = 0;
        }
    }
);

onMounted(() => {
    fetchUnreadNotifications();
    setupEchoListener();
});

onUnmounted(() => {
    teardownEchoListener();
});
</script>

<style scoped>
.header-notification-text {
    display: block;
    margin-top: 2px;
}
.dropdown-item-close1 {
    opacity: 0.6;
    transition: opacity 0.2s ease;
}
.dropdown-item-close1:hover {
    opacity: 1;
}
</style>
