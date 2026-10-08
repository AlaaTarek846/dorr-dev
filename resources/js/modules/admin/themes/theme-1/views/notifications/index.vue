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

        <div class="notif-wrap mx-auto">
            <div v-if="notifications.length > 0" class="d-flex justify-content-end mb-3">
                <button type="button" class="btn btn-sm btn-outline-primary" :disabled="isClearing || ! hasUnread" @click="markAllAsRead">
                    <i class="bx bx-check-double me-1"></i>{{ t('notifications.mark_all_read') }}
                </button>
            </div>

            <!-- first load -->
            <div v-if="loading && ! notifications.length" class="d-flex flex-column gap-3" aria-busy="true">
                <div v-for="n in 6" :key="n" class="notif-card notif-card--skeleton placeholder-glow">
                    <span class="placeholder rounded-circle flex-shrink-0" style="width: 40px; height: 40px;"></span>
                    <div class="flex-grow-1">
                        <span class="placeholder col-3 d-block mb-2"></span>
                        <span class="placeholder col-7 placeholder-sm d-block mb-1"></span>
                        <span class="placeholder col-2 placeholder-sm d-block"></span>
                    </div>
                </div>
            </div>

            <template v-else-if="notifications.length > 0">
                <div class="d-flex flex-column gap-3">
                    <div
                        v-for="(notification, index) in notifications"
                        :key="notification.id"
                        class="notif-card"
                        :class="[notification.read_at ? 'notif-card--read' : 'notif-card--unread', { 'notif-card--link': notificationLink(notification) }]"
                        @click="openCard(notification, $event)"
                    >
                        <span class="avatar avatar-md avatar-rounded flex-shrink-0 position-relative notif-avatar" :class="notification.read_at ? 'bg-light text-muted' : 'bg-primary-transparent text-primary'">
                            <img v-if="notification.image" :src="notification.image" class="avatar-img rounded-circle" alt="img">
                            <i v-else :class="[notificationIcon(notification), 'fs-20']"></i>
                            <span class="notif-dot" :class="notification.read_at ? 'notif-dot--read' : 'notif-dot--unread'"></span>
                        </span>

                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-semibold text-dark text-break" :class="{ 'fw-bold': ! notification.read_at }">{{ notification.title }}</div>
                            <div class="text-muted fs-13 text-break">{{ notification.message }}</div>
                            <div class="d-flex align-items-center flex-wrap gap-2 mt-1">
                                <span class="text-muted fs-12">{{ timeAgo(notification) }}</span>
                                <RouterLink
                                    v-if="notificationLink(notification)"
                                    class="fs-12"
                                    :to="notificationLink(notification)"
                                    @click="markItemAsRead(notification, index)"
                                >
                                    {{ t('notifications.open') }}
                                </RouterLink>
                            </div>
                        </div>

                        <div class="d-flex align-items-start gap-2 flex-shrink-0">
                            <span class="notif-date">{{ shortDate(notification) }}</span>
                            <button
                                v-if="! notification.read_at"
                                type="button"
                                class="btn btn-sm btn-icon btn-light rounded-circle"
                                :title="t('notifications.mark_as_read')"
                                @click="markItemAsRead(notification, index)"
                            >
                                <i class="bx bx-check fs-16"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- the next 20 load when this comes into view -->
                <div ref="sentinel" class="d-flex justify-content-center py-4">
                    <span v-if="loadingMore" class="notif-loading">
                        {{ t('notifications.loading') }}
                        <span class="spinner-border spinner-border-sm ms-2"></span>
                    </span>
                    <span v-else-if="! hasMore" class="text-muted fs-12">{{ t('notifications.end_of_list') }}</span>
                </div>
            </template>

            <div v-else class="text-center py-5">
                <span class="avatar avatar-xxl avatar-rounded bg-light text-muted mb-3">
                    <i class="ri-notification-off-line fs-1"></i>
                </span>
                <h6 class="fw-semibold text-dark">{{ t('notifications.no_notifications') }}</h6>
                <p class="text-muted fs-12 mb-0">{{ t('notifications.no_notifications_desc') }}</p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import { notificationIcon, notificationLink } from '../../../../../../utils/notificationLink';

const PER_PAGE = 20;

const { t, locale } = useI18n();
const router = useRouter();

const notifications = ref([]);
const loading = ref(false);
const loadingMore = ref(false);
const isClearing = ref(false);
const currentPage = ref(0);
const hasMore = ref(true);
const sentinel = ref(null);
let observer = null;

const hasUnread = computed(() => notifications.value.some((item) => ! item.read_at));

/** The newest 20 first; each further page (older ones) is appended when the end of the list is reached. */
async function fetchNext() {
    if (loading.value || loadingMore.value || ! hasMore.value) {
        return;
    }

    const page = currentPage.value + 1;

    if (page === 1) {
        loading.value = true;
    } else {
        loadingMore.value = true;
    }

    try {
        const response = await adminAxios.get('/api/admin/v1/notifications', { params: { page, per_page: PER_PAGE } });
        const rows = response.data?.data || [];
        const known = new Set(notifications.value.map((item) => item.id));

        notifications.value.push(...rows.filter((row) => ! known.has(row.id)));
        currentPage.value = page;
        hasMore.value = Boolean(response.data?.pagination?.has_more_pages);
    } catch (error) {
        console.error('[NotificationsView] Failed to load notifications:', error);
        hasMore.value = false;
    } finally {
        loading.value = false;
        loadingMore.value = false;
    }

    // A short first page may leave the end of the list on screen: keep filling until it is pushed out of view.
    await nextTick();
    if (hasMore.value && sentinel.value && sentinel.value.getBoundingClientRect().top < window.innerHeight) {
        fetchNext();
    }
}

