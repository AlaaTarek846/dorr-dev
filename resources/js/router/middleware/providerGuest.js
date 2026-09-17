import { useProviderAuthStore } from '../../stores/providerAuth';

export default function providerGuest({ next }) {
    const authStore = useProviderAuthStore();

    if (authStore.isAuthenticated) {
        return next({ name: 'provider.dashboard' });
    }

    return next();
}
