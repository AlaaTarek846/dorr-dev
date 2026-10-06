<template>
    <div>
        <WalletPageHeader :title="t('chat.moments.title')" :section="t('sidebar.chat')" :total="rows.length || null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="input-group input-group-sm" style="max-width: 240px;">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="search" type="search" class="form-control" :placeholder="t('chat.moments.search')">
                </div>
                <div class="btn-group btn-group-sm flex-wrap" role="group">
                    <button v-for="k in ['', ...kinds]" :key="k" type="button" class="btn" :class="kind === k ? 'btn-primary' : 'btn-outline-primary'" @click="kind = k">
                        {{ k ? t(`chat.moments.kinds.${k}`) : t('chat.packages.all') }}
                    </button>
                </div>
                <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave ms-auto" @click="openCreate">
                    <i class="ri-add-line me-1 align-middle"></i>{{ t('chat.moments.add') }}
                </button>
            </div>
            <div class="card-body">
                <div v-if="loading" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></div>
                <div v-else-if="!visibleRows.length" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</div>
                <div v-else class="row g-3">
                    <div v-for="row in visibleRows" :key="row.id" class="col-12 col-md-6 col-xl-4">
                        <div class="moment-card" :class="{ off: !row.status }">
                            <MomentPreview :look="row" :name="row.name" :greeting="(row.translations.find(x => x.locale === locale) || row.translations[0] || {}).greeting" small />
                            <div class="p-3">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="badge bg-light text-dark">{{ t(`chat.moments.kinds.${row.kind}`) }}</span>
                                    <span class="badge" :class="row.date_rule === 'hijri' ? 'bg-success-transparent' : 'bg-info-transparent'">{{ ruleLabel(row) }}</span>
                                    <span v-if="!row.default_on" class="badge bg-warning-transparent">{{ t('chat.moments.opt_in') }}</span>
                                </div>
                                <div class="fs-12 text-muted">
                                    {{ t('chat.moments.next') }}: <strong dir="ltr">{{ row.next || '—' }}</strong>
                                    · {{ row.countries.length ? row.countries.join(', ') : t('chat.moments.everywhere') }}
                                </div>
                                <div class="d-flex align-items-center gap-1 mt-2">
                                    <div v-if="canUpdate" class="toggle toggle-success mb-0 me-auto" :class="{ on: row.status }" role="button" @click="toggleStatus(row)"><span></span></div>
                                    <button type="button" class="btn btn-sm btn-success-light btn-icon" :title="t('chat.moments.dates')" @click="openDates(row)"><i class="ri-calendar-event-line"></i></button>
                                    <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" @click="openEdit(row)"><i class="ri-pencil-line"></i></button>
                                    <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" @click="remove(row)"><i class="ri-delete-bin-line"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ------------------------------------------------------------ editor -->
        <WalletModal :show="showModal" :title="editingId ? t('chat.moments.edit') : t('chat.moments.add')" size="xl" @close="showModal = false">
            <form id="moment-form" class="row g-3" @submit.prevent="save">
                <div class="col-lg-7">
                    <CatalogTranslationTabs :languages="languages" :active-locale="activeLocale" :translation-tab-class="tabClass" :translation-tab-feedback="tabFeedback" @update:active-locale="activeLocale = $event" />
                    <div v-if="form.translations[activeLocale]" class="mb-3">
                        <label class="form-label">{{ t('chat.common.name') }} <span class="text-danger">*</span></label>
                        <input v-model="form.translations[activeLocale].name" type="text" maxlength="120" class="form-control">
                        <label class="form-label mt-2">{{ t('chat.moments.greeting') }}</label>
                        <textarea v-model="form.translations[activeLocale].greeting" rows="2" maxlength="300" class="form-control"></textarea>
                        <div v-if="errors.translations" class="text-danger fs-12 mt-1">{{ errors.translations }}</div>
                    </div>

                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label">{{ t('chat.moments.kind') }}</label>
                            <select v-model="form.kind" class="form-select">
                                <option v-for="k in kinds" :key="k" :value="k">{{ t(`chat.moments.kinds.${k}`) }}</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ t('chat.moments.rule') }}</label>
                            <select v-model="form.date_rule" class="form-select">
                                <option value="gregorian">{{ t('chat.moments.rules.gregorian') }}</option>
                                <option value="hijri">{{ t('chat.moments.rules.hijri') }}</option>
                                <option value="manual">{{ t('chat.moments.rules.manual') }}</option>
                            </select>
                        </div>
                        <template v-if="form.date_rule !== 'manual'">
                            <div class="col-md-2">
                                <label class="form-label">{{ t('chat.moments.day') }}</label>
                                <input v-model.number="form.day" type="number" min="1" max="31" class="form-control">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">{{ t('chat.moments.month') }}</label>
                                <select v-model.number="form.month" class="form-select">
                                    <option v-for="m in 12" :key="m" :value="m">{{ form.date_rule === 'hijri' ? hijriMonths[m - 1] : m }}</option>
                                </select>
                            </div>
                        </template>
                        <div v-else class="col-md-4 d-flex align-items-end"><small class="text-muted">{{ t('chat.moments.manual_hint') }}</small></div>
                        <div class="col-md-4">
                            <label class="form-label">{{ t('chat.moments.duration') }}</label>
                            <input v-model.number="form.duration_days" type="number" min="1" max="60" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ t('chat.moments.before') }}</label>
                            <input v-model.number="form.show_before_days" type="number" min="0" max="60" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ t('chat.moments.after') }}</label>
                            <input v-model.number="form.show_after_days" type="number" min="0" max="30" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">{{ t('chat.moments.countries') }}</label>
                            <MultiSelect v-model="form.countries" :options="countries" option-label="label" option-value="code" filter display="chip" class="w-100" :placeholder="t('chat.moments.everywhere')" />
                            <small class="text-muted">{{ t('chat.moments.countries_hint') }}</small>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch"><input id="m-default" v-model="form.default_on" class="form-check-input" type="checkbox"><label class="form-check-label" for="m-default">{{ t('chat.moments.default_on') }}</label></div>
                            <small class="text-muted">{{ t('chat.moments.default_on_hint') }}</small>
                        </div>
                    </div>
                </div>

                <!-- the look, with a live preview -->
                <div class="col-lg-5">
                    <MomentPreview :look="previewLook" :name="form.translations[activeLocale]?.name" :greeting="form.translations[activeLocale]?.greeting" />
                    <div class="row g-2 mt-1">
                        <div class="col-6">
                            <label class="form-label">{{ t('chat.moments.primary') }}</label>
                            <input v-model="form.primary_color" type="color" class="form-control form-control-color w-100">
                        </div>
                        <div class="col-6">
                            <label class="form-label">{{ t('chat.moments.secondary') }}</label>
                            <input v-model="form.secondary_color" type="color" class="form-control form-control-color w-100">
                        </div>
                        <div class="col-4">
                            <label class="form-label">{{ t('chat.moments.emoji') }}</label>
                            <input v-model="form.emoji" type="text" maxlength="8" class="form-control text-center fs-20">
                        </div>
                        <div class="col-8">
                            <label class="form-label">{{ t('chat.moments.animation') }}</label>
                            <select v-model="form.animation" class="form-select">
                                <option v-for="a in animations" :key="a" :value="a">{{ t(`chat.moments.animations.${a}`) }}</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">{{ t('chat.moments.theme') }}</label>
                            <select v-model="form.theme" class="form-select">
                                <option v-for="th in themes" :key="th" :value="th">{{ t(`chat.moments.themes.${th}`) }}</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">{{ t('chat.moments.card') }}</label>
                            <input type="file" accept="image/png,image/jpeg,image/webp" class="form-control" @change="pickCard">
                            <div class="d-flex justify-content-between">
                                <small class="text-muted">{{ t('chat.moments.card_hint') }}</small>
                                <a v-if="cardPreview" href="#" class="fs-12 text-danger" @click.prevent="removeCard">{{ t('chat.moments.card_remove') }}</a>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-if="errors.general" class="col-12 text-danger fs-13">{{ errors.general }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="saving" @click="showModal = false">{{ t('cancel') }}</button>
                <button type="submit" form="moment-form" class="btn btn-primary" :disabled="saving"><span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}</button>
            </template>
        </WalletModal>

        <!-- ------------------------------------------------------------ dates & corrections -->
        <WalletModal :show="!!datesFor" :title="datesFor ? `${t('chat.moments.dates')} — ${datesFor.moment.name}` : ''" size="lg" @close="datesFor = null">
            <div v-if="datesFor">
                <p class="text-muted fs-13">{{ t('chat.moments.dates_intro') }}</p>
                <div v-for="y in datesFor.years" :key="y.year" class="mb-4">
                    <h6 class="fw-bold mb-2">{{ y.year }} <span class="text-muted fs-12 fw-normal">— {{ t('chat.moments.computed') }}: <span dir="ltr">{{ y.computed.join(' , ') || '—' }}</span></span></h6>
                    <table class="table table-sm align-middle mb-0">
                        <tbody>
                            <tr>
                                <td class="fw-semibold">{{ t('chat.moments.everywhere') }}</td>
                                <td style="width: 190px;"><input type="date" class="form-control form-control-sm" :value="y.everywhere" :disabled="!canUpdate" @change="setDate(y.year, null, $event.target.value)"></td>
                                <td style="width: 40px;"><button v-if="y.everywhere && canUpdate" type="button" class="btn btn-sm btn-light btn-icon" @click="setDate(y.year, null, null)"><i class="ri-close-line"></i></button></td>
                            </tr>
                            <tr v-for="c in y.countries" :key="c.country_id">
                                <td>{{ c.name }} <span class="text-muted">({{ c.code }})</span> <span class="fs-11 text-muted ms-1" dir="ltr">→ {{ c.effective.join(' , ') || '—' }}</span></td>
                                <td><input type="date" class="form-control form-control-sm" :value="c.correction" :disabled="!canUpdate" @change="setDate(y.year, c.country_id, $event.target.value)"></td>
                                <td><button v-if="c.correction && canUpdate" type="button" class="btn btn-sm btn-light btn-icon" @click="setDate(y.year, c.country_id, null)"><i class="ri-close-line"></i></button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, defineComponent, h, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import MultiSelect from 'primevue/multiselect';
