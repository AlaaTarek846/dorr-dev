<?php

/**
 * نصوص الإشعارات — العربية (ar)
 *
 * بتتنادى بالمفتاح من sendNotification() / NotificationCenter، وبتتحوّل للغة القارئ (القائمة داخل
 * التطبيق) أو المستلم (الإشعار اللحظي) أو الجهاز (الـ push). لإضافة لغة جديدة: أنشئ
 * lang/{code}/notifications.php بنفس المفاتيح وضيف الكود في App\Support\LocaleResolver::supported()
 * — وأي مفتاح ناقص بيرجع للإنجليزي.
 *
 * التسمية: {event}_title / {event}_body. المتغيرات بصيغة :variable.
 */
return [

    // -------------------------------------------------------------- المحفظة: الشحن
    'wallet_topup_paid_title' => 'تم شحن محفظتك',
    'wallet_topup_paid_body' => 'تمت إضافة :amount إلى محفظتك.',
    'wallet_topup_paid_bonus_body' => 'تمت إضافة :amount إلى محفظتك، بالإضافة إلى هدية :bonus (للخدمات فقط).',
    'wallet_topup_failed_title' => 'لم تكتمل عملية الشحن',
    'wallet_topup_failed_body' => 'لم تكتمل عملية شحن :amount ولم تتم إضافة أي مبلغ إلى محفظتك. يمكنك المحاولة مرة أخرى.',
    'wallet_topup_refunded_title' => 'تم إلغاء عملية الشحن',
    'wallet_topup_refunded_body' => 'تم إلغاء عملية شحن :amount وخصمها من محفظتك.',

    // -------------------------------------------------------------- المحفظة: التحويلات
    'wallet_transfer_sent_title' => 'تم التحويل',
    'wallet_transfer_sent_body' => 'حوّلت :amount إلى :name.',
    'wallet_transfer_received_title' => 'استلمت تحويلاً',
    'wallet_transfer_received_body' => 'أرسل لك :name مبلغ :amount. يمكنك استخدامه في الخدمات.',

    // -------------------------------------------------------------- المحفظة: الرقم السري
    'wallet_pin_created_title' => 'تم إنشاء الرقم السري للمحفظة',
    'wallet_pin_created_body' => 'تم تعيين رقم سري لمحفظتك. إذا لم تكن أنت من قام بذلك فتواصل مع الدعم فوراً.',
    'wallet_pin_changed_title' => 'تم تغيير الرقم السري للمحفظة',
    'wallet_pin_changed_body' => 'تم تغيير الرقم السري لمحفظتك. إذا لم تكن أنت من قام بذلك فتواصل مع الدعم فوراً.',
    'wallet_pin_locked_title' => 'تم قفل المحفظة مؤقتاً',
    'wallet_pin_locked_body' => 'محاولات خاطئة كثيرة للرقم السري. حاول مرة أخرى بعد :minutes دقيقة. وإذا لم تكن أنت فتواصل مع الدعم.',

    // -------------------------------------------------------------- المحفظة: السحب
    'wallet_withdrawal_requested_title' => 'تم استلام طلب السحب',
    'wallet_withdrawal_requested_body' => 'طلب سحب :amount قيد المراجعة.',
    'wallet_withdrawal_paid_title' => 'تم تحويل مبلغ السحب',
    'wallet_withdrawal_paid_body' => 'تم تحويل مبلغ السحب :amount. الإيصال متاح في التطبيق.',
    'wallet_withdrawal_rejected_title' => 'تم رفض طلب السحب',
    'wallet_withdrawal_rejected_body' => 'تم رفض طلب سحب :amount وأُعيد المبلغ إلى محفظتك. السبب: :reason',
    'wallet_withdrawal_review_title' => 'طلب سحب جديد',
    'wallet_withdrawal_review_body' => 'طلب :name سحب :amount، وهو بانتظار المراجعة.',

    // -------------------------------------------------------------- المحفظة: إجراءات الإدارة
    'wallet_adjusted_credit_title' => 'تمت إضافة رصيد إلى محفظتك',
    'wallet_adjusted_credit_body' => 'أضافت الإدارة :amount إلى محفظتك. السبب: :reason',
    'wallet_adjusted_debit_title' => 'تم خصم رصيد من محفظتك',
    'wallet_adjusted_debit_body' => 'خصمت الإدارة :amount من محفظتك. السبب: :reason',

];
