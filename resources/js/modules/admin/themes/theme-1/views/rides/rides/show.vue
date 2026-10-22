<template>
    <div>
        <WalletPageHeader :title="ride?.number || t('rides.rides.ride')" :section="t('rides.rides.title')" />
        <div v-if="!ride" class="text-center py-5"><span class="spinner-border"></span></div>

        <template v-else>
            <div class="card custom-card mb-3">
                <div class="card-body d-flex flex-wrap gap-3 align-items-center">
                    <span class="badge fs-13" :class="statusClass(ride.status)">{{ t(`rides.ride_status.${ride.status}`) }}</span>
                    <span class="badge bg-light text-dark">{{ ride.category }}</span>
                    <span class="badge bg-light text-dark">{{ t(`rides.pricing_mode.${ride.pricing_mode}`) }}</span>
                    <span class="badge bg-light text-dark">{{ t(`rides.payment.${ride.payment_method}`) }} · {{ ride.payment_status }}</span>
                    <div class="ms-auto fs-4 fw-bold">{{ money(ride.money.final_total_minor ?? ride.money.agreed_minor) }}</div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-xl-4">
                    <WalletSection :title="t('rides.rides.people')" icon="ri-team-line" class="mb-3">
                        <WalletInfoTile :label="t('rides.rides.rider')" icon="ri-user-line" class="mb-2">{{ ride.rider?.name || '—' }} <span class="text-muted fs-12" dir="ltr">{{ ride.rider?.phone }}</span></WalletInfoTile>
                        <WalletInfoTile v-if="ride.beneficiary" :label="t('rides.rides.beneficiary')" icon="ri-user-shared-line" class="mb-2">
                            {{ ride.beneficiary.name }} <span class="text-muted fs-12" dir="ltr">{{ ride.beneficiary.phone }}</span>
                            <div class="text-muted fs-12">{{ t('rides.rides.beneficiary_consent') }} · {{ formatDate(ride.beneficiary.consented_at) }}</div>
                        </WalletInfoTile>
                        <WalletInfoTile v-if="ride.pickup_point" :label="t('rides.rides.pickup_point')" icon="ri-map-pin-user-line" class="mb-2">
                            {{ ride.pickup_point.name }} <span class="badge bg-light text-default fs-10">{{ ride.pickup_point.kind_label }}</span>
                            <div v-if="ride.pickup_point.instructions" class="text-muted fs-12">{{ ride.pickup_point.instructions }}</div>
                        </WalletInfoTile>
                        <WalletInfoTile v-if="ride.dropoff_point" :label="t('rides.rides.dropoff_point')" icon="ri-map-pin-2-line" class="mb-2">
                            {{ ride.dropoff_point.name }} <span class="badge bg-light text-default fs-10">{{ ride.dropoff_point.kind_label }}</span>
                            <div v-if="ride.dropoff_point.instructions" class="text-muted fs-12">{{ ride.dropoff_point.instructions }}</div>
                        </WalletInfoTile>
                        <WalletInfoTile :label="t('rides.drivers.driver')" icon="ri-steering-2-line" class="mb-2">
                            <router-link v-if="ride.driver" :to="{ name: 'admin.rides.drivers.show', params: { id: ride.driver.id } }">{{ ride.driver.name || ride.driver.phone }}</router-link>
                            <span v-else>—</span>
                        </WalletInfoTile>
                        <WalletInfoTile v-if="ride.vehicle" :label="t('rides.drivers.vehicle')" icon="ri-car-line">{{ ride.vehicle.make }} {{ ride.vehicle.model }} · {{ ride.vehicle.plate_letters }} {{ ride.vehicle.plate_number }}</WalletInfoTile>
                    </WalletSection>

                    <WalletSection v-if="ride.safety && (ride.safety.incidents.length || ride.safety.shares.length)" :title="t('rides.rides.safety_section')" icon="ri-alarm-warning-line" class="mb-3">
                        <div v-for="i in ride.safety.incidents" :key="'i' + i.id" class="d-flex align-items-center gap-2 fs-13 mb-1">
                            <span class="badge" :class="i.status === 'open' ? 'bg-danger' : i.status === 'resolved' ? 'bg-success-transparent' : 'bg-warning-transparent'">SOS · {{ t(`rides.safety.status.${i.status}`) }}</span>
                            <span>{{ t(`rides.safety.reporter.${i.reporter_type}`) }}</span>
                            <span class="text-muted fs-12">{{ formatDate(i.created_at) }}</span>
                            <a v-if="i.lat" :href="`https://www.google.com/maps/search/?api=1&query=${i.lat},${i.lng}`" target="_blank" rel="noopener" class="ms-auto"><i class="ri-map-pin-2-line"></i></a>
                        </div>
                        <div v-for="(s, k) in ride.safety.shares" :key="'s' + k" class="fs-12 text-muted">
                            <i class="ri-share-forward-line me-1"></i>{{ t(`rides.safety.reporter.${s.sharer_type}`) }} · {{ s.views }} 👁 <span v-if="s.revoked">· ✕</span>
                        </div>
                    </WalletSection>

                    <WalletSection v-if="ride.scheduled" :title="t('rides.rides.scheduled')" icon="ri-calendar-event-line" class="mb-3">
                        <WalletInfoTile :label="t('rides.rides.scheduled_at')" icon="ri-time-line" class="mb-2">{{ formatDate(ride.scheduled.at) }}</WalletInfoTile>
                        <WalletInfoTile :label="t('rides.rides.free_cancel_until')" icon="ri-close-circle-line" class="mb-2">{{ formatDate(ride.scheduled.free_cancel_until) }}</WalletInfoTile>
                        <WalletInfoTile :label="t('rides.rides.reserved_by')" icon="ri-steering-2-line">
                            <router-link v-if="ride.scheduled.reserved_driver" :to="{ name: 'admin.rides.drivers.show', params: { id: ride.scheduled.reserved_driver.id } }">{{ ride.scheduled.reserved_driver.name || ride.scheduled.reserved_driver.phone }}</router-link>
                            <span v-else class="text-muted">{{ t('rides.rides.not_reserved') }}</span>
                            <span v-if="ride.scheduled.reserved_at" class="text-muted fs-12"> · {{ formatDate(ride.scheduled.reserved_at) }}</span>
                        </WalletInfoTile>
                    </WalletSection>

                    <WalletSection :title="t('rides.rides.trip')" icon="ri-route-line" class="mb-3">
                        <div class="fs-13">
                            <div class="mb-1"><i class="ri-map-pin-line text-primary me-1"></i>{{ ride.origin.address }}</div>
                            <div v-for="s in ride.stops" :key="s.seq" class="mb-1 ms-3 text-muted"><i class="ri-checkbox-blank-circle-line me-1"></i>{{ s.address }} <span class="fs-11">({{ s.status }})</span></div>
                            <div><i class="ri-flag-line text-danger me-1"></i>{{ ride.destination?.address || t('rides.rides.open_destination') }}</div>
                        </div>
                        <hr>
                        <div class="row g-2 fs-12">
                            <div class="col-6">{{ t('rides.rides.planned') }}: {{ km(ride.planned.distance_m) }} · {{ mins(ride.planned.duration_s) }}</div>
                            <div class="col-6">{{ t('rides.rides.metered') }}: {{ km(ride.metered.distance_m) }} · {{ mins(ride.metered.duration_s) }}</div>
                            <div v-if="ride.metered.source" class="col-12 text-muted">{{ t('rides.rides.distance_source') }}: {{ ride.metered.source }}</div>
                            <div v-if="ride.track" class="col-12 text-muted">{{ t('rides.rides.track') }}: {{ ride.track.points }} · {{ km(ride.track.track_m) }} / {{ km(ride.track.device_m) }}</div>
                        </div>
                    </WalletSection>

                    <WalletSection :title="t('rides.wasl.title')" icon="ri-government-line">
                        <template v-if="can('rides.update') && ride.wasl.status && !['sent', 'not_required'].includes(ride.wasl.status)" #actions>
                            <button type="button" class="btn btn-sm btn-primary" :disabled="busy" @click="retry">{{ t('rides.rides.send_again') }}</button>
                        </template>
                        <div class="fs-13">
                            <span class="badge" :class="ride.wasl.status === 'sent' ? 'bg-success-transparent' : ride.wasl.status === 'failed' ? 'bg-danger-transparent' : 'bg-warning-transparent'">{{ ride.wasl.status ? t(`rides.wasl_trip.${ride.wasl.status}`) : '—' }}</span>
                            <div v-if="ride.wasl.result_code" class="mt-2"><code>{{ ride.wasl.result_code }}</code> {{ ride.wasl.result_message }}</div>
                            <div v-if="ride.wasl.result_code === 'CITY_UNMAPPED'" class="alert alert-warning py-2 fs-12 mt-2">{{ t('rides.rides.city_unmapped') }}</div>
                            <div class="text-muted fs-12 mt-2">{{ t('rides.wasl.attempts') }}: {{ ride.wasl.attempts }} · {{ ride.wasl.origin_city || '—' }} → {{ ride.wasl.destination_city || '—' }}</div>
                        </div>
                        <details v-for="log in ride.wasl.logs" :key="log.id" class="mt-2 fs-12">
                            <summary>{{ log.type }} · {{ log.status_code }} · {{ log.result_code || (log.success ? 'OK' : '—') }}</summary>
                            <pre class="bg-light p-2 rounded mb-0" dir="ltr">{{ JSON.stringify({ request: log.request, response: log.response }, null, 2) }}</pre>
                        </details>
                    </WalletSection>
                </div>

                <div class="col-xl-8">
                    <WalletSection :title="t('rides.rides.money')" icon="ri-money-dollar-circle-line" flush class="mb-3">
                        <div class="row g-0">
                            <div v-for="phase in ['confirmed', 'final']" :key="phase" class="col-md-6">
                                <div class="px-3 pt-2 fs-12 fw-semibold text-muted">{{ t(`rides.rides.phase.${phase}`) }}</div>
                                <table class="table table-sm mb-0 fs-13">
                                    <tbody>
                                        <tr v-for="l in ride.lines.filter((x) => x.phase === phase)" :key="phase + l.code">
                                            <td class="ps-3">{{ l.label }} <span v-if="l.visible_to !== 'both'" class="badge bg-light text-muted fs-10">{{ l.visible_to }}</span></td>
                                            <td class="text-end pe-3">{{ money(l.amount_minor) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div v-if="ride.settlement.length || ride.adjustments.length" class="px-3 py-2 border-top fs-12">
                            <div v-for="(s, i) in ride.settlement" :key="'s' + i">{{ t('rides.rides.settlement') }}: {{ s.type }} · {{ money(s.amount_minor) }} · {{ s.status }} <span v-if="s.release_at" class="text-muted">({{ formatDate(s.release_at) }})</span></div>
                            <div v-for="(a, i) in ride.adjustments" :key="'a' + i">{{ a.type }} · {{ money(a.amount_minor) }} · {{ a.affects }}</div>
                        </div>
                    </WalletSection>

                    <WalletSection v-if="ride.bidding" :title="t('rides.rides.bidding')" icon="ri-auction-line" flush class="mb-3">
                        <div class="px-3 py-2 fs-12 text-muted">
                            {{ t('rides.rides.suggested') }}: {{ money(ride.bidding.suggested_minor) }} · {{ t('rides.rides.floor') }}: {{ money(ride.bidding.floor_minor) }}
                            · {{ t('rides.rides.rider_prices') }}: {{ ride.bidding.prices.map((p) => money(p.price_minor)).join(' → ') }}
                        </div>
                        <table class="table table-sm mb-0 fs-12">
                            <tbody>
                                <tr v-for="(b, i) in ride.bidding.bids" :key="i">
                                    <td class="ps-3">{{ b.driver }} <span class="text-muted">#{{ b.attempt }}</span></td>
                                    <td>{{ money(b.price_minor) }}</td>
                                    <td class="pe-3"><span class="badge" :class="b.status === 'accepted' ? 'bg-success-transparent' : 'bg-light text-muted'">{{ b.status }}</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </WalletSection>

                    <WalletSection :title="t('rides.rides.timeline')" icon="ri-time-line" class="mb-3">
                        <div class="ride-timeline">
                            <div v-for="(e, i) in ride.events" :key="i" class="ride-timeline__item">
                                <span class="ride-timeline__dot"></span>
                                <div class="fw-semibold fs-13">{{ e.type }} <span v-if="e.to" class="text-muted fw-normal">→ {{ e.to }}</span></div>
                                <div class="fs-11 text-muted">{{ formatDate(e.at) }} · {{ e.actor }}<span v-if="e.actor_id"> #{{ e.actor_id }}</span></div>
                            </div>
                        </div>
                        <div v-if="ride.cancel" class="alert alert-danger py-2 fs-12 mt-2 mb-0">{{ t('rides.rides.canceled_by') }}: {{ ride.cancel.actor }} · {{ ride.cancel.stage }} · {{ ride.cancel.reason || '' }} {{ ride.cancel.note || '' }}</div>
                    </WalletSection>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <WalletSection :title="t('rides.rides.offers')" icon="ri-send-plane-line" flush>
                                <table class="table table-sm mb-0 fs-12">
                                    <tbody>
                                        <tr v-if="!ride.offers.length"><td class="text-muted text-center py-3">—</td></tr>
                                        <tr v-for="(o, i) in ride.offers" :key="i"><td class="ps-3">#{{ o.round }} {{ o.driver }}</td><td>{{ km(o.distance_m) }}</td><td class="pe-3">{{ o.status }}</td></tr>
                                    </tbody>
                                </table>
                            </WalletSection>
                        </div>
                        <div class="col-md-6">
                            <WalletSection :title="t('rides.rides.chat')" icon="ri-chat-3-line">
                                <div v-if="!ride.messages.length" class="text-muted fs-12">—</div>
                                <div v-for="(m, i) in ride.messages" :key="i" class="d-flex mb-1" :class="m.from === 'driver' ? 'justify-content-end' : ''">
                                    <span class="chat-bubble" :class="m.from === 'driver' ? 'chat-bubble--driver' : ''">{{ m.body }}<span class="d-block fs-10 opacity-75">{{ formatDate(m.at) }}</span></span>
                                </div>
                            </WalletSection>
                        </div>
                        <!-- O-47: why the request did or didn't reach each captain around it. -->
                        <div class="col-12">
                            <WalletSection :title="t('rides.dispatch.title')" icon="ri-radar-line">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="text-muted fs-12 flex-grow-1">{{ t('rides.dispatch.hint') }}</span>
                                    <button type="button" class="btn btn-sm btn-primary-light" :disabled="checking" @click="checkDispatch">
                                        <span v-if="checking" class="spinner-border spinner-border-sm me-1"></span><i v-else class="ri-search-eye-line me-1"></i>{{ t('rides.dispatch.run') }}
                                    </button>
                                </div>
                                <template v-if="dispatch">
                                    <div class="d-flex flex-wrap gap-2 mb-2">
                                        <span v-for="(ok, key) in dispatch.server" :key="key" class="badge" :class="ok ? 'bg-success-transparent' : 'bg-danger-transparent'">
                                            <i :class="ok ? 'ri-check-line' : 'ri-close-line'" class="me-1"></i>{{ t(`rides.dispatch.server.${key}`) }}
                                        </span>
                                        <span class="badge bg-light text-dark border">{{ t('rides.dispatch.round', { round: dispatch.ride.round, radius: km(dispatch.ride.search_radius_m), max: km(dispatch.ride.max_radius_m) }) }}</span>
                                    </div>
                                    <div v-if="!dispatch.captains.length" class="alert alert-warning py-2 fs-13 mb-0">{{ t('rides.dispatch.nobody') }}</div>
                                    <div v-else class="table-responsive">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead><tr><th>{{ t('rides.drivers.driver') }}</th><th>{{ t('rides.dispatch.distance') }}</th><th>{{ t('rides.dispatch.last_seen') }}</th><th>{{ t('rides.dispatch.reason') }}</th></tr></thead>
                                            <tbody>
                                                <tr v-for="c in dispatch.captains" :key="c.driver_id">
                                                    <td><div class="fw-semibold">{{ c.name || '—' }}</div><div class="fs-11 text-muted" dir="ltr">{{ c.phone }}</div></td>
                                                    <td>{{ km(c.distance_m) }}</td>
                                                    <td class="fs-12">{{ formatDate(c.last_seen_at) }}</td>
                                                    <td><span class="badge" :class="reasonClass(c.reason)">{{ reasonText(c.reason) }}</span></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </template>
                            </WalletSection>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletInfoTile from '../../../../../../../components/wallet/WalletInfoTile.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletSection from '../../../../../../../components/wallet/WalletSection.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// One ride's whole story: people, the trip, the money line by line, the timeline, offers, chat, Wasl.
const { t, locale } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const route = useRoute();
const ride = ref(null);
const busy = ref(false);
const dispatch = ref(null);
const checking = ref(false);

// O-47: «why didn't it reach the captain?» — the dispatch conditions for each captain around the pickup.
async function checkDispatch() {
    checking.value = true;
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/rides/${route.params.id}/dispatch-check`);
        dispatch.value = data.data;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        checking.value = false;
    }
}
const reasonText = (reason) => {
    const key = `rides.dispatch.reasons.${reason}`;
    const text = t(key);
    return text === key ? reason : text;
};
const reasonClass = (reason) => (reason.startsWith('offered') || reason === 'eligible_waiting_next_round' ? 'bg-success-transparent' : 'bg-danger-transparent');

const money = (minor) => (minor == null ? '—' : `${(minor / 10 ** (ride.value?.currency?.decimal_places ?? 2)).toFixed(ride.value?.currency?.decimal_places ?? 2)} ${ride.value?.currency?.symbol ?? ''}`);
const km = (m) => (m == null ? '—' : `${(m / 1000).toFixed(1)} km`);
const mins = (s) => (s == null ? '—' : `${Math.round(s / 60)} min`);
const formatDate = (iso) => (iso ? new Date(iso).toLocaleString(locale.value, { dateStyle: 'short', timeStyle: 'medium' }) : '');
const statusClass = (s) => ({ completed: 'bg-success-transparent', canceled: 'bg-danger-transparent', no_show: 'bg-danger-transparent', expired: 'bg-secondary-transparent' }[s] ?? 'bg-primary-transparent');

async function load() {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/rides/${route.params.id}`);
        ride.value = data.data;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function retry() {
    busy.value = true;
    try {
        const { data } = await adminAxios.post(`/api/admin/v1/rides/${route.params.id}/wasl-retry`);
        ride.value = data.data;
        showSuccess(t('rides.wasl.done'));
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        busy.value = false;
    }
}

onMounted(load);
</script>

<style scoped>
.ride-timeline {
    position: relative;
    padding-inline-start: 1.1rem;
    border-inline-start: 2px solid rgba(0, 27, 83, 0.1);
}

.ride-timeline__item {
    position: relative;
    padding: 0 0 0.75rem 0.5rem;
}

.ride-timeline__dot {
    position: absolute;
    inset-inline-start: -1.55rem;
    top: 0.3rem;
    width: 0.7rem;
    height: 0.7rem;
    border-radius: 50%;
    background: #fa7552;
    box-shadow: 0 0 0 3px rgba(250, 117, 82, 0.2);
}

.chat-bubble {
    display: inline-block;
    max-width: 85%;
    padding: 0.35rem 0.65rem;
    border-radius: 0.8rem;
    background: #f1f3f7;
    font-size: 0.8rem;
}

.chat-bubble--driver {
    background: #001b53;
    color: #fff;
}
</style>