import adminAxios from '../../../../../../../api/adminAxios';
import CatalogTranslationTabs from '../../../../../../../components/catalog/CatalogTranslationTabs.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';
import { useAvailableLanguagesStore } from '../../../../../../../stores/availableLanguages';

const { t, locale } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const languagesStore = useAvailableLanguagesStore();

const canCreate = computed(() => can('chat-moments.create'));
const canUpdate = computed(() => can('chat-moments.update'));
const canDelete = computed(() => can('chat-moments.delete'));

const kinds = ['religious', 'national', 'international', 'social', 'cultural', 'seasonal'];
const animations = ['confetti', 'lanterns', 'fireworks', 'hearts', 'stars', 'balloons', 'flags', 'snow', 'petals', 'sparkles'];
const themes = ['ramadan', 'eid', 'hajj', 'hijri_new_year', 'mawlid', 'national', 'new_year', 'christmas', 'spring', 'love', 'mother', 'father', 'women', 'teacher', 'children', 'friendship', 'work', 'culture', 'generic'];
const hijriMonths = ['محرم', 'صفر', 'ربيع الأول', 'ربيع الآخر', 'جمادى الأولى', 'جمادى الآخرة', 'رجب', 'شعبان', 'رمضان', 'شوال', 'ذو القعدة', 'ذو الحجة'];

