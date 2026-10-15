<template>
    <WalletModal :show="show" :title="driver ? t('rides.drivers.edit') : t('rides.drivers.add')" size="lg" @close="$emit('close')">
        <form id="driver-form" @submit.prevent="save">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">{{ t('rides.drivers.name') }} <span class="text-danger">*</span></label>
                    <input v-model="form.name" type="text" maxlength="150" class="form-control" :class="{ 'is-invalid': errors.name }">
                    <div class="invalid-feedback">{{ errors.name }}</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ t('rides.drivers.residency') }} <span class="text-danger">*</span></label>
                    <div class="btn-group w-100" role="group">
                        <button v-for="r in options.residencies" :key="r" type="button" class="btn btn-sm" :class="form.residency === r ? 'btn-primary' : 'btn-outline-primary'" @click="form.residency = r">
                            <i :class="r === 'citizen' ? 'ri-flag-line' : 'ri-passport-line'" class="me-1"></i>{{ t(`rides.residency.${r}`) }}
                        </button>
                    </div>
                    <small class="text-muted">{{ t('rides.drivers.residency_hint') }}</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ t('rides.drivers.country') }} <span class="text-danger">*</span></label>
                    <select v-model="form.country_id" class="form-select" @change="form.city_id = null">
                        <option v-for="c in options.countries" :key="c.id" :value="c.id">{{ c.name }} ({{ c.dial_code }})</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ t('rides.drivers.phone') }} <span class="text-danger">*</span></label>
                    <div class="input-group" dir="ltr">
                        <span class="input-group-text">{{ country?.dial_code }}</span>
                        <input v-model="form.phone" type="tel" inputmode="numeric" class="form-control" :class="{ 'is-invalid': errors.phone }" :placeholder="country?.phone_length ? '•'.repeat(country.phone_length) : ''">
                        <div class="invalid-feedback">{{ errors.phone }}</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ t('rides.drivers.city') }}</label>
                    <select v-model="form.city_id" class="form-select">
                        <option :value="null">—</option>
                        <option v-for="c in cities" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ t('rides.drivers.gender') }}</label>
                    <select v-model="form.gender" class="form-select">
                        <option :value="null">—</option>
                        <option v-for="g in options.genders" :key="g" :value="g">{{ t(`rides.gender.${g}`) }}</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ t('rides.drivers.id_type') }}</label>
                    <select v-model="form.id_type" class="form-select">
                        <option :value="null">—</option>
                        <option v-for="i in options.id_types" :key="i" :value="i">{{ t(`rides.id_type.${i}`) }}</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ t('rides.drivers.national_id') }}</label>
                    <input v-model="form.national_id" type="text" maxlength="20" dir="ltr" class="form-control" :class="{ 'is-invalid': errors.national_id }">
                    <div class="invalid-feedback">{{ errors.national_id }}</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ t('rides.drivers.date_of_birth') }}</label>
                    <input v-model="form.date_of_birth" type="date" class="form-control" :class="{ 'is-invalid': errors.date_of_birth }">
                    <div class="invalid-feedback">{{ errors.date_of_birth }}</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ t('rides.drivers.nationality') }}</label>
                    <select v-model="form.nationality_country_id" class="form-select">
                        <option :value="null">—</option>
                        <option v-for="c in options.countries" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ t('rides.drivers.email') }}</label>
                    <input v-model="form.email" type="email" dir="ltr" class="form-control" :class="{ 'is-invalid': errors.email }">
                    <div class="invalid-feedback">{{ errors.email }}</div>
                </div>
            </div>
            <div v-if="errors.general" class="text-danger mt-3 fs-13">{{ errors.general }}</div>
        </form>
        <template #footer>
            <button type="button" class="btn btn-light" :disabled="saving" @click="$emit('close')">{{ t('cancel') }}</button>
            <button type="submit" form="driver-form" class="btn btn-primary" :disabled="saving">
                <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}
            </button>
        </template>
    </WalletModal>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';

// Register a driver from the dashboard, or edit one — citizen or resident, in any country.
const props = defineProps({
    show: { type: Boolean, default: false },
    options: { type: Object, required: true },
    driver: { type: Object, default: null },
});
const emit = defineEmits(['close', 'saved']);
const { t } = useI18n();
const { showSuccess } = useToast();

const saving = ref(false);
const errors = reactive({});
const blank = () => ({
    name: '', phone: '', country_id: props.options.countries[0]?.id ?? null, city_id: null, residency: 'citizen',
    gender: null, id_type: null, national_id: '', date_of_birth: '', nationality_country_id: null, email: '',
});
const form = reactive(blank());
const country = computed(() => props.options.countries.find((c) => c.id === form.country_id));
const cities = computed(() => (props.options.cities ?? []).filter((c) => c.country_id === form.country_id));

watch(() => props.show, (open) => {
    if (!open) {
        return;
    }
    Object.keys(errors).forEach((key) => delete errors[key]);
    const d = props.driver;
    // The stored number is "+<dial><local>": the form shows the local part next to the dial code.
    const dial = (props.options.countries.find((c) => c.id === d?.country_id)?.dial_code ?? '').replace('+', '');
    const local = (d?.phone ?? '').replace(/^\+/, '');
    Object.assign(form, blank(), d ? {
        name: d.name, country_id: d.country_id, city_id: d.city_id, residency: d.residency, gender: d.gender, id_type: d.id_type,
        national_id: d.national_id ?? '', date_of_birth: d.date_of_birth ?? '', nationality_country_id: d.nationality_country_id, email: d.email ?? '',
        phone: dial && local.startsWith(dial) ? local.slice(dial.length) : local,
    } : {});
});

async function save() {
    Object.keys(errors).forEach((key) => delete errors[key]);
    saving.value = true;
    const payload = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]));
    try {
        const { data } = props.driver
            ? await adminAxios.put(`/api/admin/v1/drivers/${props.driver.id}`, payload)
            : await adminAxios.post('/api/admin/v1/drivers', payload);
        showSuccess(t('rides.drivers.saved'));
        emit('saved', data.data);
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};
        Object.entries(bag).forEach(([key, messages]) => { errors[key] ??= messages[0]; });
        errors.general = Object.keys(bag).length ? '' : extractApiErrorMessage(error);
    } finally {
        saving.value = false;
    }
}
</script>
