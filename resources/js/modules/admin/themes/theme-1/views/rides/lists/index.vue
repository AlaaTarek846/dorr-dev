<template>
    <div>
        <WalletPageHeader :title="t('rides.lists.title')" :section="t('sidebar.rides')" />

        <div class="row g-3">
            <div v-for="list in ['cancel-reasons', 'rating-tags']" :key="list" class="col-xl-6">
                <div class="card custom-card h-100">
                    <div class="card-header d-flex align-items-center py-3">
                        <h6 class="mb-0">{{ t(`rides.lists.${list}`) }}</h6>
                        <button v-if="can('ride-lists.create')" type="button" class="btn btn-primary btn-sm ms-auto" @click="open(list, null)"><i class="ri-add-line me-1"></i>{{ t('rides.lists.add') }}</button>
                    </div>
                    <div class="card-body p-0">
                        <table class="table mb-0 align-middle fs-13">
                            <tbody>
                                <tr v-if="!items[list].length"><td class="text-center text-muted py-4">{{ t('wallet.common.empty') }}</td></tr>
                                <tr v-for="item in items[list]" :key="item.id" :class="{ 'opacity-50': !item.status }">
                                    <td class="ps-3">
                                        <div class="fw-semibold">{{ item.label }}</div>
                                        <div class="fs-11 text-muted">
                                            <template v-if="list === 'cancel-reasons'">{{ t(`rides.lists.actor.${item.actor}`) }}<span v-if="item.is_safety"> · {{ t('rides.lists.safety') }}</span></template>
                                            <template v-else>{{ t(`rides.lists.target.${item.target}`) }} · <span :class="item.sentiment === 'positive' ? 'text-success' : 'text-danger'">{{ t(`rides.lists.sentiment.${item.sentiment}`) }}</span></template>
                                        </div>
                                    </td>
                                    <td class="text-end pe-3">
                                        <button v-if="can('ride-lists.update')" type="button" class="btn btn-sm btn-light btn-icon me-1" @click="open(list, item)"><i class="ri-edit-line"></i></button>
                                        <button v-if="can('ride-lists.delete')" type="button" class="btn btn-sm btn-danger-light btn-icon" @click="remove(list, item)"><i class="ri-delete-bin-line"></i></button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <WalletModal :show="!!form" :title="form ? t(`rides.lists.${form.list}`) : ''" size="md" @close="form = null">
            <template v-if="form">
                <label class="form-label">{{ t('rides.document_types.name_ar') }}</label>
                <input v-model="form.label.ar" class="form-control mb-2" dir="rtl">
                <label class="form-label">{{ t('rides.document_types.name_en') }}</label>
                <input v-model="form.label.en" class="form-control mb-3" dir="ltr">
                <template v-if="form.list === 'cancel-reasons'">
                    <label class="form-label">{{ t('rides.lists.who') }}</label>
                    <select v-model="form.actor" class="form-select mb-3">
                        <option v-for="a in ['rider', 'driver', 'both']" :key="a" :value="a">{{ t(`rides.lists.actor.${a}`) }}</option>
                    </select>
                    <div class="form-check form-switch"><input id="safety" v-model="form.is_safety" class="form-check-input" type="checkbox"><label class="form-check-label" for="safety">{{ t('rides.lists.safety') }}</label></div>
                    <div class="form-check form-switch"><input id="note" v-model="form.requires_note" class="form-check-input" type="checkbox"><label class="form-check-label" for="note">{{ t('rides.lists.requires_note') }}</label></div>
                </template>
                <template v-else>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label">{{ t('rides.lists.about') }}</label>
                            <select v-model="form.target" class="form-select"><option v-for="x in ['driver', 'rider']" :key="x" :value="x">{{ t(`rides.lists.target.${x}`) }}</option></select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">{{ t('rides.lists.kind') }}</label>
                            <select v-model="form.sentiment" class="form-select"><option v-for="x in ['positive', 'negative']" :key="x" :value="x">{{ t(`rides.lists.sentiment.${x}`) }}</option></select>
                        </div>
                    </div>
                </template>
                <div class="form-check form-switch mt-2"><input id="active" v-model="form.status" class="form-check-input" type="checkbox"><label class="form-check-label" for="active">{{ t('wallet.common.active') }}</label></div>
            </template>
            <template #footer>
                <button type="button" class="btn btn-light" @click="form = null">{{ t('cancel') }}</button>
                <button type="button" class="btn btn-primary" @click="save">{{ t('save') }}</button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// What each side picks when cancelling, and the chips under the stars — both languages.
const { t } = useI18n();
const { can } = usePermission();
const { showError } = useToast();
const items = reactive({ 'cancel-reasons': [], 'rating-tags': [] });
const form = ref(null);

function open(list, item) {
    form.value = {
        list, id: item?.id ?? null, label: { ar: item?.labels?.ar ?? '', en: item?.labels?.en ?? '' }, status: item?.status ?? true, sort_order: item?.sort_order ?? 0,
        actor: item?.actor ?? 'rider', is_safety: item?.is_safety ?? false, requires_note: item?.requires_note ?? false,
        target: item?.target ?? 'driver', sentiment: item?.sentiment ?? 'positive',
    };
}

async function save() {
    const { list, id, ...body } = form.value;
    try {
        if (id) await adminAxios.put(`/api/admin/v1/ride-lists/${list}/${id}`, body);
        else await adminAxios.post(`/api/admin/v1/ride-lists/${list}`, body);
        form.value = null;
        load(list);
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function remove(list, item) {
    if (!window.confirm(t('rides.document_types.confirm_delete'))) return;
    try {
        await adminAxios.delete(`/api/admin/v1/ride-lists/${list}/${item.id}`);
        load(list);
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function load(list) {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/ride-lists/${list}`);
        items[list] = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

onMounted(() => { load('cancel-reasons'); load('rating-tags'); });
</script>