/** The card as the phone draws it: the colours as a gradient, the emoji, the animation's sparkle. */
const MomentPreview = defineComponent({
    props: { look: { type: Object, required: true }, name: { type: String, default: '' }, greeting: { type: String, default: '' }, small: { type: Boolean, default: false } },
    setup(props) {
        return () => h('div', {
            class: ['moment-preview', props.small ? 'small' : '', `anim-${props.look.animation || 'confetti'}`],
            style: props.look.card_image
                ? { backgroundImage: `url(${props.look.card_image})` }
                : { background: `linear-gradient(150deg, ${props.look.primary_color || '#001B53'}, ${props.look.secondary_color || '#FA7552'})` },
        }, [
            h('div', { class: 'moment-sparkles' }, Array.from({ length: 10 }, (_, i) => h('span', { style: { left: `${(i * 37) % 100}%`, animationDelay: `${(i % 5) * 0.4}s` } }, props.look.emoji || '✨'))),
            h('div', { class: 'moment-text' }, [
                h('div', { class: 'moment-emoji' }, props.look.emoji || '✨'),
                h('div', { class: 'moment-name' }, props.name || '—'),
                props.greeting ? h('div', { class: 'moment-greeting' }, props.greeting) : null,
            ]),
        ]);
    },
});

const languages = computed(() => languagesStore.items);
const activeLocale = ref('');
const countries = ref([]);
const rows = ref([]);
const loading = ref(false);
const search = ref('');
const kind = ref('');
const visibleRows = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return rows.value.filter((r) => (!kind.value || r.kind === kind.value)
        && (!needle || r.key.includes(needle) || (r.translations ?? []).some((x) => (x.name || '').toLowerCase().includes(needle))));
});

