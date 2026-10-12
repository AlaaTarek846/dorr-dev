<template>
    <div class="card custom-card">
        <div class="card-header"><div class="card-title">{{ t('ai_site_requests.conversation') }}</div></div>
        <div ref="box" class="card-body" style="max-height: 380px; overflow-y: auto;">
            <p v-if="! messages.length" class="text-muted text-center my-3">{{ t('ai_site_requests.no_messages') }}</p>
            <div v-for="m in messages" :key="m.id" class="mb-3" :class="m.sender === 'admin' ? 'text-end' : ''">
                <div v-if="m.sender === 'system'" class="text-center">
                    <span class="badge bg-light text-muted fw-normal text-wrap">{{ m.body }}</span>
                </div>
                <template v-else>
                    <div class="d-inline-block p-2 px-3 rounded-3 text-start" :class="m.sender === 'admin' ? 'bg-primary text-white' : 'bg-light'" style="max-width: 85%; white-space: pre-wrap;">{{ m.body }}</div>
                    <div class="fs-11 text-muted mt-1">{{ m.sender === 'admin' ? t('ai_site_requests.you') : t('ai_site_requests.customer') }} · {{ new Date(m.created_at).toLocaleString() }}</div>
                </template>
            </div>
        </div>
        <div class="card-footer">
            <div class="input-group">
                <textarea v-model="draft" rows="2" class="form-control" :placeholder="t('ai_site_requests.write_message')" @keydown.ctrl.enter="send"></textarea>
                <button type="button" class="btn btn-primary" :disabled="sending || ! draft.trim()" @click="send"><i class="ri-send-plane-2-line"></i></button>
            </div>
            <p class="text-muted fs-11 mt-1 mb-0">{{ t('ai_site_requests.message_hint') }}</p>
        </div>
    </div>
</template>

<script setup>
import { nextTick, onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';

const props = defineProps({ requestId: { type: Number, required: true } });

const { t } = useI18n();
const { showError } = useToast();
const messages = ref([]);
const draft = ref('');
const sending = ref(false);
const box = ref(null);
let timer = null;

async function load() {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/ai-site-requests/${props.requestId}/messages`);
        const grew = (data.data ?? []).length !== messages.value.length;
        messages.value = data.data ?? [];

        if (grew) {
            await nextTick();
            box.value?.scrollTo({ top: box.value.scrollHeight });
        }
    } catch {
        // polling failures are silent; the next tick retries
    }
}

async function send() {
    if (! draft.value.trim()) {
        return;
    }

    sending.value = true;

    try {
        const { data } = await adminAxios.post(`/api/admin/v1/ai-site-requests/${props.requestId}/messages`, { body: draft.value });
        messages.value = data.data ?? [];
        draft.value = '';
        await nextTick();
        box.value?.scrollTo({ top: box.value.scrollHeight });
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        sending.value = false;
    }
}

onMounted(() => {
    load();
    timer = setInterval(load, 15000);
});
onUnmounted(() => clearInterval(timer));

defineExpose({ reload: load });
</script>
