<template>
    <MkModal :show="show" :title="t('marketing.checkout.title')" :busy="paying" @close="$emit('close')">
        <div v-if="loading" class="text-center py-4">
            <span class="spinner-border"></span>
        </div>
        <div v-else-if="loadError" class="text-center py-3">
            <p class="text-danger mb-3">{{ loadError }}</p>
            <button type="button" class="btn btn-light" @click="start">{{ t('marketing.retry') }}</button>
        </div>
        <template v-else-if="checkout">
            <div class="fw-semibold">{{ checkout.title }}</div>
            <div v-if="checkout.subtitle" class="text-muted fs-13">{{ checkout.subtitle }}</div>

            <dl class="mk-checkout-lines mt-3 mb-0">
                <div>
                    <dt>{{ t('marketing.checkout.amount') }}</dt>
                    <dd class="mk-ltr">{{ mkMoney(checkout.amount_minor, checkout.currency_code) }}</dd>
                </div>
                <div v-if="checkout.discount_minor > 0">
                    <dt>{{ t('marketing.checkout.discount') }}</dt>
                    <dd class="mk-ltr text-success">− {{ mkMoney(checkout.discount_minor, checkout.currency_code) }}</dd>
                </div>
                <div class="fw-semibold">
                    <dt>{{ t('marketing.checkout.payable') }}</dt>
                    <dd class="mk-ltr fs-16 text-primary">{{ mkMoney(checkout.payable_minor, checkout.currency_code) }}</dd>
                </div>
                <div>
                    <dt>{{ t('marketing.checkout.wallet_balance') }}</dt>
                    <dd class="mk-ltr">{{ mkMoney(checkout.wallet?.available_minor, checkout.currency_code) }}</dd>
                </div>
            </dl>

            <div v-if="checkout.coupons_allowed && checkout.status === 'pending'" class="mt-3">
                <div v-if="checkout.coupon" class="d-flex align-items-center gap-2 fs-13">
                    <span class="badge bg-success-transparent">{{ checkout.coupon.code }}</span>
                    <button type="button" class="btn btn-link btn-sm p-0 text-danger" :disabled="couponBusy" @click="removeCoupon">
                        {{ t('marketing.checkout.remove_coupon') }}
                    </button>
                </div>
                <div v-else class="input-group input-group-sm">
                    <input v-model="couponCode" class="form-control" maxlength="40" :placeholder="t('marketing.checkout.coupon')">
                    <button type="button" class="btn btn-light" :disabled="couponBusy || !couponCode.trim()" @click="applyCoupon">
                        <span v-if="couponBusy" class="spinner-border spinner-border-sm"></span>
                        <span v-else>{{ t('marketing.checkout.apply') }}</span>
                    </button>
                </div>
            </div>

            <div v-if="checkout.status === 'expired'" class="alert alert-warning mt-3 mb-0 fs-13">
                {{ t('marketing.checkout.expired') }}
            </div>
            <template v-else-if="checkout.wallet?.enough">
                <label class="form-label mt-3" for="mk-pin">{{ t('marketing.checkout.pin') }}</label>
                <input
                    id="mk-pin"
                    v-model="pin"
                    type="password"
                    inputmode="numeric"
                    autocomplete="off"
                    maxlength="4"
                    class="form-control mk-ltr text-center fs-18 mk-pin"
                    @input="pin = pin.replace(/\D/g, '').slice(0, 4)"
                    @keyup.enter="pay"
                >
                <div class="form-text">{{ t('marketing.checkout.pin_hint') }}</div>
            </template>
            <div v-else class="alert alert-warning mt-3 mb-0 fs-13">
                {{ t('marketing.checkout.shortfall', { amount: mkMoney(checkout.wallet?.shortfall_minor, checkout.currency_code) }) }}
            </div>
            <div v-if="payError" class="text-danger fs-13 mt-2">{{ payError }}</div>
        </template>

        <template #footer>
            <button type="button" class="btn btn-light" :disabled="paying" @click="$emit('close')">{{ t('marketing.cancel') }}</button>
            <button
                v-if="checkout && checkout.wallet?.enough && checkout.status === 'pending'"
                type="button"
                class="btn btn-primary"
                :disabled="paying || pin.length !== 4"
                @click="pay"
            >
                <span v-if="paying" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.checkout.pay_wallet') }}
            </button>
            <button v-else-if="checkout && checkout.status === 'expired'" type="button" class="btn btn-primary" @click="start">
                {{ t('marketing.retry') }}
            </button>
        </template>
    </MkModal>
</template>

<script setup>
import { ref, watch } from 'vue';
import { mkMoney, useMk } from '../marketingContext';
import MkModal from './MkModal.vue';

/**
 * Buys a package through the shared wallet checkout (purpose `marketing_package`, reference `{package: code}`): the server
 * prices it and the wallet pays it with the wallet PIN (X-Wallet-Pin). Card payments stay in the mobile apps.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    purpose: { type: String, required: true },
    reference: { type: Object, default: null },
});
const emit = defineEmits(['close', 'paid']);

const { ctx, http, t } = useMk();
const checkout = ref(null);
const loading = ref(false);
const loadError = ref('');
const paying = ref(false);
const payError = ref('');
const pin = ref('');
const couponCode = ref('');
const couponBusy = ref(false);

const base = () => `${ctx.walletBase}/checkouts`;
const message = (error) => error?.response?.data?.message || t('marketing.error_generic');

async function start() {
    loading.value = true;
    loadError.value = '';
    payError.value = '';
    pin.value = '';
    couponCode.value = '';
    checkout.value = null;
    try {
        const { data } = await http.post(base(), { purpose: props.purpose, reference: props.reference ?? {} });
        checkout.value = data.data;
    } catch (error) {
        loadError.value = message(error);
    } finally {
        loading.value = false;
    }
}

async function applyCoupon() {
    couponBusy.value = true;
    payError.value = '';
    try {
        const { data } = await http.post(`${base()}/${checkout.value.id}/coupon`, { code: couponCode.value.trim() });
        checkout.value = data.data;
        couponCode.value = '';
    } catch (error) {
        payError.value = message(error);
    } finally {
        couponBusy.value = false;
    }
}

async function removeCoupon() {
    couponBusy.value = true;
    payError.value = '';
    try {
        const { data } = await http.delete(`${base()}/${checkout.value.id}/coupon`);
        checkout.value = data.data;
    } catch (error) {
        payError.value = message(error);
    } finally {
        couponBusy.value = false;
    }
}

async function pay() {
    if (pin.value.length !== 4 || paying.value) {
        return;
    }
    paying.value = true;
    payError.value = '';
    try {
        const { data } = await http.post(`${base()}/${checkout.value.id}/pay`, { method: 'wallet' }, {
            headers: { 'X-Wallet-Pin': pin.value },
        });
        checkout.value = data.data;
        if (data.data?.status === 'paid') {
            emit('paid', data.data);
        } else if (data.data?.failure_reason) {
            payError.value = data.data.failure_reason;
        }
    } catch (error) {
        payError.value = message(error);
        pin.value = '';
    } finally {
        paying.value = false;
    }
}

watch(() => props.show, (open) => {
    if (open) {
        start();
    }
});
</script>

<style>
.mk-checkout-lines > div {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.45rem 0;
    border-bottom: 1px dashed var(--default-border, #e9edf6);
}

.mk-checkout-lines dt {
    font-weight: inherit;
    color: var(--text-muted, #8c9097);
}

.mk-checkout-lines dd {
    margin: 0;
}

.mk-pin {
    letter-spacing: 0.6em;
    max-width: 10rem;
}
</style>
