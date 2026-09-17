import { computed, nextTick, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import adminAxios from '../api/adminAxios';
import providerAxios from '../api/providerAxios';
import userAxios from '../api/userAxios';
import { useAuthStore } from '../stores/auth';
import { useProviderAuthStore } from '../stores/providerAuth';
import { useUserAuthStore } from '../stores/userAuth';

export function useHeader() {
    const router = useRouter();
    const route = useRoute();
    const authStore = useAuthStore();
    const userAuthStore = useUserAuthStore();
    const providerAuthStore = useProviderAuthStore();
    const isFullscreen = ref(false);
    const cartCount = ref(5);

    const isProviderPanel = computed(() => String(route.name ?? '').startsWith('provider.'));
    const isUserPanel = computed(() => String(route.name ?? '').startsWith('user.'));

    const profileRouteName = computed(() => {
        if (isProviderPanel.value) {
            return 'provider.profile';
        }

        if (isUserPanel.value) {
            return 'user.profile';
        }

        return 'admin.profile';
    });

    const dashboardHref = computed(() => {
        if (isProviderPanel.value) {
            return '/provider/dashboard';
        }

        if (isUserPanel.value) {
            return '/user/dashboard';
        }

        return '/admin/dashboard';
    });

    const adminName = computed(() => {
        if (isProviderPanel.value) {
            return providerAuthStore.provider?.name ?? 'Provider';
        }

        if (isUserPanel.value) {
            return userAuthStore.user?.name ?? 'User';
        }

        return authStore.admin?.name ?? 'Admin';
    });

    const adminRole = computed(() => {
        if (isProviderPanel.value) {
            return providerAuthStore.provider?.email ?? '';
        }

        if (isUserPanel.value) {
            return userAuthStore.user?.email ?? '';
        }

        return authStore.admin?.email ?? '';
    });

    const adminAvatar = computed(() => {
        if (isProviderPanel.value) {
            return providerAuthStore.provider?.avatar_thumb
                ?? providerAuthStore.provider?.avatar
                ?? '/dashboard/assets/images/faces/9.jpg';
        }

        if (isUserPanel.value) {
            return userAuthStore.user?.avatar_thumb
                ?? userAuthStore.user?.avatar
                ?? '/dashboard/assets/images/faces/9.jpg';
        }

        return authStore.admin?.avatar_thumb
            ?? authStore.admin?.avatar
            ?? '/dashboard/assets/images/faces/9.jpg';
    });

    function toggleSidebar() {
        if (typeof window.toggleSidemenu === 'function') {
            window.toggleSidemenu();

            return;
        }

        const html = document.documentElement;
        const toggled = html.getAttribute('data-toggled');

        if (toggled === 'close') {
            html.removeAttribute('data-toggled');
        } else {
            html.setAttribute('data-toggled', 'close');
        }
    }

    function toggleTheme() {
        const html = document.documentElement;
        const isDark = html.getAttribute('data-theme-mode') === 'dark';

        if (isDark) {
            html.setAttribute('data-theme-mode', 'light');
            html.setAttribute('data-header-styles', 'light');
            html.setAttribute('data-menu-styles', 'dark');
            html.removeAttribute('data-bg-theme');
            localStorage.removeItem('ynexdarktheme');
            localStorage.removeItem('ynexMenu');
            localStorage.removeItem('ynexHeader');
        } else {
            html.setAttribute('data-theme-mode', 'dark');
            html.setAttribute('data-header-styles', 'dark');
            html.setAttribute('data-menu-styles', 'dark');
            localStorage.setItem('ynexdarktheme', 'true');
            localStorage.setItem('ynexMenu', 'dark');
            localStorage.setItem('ynexHeader', 'dark');
        }

        document.querySelector('#switcher-light-theme') && (document.querySelector('#switcher-light-theme').checked = ! isDark);
        document.querySelector('#switcher-dark-theme') && (document.querySelector('#switcher-dark-theme').checked = isDark);
        document.querySelector('#switcher-header-light') && (document.querySelector('#switcher-header-light').checked = ! isDark);
        document.querySelector('#switcher-header-dark') && (document.querySelector('#switcher-header-dark').checked = isDark);
        document.querySelector('#switcher-menu-light') && (document.querySelector('#switcher-menu-light').checked = ! isDark);
        document.querySelector('#switcher-menu-dark') && (document.querySelector('#switcher-menu-dark').checked = isDark);
    }

    function toggleFullscreen() {
        const openIcon = document.querySelector('.full-screen-open');
        const closeIcon = document.querySelector('.full-screen-close');
        const element = document.documentElement;
        const isActive = Boolean(
            document.fullscreenElement
            ?? document.webkitFullscreenElement
            ?? document.msFullscreenElement,
        );

        if (! isActive) {
            const request = element.requestFullscreen
                ?? element.webkitRequestFullscreen
                ?? element.msRequestFullscreen;

            request?.call(element);
            isFullscreen.value = true;
            closeIcon?.classList.remove('d-none');
            closeIcon?.classList.add('d-block');
            openIcon?.classList.add('d-none');
        } else {
            const exit = document.exitFullscreen
                ?? document.webkitExitFullscreen
                ?? document.msExitFullscreen;

            exit?.call(document);
            isFullscreen.value = false;
            closeIcon?.classList.add('d-none');
            closeIcon?.classList.remove('d-block');
            openIcon?.classList.remove('d-none');
        }
    }

    function initSimpleBars() {
        if (typeof window.SimpleBar === 'undefined') {
            return;
        }

        ['header-shortcut-scroll', 'header-notification-scroll', 'header-cart-items-scroll'].forEach((id) => {
            const element = document.getElementById(id);

            if (element && ! element.dataset.simplebarInit) {
                new window.SimpleBar(element, { autoHide: true });
                element.dataset.simplebarInit = 'true';
            }
        });
    }

    function initCartRemove() {
        document.querySelectorAll('.header-cart-remove').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                button.closest('.dropdown-item')?.remove();
                cartCount.value = Math.max(0, cartCount.value - 1);
                const badge = document.getElementById('cart-icon-badge');
                const data = document.getElementById('cart-data');

                if (badge) {
                    badge.textContent = String(cartCount.value);
                }

                if (data) {
                    data.textContent = `${cartCount.value} Items`;
                }
            });
        });
    }

    async function loadProfile() {
        if (isProviderPanel.value) {
            if (! providerAuthStore.isAuthenticated || providerAuthStore.provider) {
                return;
            }

            try {
                const { data } = await providerAxios.get('/api/provider/v1/me');
                providerAuthStore.setSession({ provider: data.data });
            } catch {
                providerAuthStore.logout();
            }

            return;
        }

        if (isUserPanel.value) {
            if (! userAuthStore.isAuthenticated || userAuthStore.user) {
                return;
            }

            try {
                const { data } = await userAxios.get('/api/user/v1/me');
                userAuthStore.setSession({ user: data.data });
            } catch {
                userAuthStore.logout();
            }

            return;
        }

        if (! authStore.isAuthenticated || authStore.admin) {
            return;
        }

        try {
            const { data } = await adminAxios.get('/api/admin/v1/me');
            authStore.setSession({
                token: authStore.token,
                admin: data.data,
            });
        } catch {
            authStore.logout();
        }
    }

    async function logout() {
        if (isProviderPanel.value) {
            try {
                await providerAxios.post('/api/provider/v1/logout');
            } catch {
                //
            } finally {
                providerAuthStore.logout();
                await router.push({ name: 'provider.login' });
            }

            return;
        }

        if (isUserPanel.value) {
            try {
                await userAxios.post('/api/user/v1/logout');
            } catch {
                //
            } finally {
                userAuthStore.logout();
                await router.push({ name: 'user.login' });
            }

            return;
        }

        try {
            await adminAxios.post('/api/admin/v1/logout');
        } catch {
            //
        } finally {
            authStore.logout();
            await router.push({ name: 'admin.login' });
        }
    }

    onMounted(async () => {
        await loadProfile();
        await nextTick();

        initSimpleBars();
        initCartRemove();

        document.addEventListener('fullscreenchange', () => {
            if (! document.fullscreenElement) {
                isFullscreen.value = false;
                document.querySelector('.full-screen-close')?.classList.add('d-none');
                document.querySelector('.full-screen-open')?.classList.remove('d-none');
            }
        });
    });

    return {
        adminName,
        adminRole,
        adminAvatar,
        cartCount,
        dashboardHref,
        profileRouteName,
        toggleSidebar,
        toggleTheme,
        toggleFullscreen,
        logout,
    };
}
