<?php

/**
 * Notification texts — English (en)
 *
 * Referenced by key from sendNotification() / NotificationCenter, and resolved per *reader*
 * (in-app list), per *recipient* (real-time) or per *device* (push). To support another language,
 * add lang/{code}/notifications.php with the same keys and add the code to
 * App\Support\LocaleResolver::supported() — anything missing falls back to English.
 *
 * Naming: {event}_title / {event}_body. Placeholders use :variable.
 */
return [

    // -------------------------------------------------------------- wallet: top-up
    'wallet_topup_paid_title' => 'Wallet topped up',
    'wallet_topup_paid_body' => ':amount was added to your wallet.',
    'wallet_topup_paid_bonus_body' => ':amount was added to your wallet, plus a :bonus bonus (usable for services only).',
    'wallet_topup_failed_title' => 'Top-up not completed',
    'wallet_topup_failed_body' => 'Your top-up of :amount was not completed and nothing was added to your wallet. You can try again.',
    'wallet_topup_refunded_title' => 'Top-up reversed',
    'wallet_topup_refunded_body' => 'Your top-up of :amount was reversed and taken back out of your wallet.',

    // -------------------------------------------------------------- wallet: transfers
    'wallet_transfer_sent_title' => 'Transfer sent',
    'wallet_transfer_sent_body' => 'You sent :amount to :name.',
    'wallet_transfer_received_title' => 'You received a transfer',
    'wallet_transfer_received_body' => ':name sent you :amount. You can use it for services.',

    // -------------------------------------------------------------- wallet: PIN
    'wallet_pin_created_title' => 'Wallet PIN created',
    'wallet_pin_created_body' => 'A PIN was set for your wallet. If this was not you, contact support right away.',
    'wallet_pin_changed_title' => 'Wallet PIN changed',
    'wallet_pin_changed_body' => 'Your wallet PIN was changed. If this was not you, contact support right away.',
    'wallet_pin_locked_title' => 'Wallet temporarily locked',
    'wallet_pin_locked_body' => 'Too many wrong PIN attempts. Try again in :minutes minutes. If this was not you, contact support.',

    // -------------------------------------------------------------- wallet: withdrawals
    'wallet_withdrawal_requested_title' => 'Withdrawal request received',
    'wallet_withdrawal_requested_body' => 'Your withdrawal request for :amount is under review.',
    'wallet_withdrawal_paid_title' => 'Withdrawal paid',
    'wallet_withdrawal_paid_body' => 'Your withdrawal of :amount has been transferred. The receipt is available in the app.',
    'wallet_withdrawal_rejected_title' => 'Withdrawal request rejected',
    'wallet_withdrawal_rejected_body' => 'Your withdrawal request for :amount was rejected and the money is back in your wallet. Reason: :reason',
    'wallet_withdrawal_review_title' => 'New withdrawal request',
    'wallet_withdrawal_review_body' => ':name requested a withdrawal of :amount. It is waiting for review.',

    // -------------------------------------------------------------- wallet: admin actions
    'wallet_adjusted_credit_title' => 'Balance added to your wallet',
    'wallet_adjusted_credit_body' => ':amount was added to your wallet by the administration. Reason: :reason',
    'wallet_adjusted_debit_title' => 'Balance deducted from your wallet',
    'wallet_adjusted_debit_body' => ':amount was deducted from your wallet by the administration. Reason: :reason',

];
