<template>
    <div class="border border-warning-subtle rounded p-3 mt-3">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <h6 class="fs-13 fw-semibold mb-0 me-auto">{{ t('freelance.disputes.internal.title') }}</h6>
            <button v-if="!threads" type="button" class="btn btn-sm btn-outline-warning" :disabled="loading" @click="load">
                <span v-if="loading" class="spinner-border spinner-border-sm me-1"></span>{{ t('freelance.disputes.internal.open') }}
            </button>
            <button v-else type="button" class="btn btn-sm btn-outline-secondary" @click="threads = null">{{ t('freelance.disputes.workroom.hide') }}</button>
        </div>
        <div v-if="!threads" class="text-muted fs-11 mt-1">{{ t('freelance.disputes.internal.warn') }}</div>

        <div v-else class="mt-2 dispute-internal">
            <div v-if="!threads.length" class="text-muted fs-12">{{ t('freelance.disputes.internal.empty') }}</div>
            <div v-for="th in threads" :key="th.uuid" class="mb-3">
                <div class="fw-semibold fs-12">{{ th.team.name }} <span class="text-muted fw-normal">· {{ t('freelance.disputes.internal.messages', { n: th.messages_count }) }}</span></div>
                <div v-for="m in th.messages" :key="m.id" class="fs-12 py-1 border-bottom">
                    <span class="fw-semibold">{{ m.from.name }}</span>
                    <span v-if="m.from.role" class="text-muted fs-11"> ({{ m.from.role }})</span>:
                    <span style="white-space: pre-line">{{ m.body }}</span>
                    <span v-if="m.deleted" class="badge bg-secondary-transparent ms-1">{{ t('freelance.disputes.internal.deleted') }}</span>
                    <span class="text-muted fs-11"> · {{ formatCatalogDate(m.at, locale) }}</span>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { formatCatalogDate } from '../../../../../../../utils/catalog';

// FS-150d/f: a team's internal chat about the disputed project, read only, while the dispute is being decided. Every opening is on the
// audit log and the team's Owners are told the first time; this is why it waits for the click.
const props = defineProps({ dispute: { type: String, required: true } });
const { t, locale } = useI18n();
const { showError } = useToast();

const threads = ref(null);
const loading = ref(false);

watch(() => props.dispute, () => (threads.value = null));

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/freelance-disputes/${props.dispute}/internal-chat`);
        threads.value = data.data.threads ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}
</script>

<style scoped>
.dispute-internal {
    max-height: 420px;
    overflow-y: auto;
}
</style>
