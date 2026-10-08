/**
 * Where a dashboard notification should take the admin, from its machine-readable payload
 * (`data.type`, set by the backend event — see Modules/Wallet/app/Services/WalletNotifier.php).
 * Returns a vue-router location, or null when the notification is informational only.
 */
export function notificationLink(notification) {
    // A notification can carry its own admin path (`data.url`, e.g. "/admin/support-tickets?ticket=7"): it wins.
    const url = notification?.data?.url;

    if (typeof url === 'string' && url.startsWith('/')) {
        return url;
    }

    const type = notification?.data?.type;

    switch (type) {
        case 'wallet_withdrawal':
            return { name: 'admin.wallet.withdrawals' };
        case 'wallet_pin_recovery':
            return { name: 'admin.wallet.pin-recovery' };
        case 'wallet':
            return { name: 'admin.wallet.online-transactions' };
        // A ticket opens its conversation on the support page; a customer who asked for an agent in the quick chat
        // opens their latest ticket (the one they open right after, when it exists).
        case 'support':
            return { name: 'admin.support-tickets.index', query: { ticket: notification.data.ticket_id } };
        case 'support_help':
            return { name: 'admin.support-tickets.index', query: { user: notification.data.user_id } };
        default:
            return null;
    }
}

/** A bell icon per kind of event, so the list can be scanned at a glance. */
export function notificationIcon(notification) {
    const event = notification?.event || '';

    if (event.startsWith('wallet.withdrawal')) return 'bx bx-money-withdraw';
    if (event.startsWith('wallet.transfer')) return 'bx bx-transfer-alt';
    if (event.startsWith('wallet.pin')) return 'bx bx-lock-alt';
    if (event.startsWith('wallet.')) return 'bx bx-wallet';
    if (event.startsWith('support.')) return 'bx bx-support';

    return 'bx bx-bell';
}
