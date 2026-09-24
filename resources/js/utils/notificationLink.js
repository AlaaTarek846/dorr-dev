/**
 * Where a dashboard notification should take the admin, from its machine-readable payload
 * (`data.type`, set by the backend event — see Modules/Wallet/app/Services/WalletNotifier.php).
 * Returns a vue-router location, or null when the notification is informational only.
 */
export function notificationLink(notification) {
    const type = notification?.data?.type;

    switch (type) {
        case 'wallet_withdrawal':
            return { name: 'admin.wallet.withdrawals' };
        case 'wallet':
            return { name: 'admin.wallet.online-transactions' };
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

    return 'bx bx-bell';
}
