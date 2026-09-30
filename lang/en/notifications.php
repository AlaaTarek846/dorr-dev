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
    'phone_changed_title' => 'Phone number changed',
    'phone_changed_body' => 'Your account phone number was changed to :phone. If this was not you, contact support right away.',
    'wallet_pin_changed_title' => 'Wallet PIN changed',
    'wallet_pin_changed_body' => 'Your wallet PIN was changed. If this was not you, contact support right away.',
    'wallet_pin_locked_title' => 'Wallet temporarily locked',
    'wallet_pin_locked_body' => 'Too many wrong PIN attempts. Try again in :minutes minutes. One more wrong attempt will freeze your wallet permanently. If this was not you, contact support.',
    'wallet_pin_frozen_title' => 'Your wallet is permanently frozen',
    'wallet_pin_frozen_body' => 'A wrong PIN attempt right after a temporary lock has frozen your wallet permanently. Take a selfie and upload an ID photo in the app for support to review.',

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

    // -------------------------------------------------------------- wallet: PIN recovery
    'wallet_recovery_set_title' => 'PIN recovery method set',
    'wallet_recovery_set_password_body' => 'If you forget your wallet PIN you can get it back with your password. If this was not you, contact support.',
    'wallet_recovery_set_birth_date_body' => 'If you forget your wallet PIN you can get it back with your date of birth. If this was not you, contact support.',
    'wallet_recovery_set_id_photo_body' => 'If you forget your wallet PIN you can get it back with your ID photo. If this was not you, contact support.',
    'wallet_recovery_set_passport_photo_body' => 'If you forget your wallet PIN you can get it back with your passport photo. If this was not you, contact support.',
    'wallet_recovery_set_email_body' => 'If you forget your wallet PIN you can get it back with your e-mail. If this was not you, contact support.',
    'wallet_pin_recovered_title' => 'Wallet PIN reset',
    'wallet_pin_recovered_body' => 'Your wallet PIN was reset using your recovery method. If this was not you, contact support right away.',
    'wallet_recovery_requested_title' => 'PIN recovery request received',
    'wallet_recovery_requested_body' => 'We received your request to recover your wallet PIN. It is being reviewed and you will be notified of the result.',
    'wallet_recovery_review_title' => 'New PIN recovery request',
    'wallet_recovery_review_body' => ':name asked to recover their wallet PIN with a document. It is waiting for review.',
    'wallet_recovery_approved_title' => 'PIN recovery approved',
    'wallet_recovery_approved_body' => 'Your PIN recovery request was approved. Your wallet PIN is now :pin (four zeros) — you will be asked to choose a new one when you open the wallet.',
    'wallet_recovery_rejected_title' => 'PIN recovery request rejected',
    'wallet_recovery_rejected_body' => 'Your PIN recovery request was rejected. Reason: :reason',
    'wallet_security_requested_title' => 'Identity check received',
    'wallet_security_requested_body' => 'We received your ID photo and selfie. Support will verify your identity as soon as possible.',
    'wallet_security_review_title' => 'Identity check for a frozen wallet',
    'wallet_security_review_body' => ":name's wallet was permanently frozen after wrong PIN attempts, and they submitted an ID photo and a selfie for review.",
    'wallet_security_approved_title' => 'Your identity has been verified',
    'wallet_security_approved_body' => 'Your identity has been verified, and your PIN is now :pin. You will be asked to choose a new one when you open the wallet.',
    // -------------------------------------------------------------- Chat subscriptions
    'ai_subscription_subscribed_title' => 'Subscribed successfully',
    'ai_subscription_subscribed_body' => 'You are now subscribed to the :plan plan.',
    'ai_subscription_plan_changed_title' => 'Plan changed',
    'ai_subscription_plan_changed_body' => 'Your subscription was switched to the :plan plan.',
    'ai_subscription_renewed_title' => 'Your subscription was renewed',
    'ai_subscription_renewed_body' => 'Your :plan subscription was renewed successfully.',
    'ai_subscription_renewal_reminder_title' => 'Your subscription renews soon',
    'ai_subscription_renewal_reminder_body' => 'Your :plan subscription renews in about a day for :price :currency - make sure your wallet balance is enough.',
    'ai_subscription_renewal_failed_title' => 'Subscription renewal failed',
    'ai_subscription_renewal_failed_body' => 'Your wallet balance was not enough to renew your :plan plan. You have :days days of grace to top up before your subscription is suspended.',
    'ai_subscription_grace_ending_soon_title' => 'Your grace period is ending soon',
    'ai_subscription_grace_ending_soon_body' => 'One day left in the grace period for your :plan subscription - top up your wallet now to keep it active.',
    'ai_subscription_suspended_title' => 'Your subscription was suspended',
    'ai_subscription_suspended_body' => 'Your :plan subscription was suspended after the grace period ended with an insufficient balance. Top up your wallet and subscribe again.',
    'ai_subscription_expired_title' => 'Your subscription has ended',
    'ai_subscription_expired_body' => 'Your :plan subscription has ended. Subscribe again to keep using it.',
    'ai_subscription_auto_renew_enabled_title' => 'Auto-renewal turned on',
    'ai_subscription_auto_renew_enabled_body' => 'Your subscription will now renew automatically from your wallet when it ends.',
    'ai_subscription_auto_renew_disabled_title' => 'Auto-renewal turned off',
    'ai_subscription_auto_renew_disabled_body' => 'Your subscription stays active until it ends, and will no longer renew automatically.',
    'ai_subscription_admin_adjusted_title' => 'Your subscription was updated',
    'ai_subscription_admin_adjusted_extended_body' => 'Your :plan subscription was extended by our team.',
    'ai_subscription_admin_adjusted_suspended_body' => 'Your :plan subscription was suspended by our team.',
    'ai_subscription_admin_adjusted_reactivated_body' => 'Your :plan subscription was reactivated by our team.',
    'ai_subscription_admin_adjusted_cancelled_body' => 'Your :plan subscription was cancelled by our team.',

];
