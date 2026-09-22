<template>
    <div>
        <label v-if="label" :for="inputId" class="form-label">
            {{ label }}
            <span v-if="required" class="text-danger">*</span>
        </label>
        <div class="input-group phone-country-input" style="direction: ltr;" :class="{ 'is-invalid-group': invalid }">
            <button
                ref="toggleRef"
                type="button"
                class="btn btn-light dropdown-toggle phone-country-input__toggle"
                data-bs-toggle="dropdown"
                data-bs-auto-close="outside"
                aria-expanded="false"
            >
                <span v-if="selectedCountry" class="phone-country-input__flag">
                    <FlagImage
                        :code="resolveCountryFlagCode(selectedCountry)"
                        :size="40"
                        :width="24"
                        :height="18"
                    />
                </span>
                <span v-else class="phone-country-input__placeholder-flag">
                    <i class="ri-global-line"></i>
                </span>
                <span class="phone-country-input__dial">{{ selectedDialCode || '—' }}</span>
            </button>
            <ul
                class="dropdown-menu phone-country-input__menu"
                @hidden.bs.dropdown="countrySearch = ''"
            >
                <li v-if="countries.length" class="phone-country-input__search">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">
                            <i class="ri-search-line text-muted"></i>
                        </span>
                        <input
                            v-model="countrySearch"
                            type="search"
                            class="form-control"
                            :placeholder="t('profile.search_countries')"
                            @click.stop
                        >
                    </div>
                </li>
                <li v-if="! countries.length" class="dropdown-item text-muted">
                    {{ loading ? t('profile.loading_countries') : t('profile.no_countries') }}
                </li>
                <li v-else-if="! filteredCountries.length" class="dropdown-item text-muted">
                    {{ t('profile.no_countries_match') }}
                </li>
                <li v-for="country in filteredCountries" :key="country.id">
                    <button
                        type="button"
                        class="dropdown-item d-flex align-items-center gap-2"
                        :class="{ active: Number(country.id) === Number(countryId) }"
                        @click="selectCountry(country)"
                    >
                        <span class="phone-country-input__flag">
                            <FlagImage
                                :code="resolveCountryFlagCode(country)"
                                :size="40"
                                :width="24"
                                :height="18"
                            />
                        </span>
                        <span class="flex-fill text-truncate">{{ country.name || country.code }}</span>
                        <span class="text-muted fs-12">{{ country.dial_code }}</span>
                    </button>
                </li>
            </ul>
            <input
                :id="inputId"
                :value="phone"
                type="tel"
                dir="ltr"
                class="form-control"
                style="direction: ltr;"
                :class="{ 'is-invalid': invalid, 'is-valid': valid }"
                :placeholder="placeholder"
                maxlength="50"
                @input="onPhoneInput"
            >
        </div>
        <div v-if="error" class="invalid-feedback d-block">{{ error }}</div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../api/adminAxios';
import { formatDialCodeForPayload, resolveCountryFlagCode } from '../../utils/catalog';
import FlagImage from '../ui/FlagImage.vue';

const props = defineProps({
    countryId: {
        type: [Number, String, null],
        default: null,
    },
    phone: {
        type: String,
        default: '',
    },
    label: {
        type: String,
        default: '',
    },
    placeholder: {
        type: String,
        default: '',
    },
    required: {
        type: Boolean,
        default: false,
    },
    invalid: {
        type: Boolean,
        default: false,
    },
    valid: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: '',
    },
    inputId: {
        type: String,
        default: 'phone-country-input',
    },
    axiosClient: {
        type: Object,
        default: null,
    },
    dropdownEndpoint: {
        type: String,
        default: '/api/admin/v1/countries/dropdown',
    },
    show: {
        type: Boolean,
        default: true,
    },
    loadOnShow: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:countryId', 'update:phone', 'country-change', 'countries-loaded']);

const { t } = useI18n();
const countries = ref([]);
const loading = ref(false);
const countrySearch = ref('');
const toggleRef = ref(null);

const filteredCountries = computed(() => {
    const query = countrySearch.value.trim().toLowerCase();

    if (! query) {
        return countries.value;
    }

    return countries.value.filter((country) => {
        const name = String(country.name ?? '').toLowerCase();
        const code = String(country.code ?? '').toLowerCase();
        const dial = String(country.dial_code ?? '');

        return name.includes(query) || code.includes(query) || dial.includes(query);
    });
});

const selectedCountry = computed(() => countries.value.find(
    (country) => Number(country.id) === Number(props.countryId),
) ?? null);

const selectedDialCode = computed(() => formatDialCodeForPayload(selectedCountry.value?.dial_code ?? ''));

function selectCountry(country) {
    emit('update:countryId', country?.id ? Number(country.id) : null);
    emit('country-change', country ?? null);
    closeDropdown();
}

function closeDropdown() {
    countrySearch.value = '';

    if (! toggleRef.value || ! window.bootstrap?.Dropdown) {
        return;
    }

    window.bootstrap.Dropdown.getInstance(toggleRef.value)?.hide();
}

function onPhoneInput(event) {
    emit('update:phone', event.target.value);
}

async function loadCountries() {
    loading.value = true;

    try {
        const client = props.axiosClient ?? adminAxios;
        const { data } = await client.get(props.dropdownEndpoint);

        countries.value = data.data ?? [];
        emit('countries-loaded', countries.value);
    } catch {
        countries.value = [];
    } finally {
        loading.value = false;
    }
}

watch(() => props.show, (visible) => {
    if (visible && props.loadOnShow) {
        loadCountries();
    }
});

onMounted(() => {
    if (! props.loadOnShow || props.show) {
        loadCountries();
    }
});
</script>

<style scoped>
.phone-country-input__toggle {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    min-width: 7rem;
    border-color: var(--input-border, var(--default-border, #dee2e6));
}

.phone-country-input__dial {
    font-size: 0.875rem;
    font-weight: 600;
    direction: ltr;
    unicode-bidi: isolate;
}

.phone-country-input__placeholder-flag {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 18px;
    color: var(--text-muted, #8c9097);
}

.phone-country-input__flag {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    min-width: 24px;
    height: 18px;
    flex-shrink: 0;
}

.phone-country-input__search {
    padding: 0.4rem 0.5rem 0.5rem;
    border-bottom: 1px solid var(--default-border, #e2e5e9);
    margin-bottom: 0.25rem;
}

.phone-country-input__search .input-group-text {
    padding-inline: 0.5rem;
}

.phone-country-input__menu {
    max-height: 16rem;
    overflow-y: auto;
    min-width: 16rem;
}

.phone-country-input__menu .dropdown-item {
    min-height: 2.25rem;
}

.phone-country-input.is-invalid-group .phone-country-input__toggle,
.phone-country-input.is-invalid-group .form-control {
    border-color: var(--bs-form-invalid-border-color, #dc3545);
}
</style>