const showModal = ref(false);
const editingId = ref(null);
const saving = ref(false);
const errors = reactive({});
const blank = () => ({
    kind: 'social', date_rule: 'gregorian', month: 1, day: 1, duration_days: 1, show_before_days: 3, show_after_days: 0, countries: [], default_on: true,
    theme: 'generic', primary_color: '#001B53', secondary_color: '#FA7552', emoji: '✨', animation: 'confetti', status: true, translations: {}, card: null, remove_card: false,
});
const form = reactive(blank());
const cardPreview = ref(null);
const previewLook = computed(() => ({ ...form, card_image: cardPreview.value }));
const datesFor = ref(null);

function ruleLabel(row) {
    if (row.date_rule === 'manual') return t('chat.moments.rules.manual');
    if (row.date_rule === 'hijri') return `${row.day} ${hijriMonths[(row.month || 1) - 1]}`;
    return `${row.day}/${row.month}`;
}

function tabClass(code) {
    const filled = !!(form.translations[code]?.name || '').trim();

    return { active: activeLocale.value === code, 'catalog-lang-tab--error': !!errors.translations && !filled, 'catalog-lang-tab--valid': filled };
}

function tabFeedback(code) {
    const filled = !!(form.translations[code]?.name || '').trim();

    return { show: filled || !!errors.translations, valid: filled };
}

function ensureTranslations() {
    languages.value.forEach((lang) => { form.translations[lang.code] ??= { name: '', greeting: '' }; });
    if (!activeLocale.value && languages.value.length) activeLocale.value = languages.value[0].code;
}

function resetErrors() {
    Object.keys(errors).forEach((key) => delete errors[key]);
}

function pickCard(event) {
    const file = event.target.files?.[0] || null;
    form.card = file;
    form.remove_card = false;
    if (file) cardPreview.value = URL.createObjectURL(file);
}

function removeCard() {
    form.card = null;
    form.remove_card = true;
    cardPreview.value = null;
}

function openCreate() {
    editingId.value = null;
    resetErrors();
    Object.assign(form, blank());
    cardPreview.value = null;
    ensureTranslations();
    activeLocale.value = languages.value[0]?.code || '';
    showModal.value = true;
}

function openEdit(row) {
    editingId.value = row.id;
    resetErrors();
    Object.assign(form, blank(), {
        kind: row.kind, date_rule: row.date_rule, month: row.month || 1, day: row.day || 1, duration_days: row.duration_days, show_before_days: row.show_before_days,
        show_after_days: row.show_after_days, countries: [...(row.countries || [])], default_on: row.default_on, theme: row.theme, primary_color: row.primary_color || '#001B53',
        secondary_color: row.secondary_color || '#FA7552', emoji: row.emoji || '', animation: row.animation || 'confetti', status: row.status, translations: {},
    });
    cardPreview.value = row.card_image;
    ensureTranslations();
    (row.translations ?? []).forEach((tr) => { form.translations[tr.locale] = { name: tr.name ?? '', greeting: tr.greeting ?? '' }; });
    activeLocale.value = languages.value[0]?.code || '';
    showModal.value = true;
}

async function save() {
    resetErrors();
    saving.value = true;
    const body = new FormData();
    ['kind', 'date_rule', 'duration_days', 'show_before_days', 'show_after_days', 'theme', 'primary_color', 'secondary_color', 'emoji', 'animation'].forEach((k) => body.append(k, form[k] ?? ''));
    if (form.date_rule !== 'manual') {
        body.append('month', String(form.month));
        body.append('day', String(form.day));
    }
    body.append('default_on', form.default_on ? '1' : '0');
    body.append('status', form.status ? '1' : '0');
    (form.countries || []).forEach((c, i) => body.append(`countries[${i}]`, c));
    if (!(form.countries || []).length) body.append('countries', '');
    languages.value.forEach((lang, i) => {
        body.append(`translations[${i}][locale]`, lang.code);
        body.append(`translations[${i}][name]`, form.translations[lang.code]?.name || '');
        body.append(`translations[${i}][greeting]`, form.translations[lang.code]?.greeting || '');
    });
    if (form.card) body.append('card', form.card);
    if (form.remove_card) body.append('remove_card', '1');

    try {
        await adminAxios.post(editingId.value ? `/api/admin/v1/chat-moments/${editingId.value}` : '/api/admin/v1/chat-moments', body);
        showSuccess(t('chat.moments.saved'));
        showModal.value = false;
        load();
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};
        Object.entries(bag).forEach(([key, messages]) => { errors[key.startsWith('translations') ? 'translations' : key] ??= messages[0]; });
        errors.general = Object.keys(bag).length ? Object.values(bag).map((m) => m[0]).join(' · ') : extractApiErrorMessage(error);
    } finally {
        saving.value = false;
    }
}

