<template>
    <WalletModal :show="show" :title="t('security.devices.title', { name: userName || `#${userId}` })" size="lg" @close="emit('close')">
        <div v-if="loading" class="text-center py-5">
            <span class="spinner-border text-primary"></span>
        </div>

        <template v-else>
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h6 class="mb-0 fw-semibold">
                    {{ t('security.devices.active') }}
                    <span class="badge bg-primary-transparent ms-1">{{ active.length }}</span>
                </h6>
                <button
                    v-if="active.length"
                    type="button"
                    class="btn btn-sm btn-danger-light"
                    :disabled="busy !== null"
                    @click="signOutAll"
                >
                    <span v-if="busy === 'all'" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="ri-logout-box-r-line me-1"></i>{{ t('security.devices.sign_out_all') }}
                </button>
            </div>

            <p v-if="!active.length" class="text-muted text-center py-4 mb-3">{{ t('security.devices.none') }}</p>

            <ul v-else class="list-group mb-4">
                <li v-for="device in active" :key="device.id" class="list-group-item d-flex align-items-center gap-3">
                    <span class="avatar avatar-md avatar-rounded bg-primary-transparent flex-shrink-0">
                        <i :class="platformIcon(device.platform)" class="fs-5 text-primary"></i>
                    </span>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold text-truncate">{{ device.device_name }}</div>
                        <div class="text-muted fs-12">
                            {{ device.platform_label }}<template v-if="device.app_version"> · v{{ device.app_version }}</template>
                            · <span dir="ltr">{{ device.ip || '—' }}</span>
                            <template v-if="device.country_code"> · {{ device.country_code }}</template>
                        </div>
                        <div class="text-muted fs-11">
                            {{ t('security.devices.last_active') }}: {{ formatCatalogDate(device.last_active_at, locale) }}
                            · {{ t('security.devices.signed_in') }}: {{ formatCatalogDate(device.signed_in_at, locale) }}
                        </div>
                    </div>
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-danger flex-shrink-0"
                        :disabled="busy !== null"
                        @click="signOut(device)"
                    >
                        <span v-if="busy === device.id" class="spinner-border spinner-border-sm"></span>
                        <template v-else>{{ t('security.devices.sign_out') }}</template>
                    </button>
                </li>
            </ul>

            <template v-if="recent.length">
                <h6 class="fw-semibold mb-2">{{ t('security.devices.recent') }}</h6>
                <ul class="list-group list-group-flush">
                    <li v-for="device in recent" :key="device.id" class="list-group-item d-flex align-items-center gap-3 px-0">
                        <i :class="platformIcon(device.platform)" class="fs-5 text-muted"></i>
                        <div class="flex-grow-1 min-w-0">
                            <div class="text-truncate">{{ device.device_name }}</div>
                            <div class="text-muted fs-11">
                                {{ formatCatalogDate(device.revoked_at, locale) }} · {{ reasonLabel(device.revoked_reason) }}
                            </div>
                        </div>
                    </li>
                </ul>
            </template>

            <div v-if="error" class="text-danger mt-2 fs-13">{{ error }}</div>
        </template>

        <template #footer>
            <router-link
                v-if="canViewLog"
                :to="{ name: 'admin.security-events.index', query: { user: userId } }"
                class="btn btn-light me-auto"
                @click="emit('close')"
            >
                <i class="ri-shield-keyhole-line me-1"></i>{{ t('security.devices.open_log') }}
            </router-link>
            <button type="button" class="btn btn-light" @click="emit('close')">{{ t('close') }}</button>
        </template>
    </WalletModal>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import WalletModal from '../../../../../../components/wallet/WalletModal.vue';
import { usePermission } from '../../../../../../composables/usePermission';
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';
import { formatCatalogDate } from '../../../../../../utils/catalog';

const props = defineProps({
    show: { type: Boolean, default: false },
    userId: { type: [Number, String], default: null },
    userName: { type: String, default: '' },
});

const emit = defineEmits(['close']);

const { t, locale } = useI18n();
const { showSuccess } = useToast();
const { can } = usePermission();
const canViewLog = computed(() => can('security-events.view'));

const loading = ref(false);
const busy = ref(null);
const error = ref('');
const active = ref([]);
const recent = ref([]);

const base = computed(() => `/api/admin/v1/users/${props.userId}/sessions`);

function fill(data) {
    active.value = data?.active ?? [];
    recent.value = data?.recent ?? [];
}

async function load() {
    loading.value = true;
    error.value = '';

    try {
        const { data } = await adminAxios.get(base.value);
        fill(data.data);
    } catch (e) {
        error.value = extractApiErrorMessage(e);
    } finally {
        loading.value = false;
    }
}

async function signOut(device) {
    if (! window.confirm(t('security.devices.confirm_one', { device: device.device_name }))) {
        return;
    }

    busy.value = device.id;
    error.value = '';

    try {
        const { data } = await adminAxios.delete(`${base.value}/${device.id}`);
        fill(data.data);
        showSuccess(t('security.devices.signed_out'));
    } catch (e) {
        error.value = extractApiErrorMessage(e);
    } finally {
        busy.value = null;
    }
}

async function signOutAll() {
    if (! window.confirm(t('security.devices.confirm_all'))) {
        return;
    }

    busy.value = 'all';
    error.value = '';

    try {
        const { data } = await adminAxios.post(`${base.value}/logout-all`);
        fill(data.data);
        showSuccess(t('security.devices.signed_out_all'));
    } catch (e) {
        error.value = extractApiErrorMessage(e);
    } finally {
        busy.value = null;
    }
}

function platformIcon(platform) {
    if (platform === 'android') {
        return 'ri-android-line';
    }

    if (platform === 'ios') {
        return 'ri-apple-line';
    }

    if (platform === 'web') {
        return 'ri-global-line';
    }

    return 'ri-device-line';
}

function reasonLabel(reason) {
    return reason ? t(`security.reasons.${reason}`) : '—';
}

watch(() => props.show, (open) => {
    if (open && props.userId) {
        fill(null);
        load();
    }
});
</script>
