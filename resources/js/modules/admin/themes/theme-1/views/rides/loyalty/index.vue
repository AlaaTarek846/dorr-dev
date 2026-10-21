<template>
    <div>
        <WalletPageHeader :title="t('rides.loyalty.title')" :section="t('sidebar.rides')" />
        <p class="text-muted fs-13 mb-3">{{ t('rides.loyalty.hint') }}</p>

        <ul class="nav nav-tabs mb-3">
            <li v-for="tab in tabs" :key="tab" class="nav-item">
                <a href="#" class="nav-link" :class="{ active: current === tab }" @click.prevent="current = tab">{{ t(`rides.loyalty.tabs.${tab}`) }}</a>
            </li>
        </ul>

        <!-- One rider's points: look up by phone, add or take with a reason. -->
        <div v-if="current === 'members'" class="card custom-card">
            <div class="card-body">
                <div class="d-flex gap-2 mb-3" style="max-width: 460px">
                    <input v-model="phone" class="form-control" :placeholder="t('rides.loyalty.phone')" @keyup.enter="findMember">
                    <button type="button" class="btn btn-primary" @click="findMember">{{ t('rides.loyalty.find') }}</button>
                </div>
                <div v-if="member === false" class="text-muted">{{ t('rides.loyalty.not_found') }}</div>
                <template v-else-if="member">
                    <div class="d-flex flex-wrap gap-4 mb-3">
                        <div><div class="fs-12 text-muted">{{ t('rides.loyalty.rider') }}</div><div class="fw-semibold">{{ member.user.name }} · {{ member.user.phone }}</div></div>
                        <div><div class="fs-12 text-muted">{{ t('rides.loyalty.balance') }}</div><div class="fw-bold fs-5">{{ member.balance }}</div></div>
                        <div><div class="fs-12 text-muted">{{ t('rides.loyalty.lifetime') }}</div><div class="fw-semibold">{{ member.lifetime }}</div></div>
                        <div><div class="fs-12 text-muted">{{ t('rides.loyalty.tier') }}</div><div class="fw-semibold">{{ member.tier || '—' }}</div></div>
                    </div>
                    <div v-if="can('ride-loyalty.update')" class="row g-2 align-items-end mb-3" style="max-width: 640px">
                        <div class="col-3"><label class="form-label">{{ t('rides.loyalty.points') }}</label><input v-model.number="adjust.points" type="number" class="form-control"></div>
                        <div class="col-6"><label class="form-label">{{ t('rides.loyalty.reason') }}</label><input v-model="adjust.reason" class="form-control"></div>
                        <div class="col-3"><button type="button" class="btn btn-primary w-100" @click="saveAdjust">{{ t('rides.loyalty.apply') }}</button></div>
                    </div>
                    <table class="table table-sm fs-13 mb-0">
                        <tbody>
                            <tr v-for="e in member.entries" :key="e.id">
                                <td>{{ e.title }}</td>
                                <td class="text-end fw-semibold" :class="e.points > 0 ? 'text-success' : 'text-danger'">{{ e.points > 0 ? '+' : '' }}{{ e.points }}</td>
                                <td class="text-end text-muted">{{ e.balance_after }}</td>
                                <td class="text-end text-muted">{{ (e.created_at || '').slice(0, 16).replace('T', ' ') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </div>
        </div>

        <div v-else class="card custom-card">
            <div class="card-header d-flex align-items-center py-3">
                <h6 class="mb-0">{{ t(`rides.loyalty.tabs.${current}`) }}</h6>
                <button v-if="can('ride-loyalty.create')" type="button" class="btn btn-primary btn-sm ms-auto" @click="open(null)"><i class="ri-add-line me-1"></i>{{ t('rides.lists.add') }}</button>
            </div>
            <div class="card-body p-0">
                <table class="table mb-0 align-middle fs-13">
                    <tbody>
                        <tr v-if="!items[current].length"><td class="text-center text-muted py-4">{{ t('wallet.common.empty') }}</td></tr>
                        <tr v-for="item in items[current]" :key="item.id" :class="{ 'opacity-50': !item.status }">
                            <td class="ps-3">
                                <div class="fw-semibold">
                                    <span v-if="current === 'tiers' && item.color" class="d-inline-block rounded-circle me-1" :style="{ width: '10px', height: '10px', background: item.color }"></span>
                                    {{ word(item.name || item.title) }}
                                </div>
                                <div class="fs-11 text-muted">{{ summary(item) }}</div>
                            </td>
                            <td class="text-end pe-3">
                                <button v-if="can('ride-loyalty.update')" type="button" class="btn btn-sm btn-light btn-icon me-1" @click="open(item)"><i class="ri-edit-line"></i></button>
                                <button v-if="can('ride-loyalty.delete')" type="button" class="btn btn-sm btn-danger-light btn-icon" @click="remove(item)"><i class="ri-delete-bin-line"></i></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <WalletModal :show="!!form" :title="form ? t(`rides.loyalty.tabs.${current}`) : ''" size="lg" @close="form = null">
            <template v-if="form">
                <RideLangFields v-slot="{ locale, dir }" :required="[form.words]">
                    <label class="form-label">{{ t('rides.lang.name') }} *</label>
                    <input v-model="form.words[locale]" class="form-control mb-2" :dir="dir">
                    <template v-if="current !== 'tiers'">
                        <label class="form-label">{{ t('rides.loyalty.description') }}</label>
                        <textarea v-model="form.description[locale]" class="form-control mb-2" rows="2" :dir="dir"></textarea>
                    </template>
                    <template v-else>
                        <label class="form-label">{{ t('rides.loyalty.benefits') }}</label>
                        <textarea v-model="form.benefitsText[locale]" class="form-control mb-2" rows="3" :dir="dir" :placeholder="t('rides.loyalty.one_per_line')"></textarea>
                    </template>
                </RideLangFields>

                <div class="row g-2">
                    <template v-if="current === 'tiers'">
                        <div class="col-md-4"><label class="form-label">{{ t('rides.loyalty.min_points') }}</label><input v-model.number="form.min_points" type="number" min="0" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">{{ t('rides.loyalty.multiplier') }}</label><input v-model.number="form.multiplier" type="number" min="0" step="0.1" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">{{ t('rides.loyalty.color') }}</label><input v-model="form.color" type="color" class="form-control form-control-color w-100"></div>
                    </template>
                    <template v-if="current === 'badges' || current === 'challenges'">
                        <div class="col-md-4">
                            <label class="form-label">{{ t('rides.loyalty.rule') }}</label>
                            <select v-model="form.rule" class="form-select"><option v-for="r in options.rules?.[current] || []" :key="r" :value="r">{{ t(`rides.loyalty.rules.${r}`) }}</option></select>
                        </div>
                        <div v-if="form.rule === 'category_rides'" class="col-md-4"><label class="form-label">{{ t('rides.loyalty.category') }}</label><input v-model="form.category" class="form-control" placeholder="economy"></div>
                        <div class="col-md-4"><label class="form-label">{{ t(current === 'badges' ? 'rides.loyalty.threshold' : 'rides.loyalty.target') }}</label><input v-model.number="form.goal" type="number" min="1" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">{{ t('rides.loyalty.reward_points') }}</label><input v-model.number="form.reward_points" type="number" min="0" class="form-control"></div>
                        <div v-if="current === 'badges'" class="col-md-4">
                            <label class="form-label">{{ t('rides.loyalty.icon') }}</label>
                            <select v-model="form.icon" class="form-select"><option v-for="i in options.icons || []" :key="i" :value="i">{{ i }}</option></select>
                        </div>
                    </template>
                    <template v-if="current === 'challenges'">
                        <div class="col-md-4"><label class="form-label">{{ t('rides.loyalty.starts_at') }}</label><input v-model="form.starts_at" type="datetime-local" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">{{ t('rides.loyalty.ends_at') }}</label><input v-model="form.ends_at" type="datetime-local" class="form-control"></div>
                        <div class="col-md-4">
                            <label class="form-label">{{ t('rides.loyalty.country') }}</label>
                            <select v-model="form.country_id" class="form-select"><option :value="null">{{ t('rides.loyalty.all_countries') }}</option><option v-for="c in options.countries || []" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                        </div>
                    </template>
                    <template v-if="current === 'rewards'">
                        <div class="col-md-4"><label class="form-label">{{ t('rides.loyalty.cost_points') }}</label><input v-model.number="form.cost_points" type="number" min="1" class="form-control"></div>
                        <div class="col-md-4">
                            <label class="form-label">{{ t('rides.loyalty.kind') }}</label>
                            <select v-model="form.kind" class="form-select"><option value="wallet_credit">{{ t('rides.loyalty.kinds.wallet_credit') }}</option><option value="coupon">{{ t('rides.loyalty.kinds.coupon') }}</option></select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ t('rides.loyalty.country') }} *</label>
                            <select v-model="form.country_id" class="form-select"><option v-for="c in options.countries || []" :key="c.id" :value="c.id">{{ c.name }} ({{ c.currency }})</option></select>
                        </div>
                        <div class="col-md-4"><label class="form-label">{{ form.kind === 'coupon' ? t('rides.loyalty.coupon_fixed') : t('rides.loyalty.credit') }}</label><input v-model.number="form.value" type="number" min="0" step="0.01" class="form-control"></div>
                        <template v-if="form.kind === 'coupon'">
                            <div class="col-md-4"><label class="form-label">{{ t('rides.loyalty.coupon_percent') }}</label><input v-model.number="form.coupon_percent" type="number" min="1" max="100" class="form-control"></div>
                            <div class="col-md-4"><label class="form-label">{{ t('rides.loyalty.coupon_max') }}</label><input v-model.number="form.coupon_max" type="number" min="0" step="0.01" class="form-control"></div>
                            <div class="col-md-4"><label class="form-label">{{ t('rides.loyalty.coupon_days') }}</label><input v-model.number="form.coupon_days" type="number" min="1" class="form-control"></div>
                        </template>
                        <div class="col-md-4">
                            <label class="form-label">{{ t('rides.loyalty.min_tier') }}</label>
                            <select v-model="form.min_tier_id" class="form-select"><option :value="null">—</option><option v-for="tier in options.tiers || []" :key="tier.id" :value="tier.id">{{ tier.name }}</option></select>
                        </div>
                        <div class="col-md-4"><label class="form-label">{{ t('rides.loyalty.stock') }}</label><input v-model.number="form.stock" type="number" min="0" class="form-control" :placeholder="t('rides.loyalty.unlimited')"></div>
                        <div class="col-md-4"><label class="form-label">{{ t('rides.loyalty.per_user') }}</label><input v-model.number="form.per_user_limit" type="number" min="1" class="form-control" :placeholder="t('rides.loyalty.unlimited')"></div>
                    </template>
                    <div v-if="current !== 'challenges'" class="col-md-4"><label class="form-label">{{ t('rides.loyalty.sort') }}</label><input v-model.number="form.sort_order" type="number" min="0" class="form-control"></div>
                </div>
                <div class="form-check form-switch mt-3"><input id="active" v-model="form.status" class="form-check-input" type="checkbox"><label class="form-check-label" for="active">{{ t('wallet.common.active') }}</label></div>
            </template>
            <template #footer>
                <button type="button" class="btn btn-light" @click="form = null">{{ t('cancel') }}</button>
                <button type="button" class="btn btn-primary" @click="save">{{ t('save') }}</button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import RideLangFields from '../RideLangFields.vue';
import { onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// DORR points (R-146..R-155): tiers, badges, challenges, rewards — and one rider's points.
const { t, locale: uiLocale } = useI18n();
const { can } = usePermission();
const { showError, showSuccess } = useToast();
const tabs = ['tiers', 'badges', 'challenges', 'rewards', 'members'];
const current = ref('tiers');
const items = reactive({ tiers: [], badges: [], challenges: [], rewards: [] });
const options = ref({});
const form = ref(null);
const phone = ref('');
const member = ref(null);
const adjust = reactive({ points: null, reason: '' });

const word = (v) => (v ? v[uiLocale.value] || v.ar || v.en || '' : '');
const minor = (v) => (v === null || v === undefined || v === '' ? null : Math.round(Number(v) * 100));
const major = (v) => (v === null || v === undefined ? null : v / 100);
const local = (iso) => (iso ? iso.slice(0, 16) : '');

function summary(item) {
    switch (current.value) {
        case 'tiers': return `${t('rides.loyalty.min_points')}: ${item.min_points} · ×${(item.multiplier_bp / 10000).toFixed(2)}`;
        case 'badges': return `${t(`rides.loyalty.rules.${item.rule}`)}${item.category ? ` (${item.category})` : ''} ≥ ${item.threshold} · +${item.reward_points}`;
        case 'challenges': return `${t(`rides.loyalty.rules.${item.rule}`)} ${item.target} · +${item.reward_points} · ${local(item.starts_at)} → ${local(item.ends_at)}`;
        default: return `${item.cost_points} ${t('rides.loyalty.points')} · ${t(`rides.loyalty.kinds.${item.kind}`)}`;
    }
}

function open(item) {
    const lines = (list) => ({ ar: (list?.ar || []).join('\n'), en: (list?.en || []).join('\n') });
    form.value = {
        id: item?.id ?? null,
        words: { ar: (item?.name || item?.title)?.ar ?? '', en: (item?.name || item?.title)?.en ?? '' },
        description: { ar: item?.description?.ar ?? '', en: item?.description?.en ?? '' },
        benefitsText: lines(item?.benefits),
        min_points: item?.min_points ?? 0, multiplier: item ? item.multiplier_bp / 10000 : 1, color: item?.color ?? '#C0A062',
        rule: item?.rule ?? 'rides', category: item?.category ?? '', goal: item?.threshold ?? item?.target ?? 1, reward_points: item?.reward_points ?? 0,
        icon: item?.icon ?? 'star',
        starts_at: local(item?.starts_at) || new Date().toISOString().slice(0, 16), ends_at: local(item?.ends_at) || '',
        country_id: item?.country_id ?? (current.value === 'rewards' ? options.value.countries?.[0]?.id ?? null : null),
        cost_points: item?.cost_points ?? 100, kind: item?.kind ?? 'wallet_credit', value: major(item?.value_minor ?? 0),
        coupon_percent: item?.coupon_percent ?? null, coupon_max: major(item?.coupon_max_minor ?? null), coupon_days: item?.coupon_days ?? 30,
        min_tier_id: item?.min_tier_id ?? null, stock: item?.stock ?? null, per_user_limit: item?.per_user_limit ?? null,
        sort_order: item?.sort_order ?? 0, status: item?.status ?? true,
    };
}

function body() {
    const f = form.value;
    const description = f.description.ar || f.description.en ? f.description : null;
    const split = (s) => s.split('\n').map((x) => x.trim()).filter(Boolean);
    switch (current.value) {
        case 'tiers': return { name: f.words, benefits: { ar: split(f.benefitsText.ar), en: split(f.benefitsText.en) }, min_points: f.min_points, multiplier_bp: Math.round(f.multiplier * 10000), color: f.color, sort_order: f.sort_order, status: f.status };
        case 'badges': return { name: f.words, description, icon: f.icon, rule: f.rule, category: f.rule === 'category_rides' ? f.category : null, threshold: f.goal, reward_points: f.reward_points, sort_order: f.sort_order, status: f.status };
        case 'challenges': return { title: f.words, description, rule: f.rule, category: f.rule === 'category_rides' ? f.category : null, target: f.goal, reward_points: f.reward_points, country_id: f.country_id, starts_at: f.starts_at, ends_at: f.ends_at, status: f.status };
        default: return {
            title: f.words, description, cost_points: f.cost_points, kind: f.kind, country_id: f.country_id, value_minor: minor(f.value) ?? 0,
            coupon_percent: f.kind === 'coupon' ? f.coupon_percent || null : null, coupon_max_minor: f.kind === 'coupon' ? minor(f.coupon_max) : null,
            coupon_days: f.coupon_days, min_tier_id: f.min_tier_id, stock: f.stock === '' ? null : f.stock, per_user_limit: f.per_user_limit === '' ? null : f.per_user_limit,
            sort_order: f.sort_order, status: f.status,
        };
    }
}

async function save() {
    try {
        if (form.value.id) await adminAxios.put(`/api/admin/v1/ride-loyalty/${current.value}/${form.value.id}`, body());
        else await adminAxios.post(`/api/admin/v1/ride-loyalty/${current.value}`, body());
        form.value = null;
        load(current.value);
        if (current.value === 'tiers') loadOptions();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function remove(item) {
    if (!window.confirm(t('rides.document_types.confirm_delete'))) return;
    try {
        await adminAxios.delete(`/api/admin/v1/ride-loyalty/${current.value}/${item.id}`);
        load(current.value);
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function findMember() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ride-loyalty/member', { params: { phone: phone.value } });
        member.value = data.data || false;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function saveAdjust() {
    try {
        await adminAxios.post(`/api/admin/v1/ride-loyalty/members/${member.value.user.id}/adjust`, { points: adjust.points, reason: adjust.reason });
        adjust.points = null;
        adjust.reason = '';
        showSuccess?.(t('rides.loyalty.applied'));
        findMember();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function load(list) {
    if (list === 'members') return;
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/ride-loyalty/${list}`);
        items[list] = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function loadOptions() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ride-loyalty/options');
        options.value = data.data ?? {};
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

watch(current, (list) => load(list));
onMounted(() => { loadOptions(); load('tiers'); });
</script>
