<template>
    <WalletModal :show="show" :title="record?.label || t('security.log.title')" size="lg" @close="emit('close')">
        <div v-if="record" class="d-flex flex-column gap-3">
            <WalletDetailHero
                :label="t('security.log.event')"
                :value="record.label"
                :subtitle="formatCatalogDate(record.created_at, locale)"
                :icon="record.warning ? 'ri-alert-line' : 'ri-shield-check-line'"
            >
                <span class="badge fs-12" :class="record.warning ? 'bg-danger-transparent' : 'bg-success-transparent'">
                    {{ record.warning ? t('security.log.warning') : t('security.log.normal') }}
                </span>
            </WalletDetailHero>

            <div v-if="record.description" class="alert alert-light border mb-0 fs-13">{{ record.description }}</div>

            <div class="row g-3">
                <div class="col-6">
                    <WalletInfoTile icon="ri-user-line" :label="t('security.log.customer')">
                        <template v-if="record.user">
                            <button type="button" class="btn btn-link p-0 fw-semibold" @click="filterUser">
                                {{ record.user.name || `#${record.user.id}` }}
                            </button>
                            <span class="d-block text-muted fs-12" dir="ltr">{{ record.user.phone }}</span>
                        </template>
                        <span v-else class="text-muted fw-normal">—</span>
                    </WalletInfoTile>
                </div>
                <div class="col-6">
                    <WalletInfoTile icon="ri-smartphone-line" :label="t('security.log.device')">
                        {{ record.device_name || '—' }}
                        <span class="d-block text-muted fs-12">{{ record.platform_label }}</span>
                    </WalletInfoTile>
                </div>
                <div class="col-6">
                    <WalletInfoTile icon="ri-global-line" :label="t('security.log.ip')">
                        <span dir="ltr">{{ record.ip || '—' }}</span>
                    </WalletInfoTile>
                </div>
                <div class="col-6">
                    <WalletInfoTile icon="ri-map-pin-line" :label="t('security.log.country')">
                        {{ record.country_code || '—' }}
                    </WalletInfoTile>
                </div>
            </div>

            <WalletSection v-if="record.session" :title="t('security.log.session')" icon="ri-device-line">
                <div class="row g-3">
                    <div class="col-6">
                        <WalletInfoTile icon="ri-login-box-line" :label="t('security.devices.signed_in')">
                            {{ formatCatalogDate(record.session.signed_in_at, locale) }}
                        </WalletInfoTile>
                    </div>
                    <div class="col-6">
                        <WalletInfoTile icon="ri-time-line" :label="t('security.devices.last_active')">
                            {{ formatCatalogDate(record.session.last_active_at, locale) }}
                        </WalletInfoTile>
                    </div>
                    <div class="col-6">
                        <WalletInfoTile icon="ri-information-line" :label="t('security.log.app_version')">
                            {{ record.session.app_version || '—' }}
                        </WalletInfoTile>
                    </div>
                    <div class="col-6">
                        <WalletInfoTile icon="ri-logout-box-r-line" :label="t('security.log.session_state')">
                            <template v-if="record.session.revoked_at">
                                {{ t(`security.reasons.${record.session.revoked_reason}`) }}
                                <span class="d-block text-muted fs-12">{{ formatCatalogDate(record.session.revoked_at, locale) }}</span>
                            </template>
                            <span v-else class="text-success">{{ t('security.log.still_signed_in') }}</span>
                        </WalletInfoTile>
                    </div>
                    <div v-if="record.session.user_agent" class="col-12">
                        <WalletInfoTile icon="ri-code-line" :label="t('security.log.user_agent')">
                            <span class="fs-12 fw-normal text-break" dir="ltr">{{ record.session.user_agent }}</span>
                        </WalletInfoTile>
                    </div>
                </div>
            </WalletSection>
        </div>

        <template #footer>
            <button type="button" class="btn btn-light" @click="emit('close')">{{ t('close') }}</button>
        </template>
    </WalletModal>
</template>

<script setup>
import { useI18n } from 'vue-i18n';
import WalletDetailHero from '../../../../../../components/wallet/WalletDetailHero.vue';
import WalletInfoTile from '../../../../../../components/wallet/WalletInfoTile.vue';
import WalletModal from '../../../../../../components/wallet/WalletModal.vue';
import WalletSection from '../../../../../../components/wallet/WalletSection.vue';
import { formatCatalogDate } from '../../../../../../utils/catalog';

const props = defineProps({
    show: { type: Boolean, default: false },
    record: { type: Object, default: null },
});

const emit = defineEmits(['close', 'filter-user']);
const { t, locale } = useI18n();

function filterUser() {
    emit('filter-user', props.record.user.id);
    emit('close');
}
</script>
