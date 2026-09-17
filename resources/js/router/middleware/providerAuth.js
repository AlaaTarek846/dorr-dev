import { useProviderAuthStore } from '../../stores/providerAuth';

export default function providerAuth({ next }) {
    const authStore = useProviderAuthStore();

    if (! authStore.isAuthenticated) {
        return next({ name: 'provider.login' });
    }

    return next();
}