// The whole card opens what the notification is about (a ticket's conversation, a withdrawal…), not only its link.
async function openCard(notification, event) {
    const target = notificationLink(notification);

    if (! target || event.target.closest('a, button')) {
        return;
    }

    if (! notification.read_at) {
        markItemAsRead(notification);
    }

    router.push(target);
}

async function markItemAsRead(notification) {
    try {
        await adminAxios.post(`/api/admin/v1/notifications/${notification.id}/read`);
        notification.read_at = new Date().toISOString().slice(0, 16).replace('T', ' ');
    } catch (error) {
        console.error('[NotificationsView] Failed to mark as read:', error);
    }
}

async function markAllAsRead() {
    isClearing.value = true;
    try {
        await adminAxios.post('/api/admin/v1/notifications/read-all');
        const now = new Date().toISOString().slice(0, 16).replace('T', ' ');
        notifications.value.forEach((item) => {
            if (! item.read_at) {
                item.read_at = now;
            }
        });
    } catch (error) {
        console.error('[NotificationsView] Failed to mark all as read:', error);
    } finally {
        isClearing.value = false;
    }
}

// "12 mins ago" in the viewer's language, worked out from the UTC instant.
function timeAgo(notification) {
    const moment = notification.created_at_iso ? new Date(notification.created_at_iso) : null;

    if (! moment || Number.isNaN(moment.getTime())) {
        return notification.created_at;
    }

    const seconds = Math.round((moment.getTime() - Date.now()) / 1000);
    const units = [['year', 31536000], ['month', 2592000], ['day', 86400], ['hour', 3600], ['minute', 60]];
    const formatter = new Intl.RelativeTimeFormat(locale.value === 'ar' ? 'ar' : 'en', { numeric: 'auto', style: 'short' });

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return formatter.format(Math.round(seconds / size), unit);
        }
    }

    return formatter.format(0, 'second');
}

// The small grey badge on the right: "24, Oct 2022".
function shortDate(notification) {
    const moment = notification.created_at_iso ? new Date(notification.created_at_iso) : null;

    if (! moment || Number.isNaN(moment.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { day: '2-digit', month: 'short', year: 'numeric' }).format(moment);
}

// The end of the list is watched: when it comes into view the next page is fetched. It only exists once there are rows.
watch(sentinel, (element, previous) => {
    if (previous) {
        observer?.unobserve(previous);
    }

    if (element) {
        observer?.observe(element);
    }
});

onMounted(() => {
    observer = new IntersectionObserver((entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
            fetchNext();
        }
    }, { rootMargin: '120px' });

    fetchNext();
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<style scoped>
.notif-wrap {
    max-width: 860px;
}

.notif-card {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    padding: 1rem 1.25rem;
    border-radius: 0.5rem;
    border-inline-start: 4px solid transparent;
    background: var(--custom-white, #fff);
    box-shadow: 0 1px 4px rgba(15, 23, 42, 0.08);
    transition: background-color 0.2s ease, box-shadow 0.2s ease;
}

.notif-card--link {
    cursor: pointer;
}

.notif-card:hover {
    box-shadow: 0 3px 10px rgba(15, 23, 42, 0.12);
}

/* Not read yet: a tinted background and an accent edge, so it stands out from the read ones. */
.notif-card--unread {
    background: rgba(var(--primary-rgb, 132, 90, 223), 0.09);
    border-inline-start-color: rgb(var(--primary-rgb, 132, 90, 223));
}

.notif-card--read {
    border-inline-start-color: rgba(var(--primary-rgb, 132, 90, 223), 0.25);
}

.notif-card--skeleton {
    align-items: center;
}

.notif-dot {
    position: absolute;
    inset-block-end: 0;
    inset-inline-end: 0;
    width: 11px;
    height: 11px;
    border-radius: 50%;
    border: 2px solid var(--custom-white, #fff);
}

.notif-dot--unread {
    background: #26bf94;
}

.notif-dot--read {
    background: #8c9097;
}

.notif-date {
    padding: 0.15rem 0.5rem;
    border-radius: 0.25rem;
    background: rgba(140, 144, 151, 0.14);
    color: var(--text-muted, #8c9097);
    font-size: 0.7rem;
    font-weight: 600;
    white-space: nowrap;
}

.notif-loading {
    display: inline-flex;
    align-items: center;
    padding: 0.6rem 1.5rem;
    border-radius: 0.4rem;
    background: rgba(var(--primary-rgb, 132, 90, 223), 0.12);
    color: rgb(var(--primary-rgb, 132, 90, 223));
    font-weight: 500;
}
</style>