async function toggleStatus(row) {
    try {
        await adminAxios.patch(`/api/admin/v1/chat-moments/${row.id}/status`, { status: !row.status });
        row.status = !row.status;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function remove(row) {
    if (!window.confirm(t('chat.moments.confirm_delete', { name: row.name }))) return;
    try {
        await adminAxios.delete(`/api/admin/v1/chat-moments/${row.id}`);
        showSuccess(t('chat.common.deleted'));
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function openDates(row) {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/chat-moments/${row.id}/dates`);
        datesFor.value = data.data;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function setDate(year, countryId, date) {
    try {
        const { data } = await adminAxios.put(`/api/admin/v1/chat-moments/${datesFor.value.moment.id}/dates`, { year, country_id: countryId, date: date || null });
        const first = datesFor.value.years[0]?.year;
        datesFor.value = data.data;
        if (first && first !== year) {
            const again = await adminAxios.get(`/api/admin/v1/chat-moments/${datesFor.value.moment.id}/dates`, { params: { year: first } });
            datesFor.value = again.data.data;
        }
        showSuccess(t('chat.moments.date_saved'));
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/chat-moments');
        rows.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

watch(languages, () => ensureTranslations(), { immediate: true });

onMounted(async () => {
    load();
    await languagesStore.fetch();
    try {
        const { data } = await adminAxios.get('/api/admin/v1/countries/dropdown');
        countries.value = (data.data ?? []).map((c) => ({ code: c.code, label: `${c.name} (${c.code})` }));
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
});
</script>

<style>
.moment-card { border: 1px solid var(--default-border, #eef0f3); border-radius: 16px; overflow: hidden; background: var(--custom-white, #fff); }
.moment-card.off { opacity: 0.55; }
.moment-preview { position: relative; height: 230px; border-radius: 16px; overflow: hidden; background-size: cover; background-position: center; display: flex; align-items: center; justify-content: center; }
.moment-preview.small { height: 150px; border-radius: 0; }
.moment-text { position: relative; text-align: center; color: #fff; padding: 12px; text-shadow: 0 2px 8px rgba(0, 0, 0, 0.35); }
.moment-emoji { font-size: 44px; animation: moment-bob 2.4s ease-in-out infinite; }
.moment-preview.small .moment-emoji { font-size: 34px; }
.moment-name { font-weight: 800; font-size: 20px; }
.moment-preview.small .moment-name { font-size: 16px; }
.moment-greeting { font-size: 13px; opacity: 0.92; margin-top: 4px; }
.moment-sparkles { position: absolute; inset: 0; pointer-events: none; }
.moment-sparkles span { position: absolute; top: -20%; font-size: 14px; opacity: 0.55; animation: moment-fall 4s linear infinite; }
.anim-fireworks .moment-sparkles span, .anim-sparkles .moment-sparkles span, .anim-stars .moment-sparkles span { animation-name: moment-twinkle; top: auto; }
.anim-balloons .moment-sparkles span, .anim-lanterns .moment-sparkles span { animation-name: moment-rise; top: 110%; }
@keyframes moment-fall { to { transform: translateY(320px) rotate(160deg); } }
@keyframes moment-rise { to { transform: translateY(-320px); } }
@keyframes moment-twinkle { 0%, 100% { opacity: 0.1; transform: scale(0.6); } 50% { opacity: 0.9; transform: scale(1.2); } }
@keyframes moment-bob { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }
.moment-sparkles span:nth-child(odd) { top: 20%; }
.moment-sparkles span:nth-child(3n) { top: 60%; }
</style>
