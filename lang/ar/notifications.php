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
    'phone_changed_title' => 'تم تغيير رقم الجوال',
    'phone_changed_body' => 'تم تغيير رقم الجوال المرتبط بحسابك إلى :phone. إذا لم تكن أنت من قام بذلك فتواصل مع الدعم فوراً.',
    'wallet_pin_changed_title' => 'تم تغيير الرقم السري للمحفظة',
    'wallet_pin_changed_body' => 'تم تغيير الرقم السري لمحفظتك. إذا لم تكن أنت من قام بذلك فتواصل مع الدعم فوراً.',
    'wallet_pin_locked_title' => 'تم قفل المحفظة مؤقتاً',
    'wallet_pin_locked_body' => 'محاولات خاطئة كثيرة للرقم السري. حاول مرة أخرى بعد :minutes دقيقة. محاولة خاطئة أخرى ستقفل محفظتك بشكل دائم. وإذا لم تكن أنت فتواصل مع الدعم.',
    'wallet_pin_frozen_title' => 'تم قفل محفظتك بشكل دائم',
    'wallet_pin_frozen_body' => 'محاولة خاطئة أخرى للرقم السري بعد القفل المؤقت أدت لقفل محفظتك بشكل دائم. صوّر نفسك وارفع صورة هويتك من داخل التطبيق لمراجعة الدعم.',

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

    // -------------------------------------------------------------- المحفظة: استرجاع الرقم السري
    'wallet_recovery_set_title' => 'تم تحديد وسيلة استرجاع الرقم السري',
    'wallet_recovery_set_password_body' => 'إذا نسيت الرقم السري لمحفظتك يمكنك استرجاعه عن طريق كلمة المرور. وإذا لم تكن أنت من قام بذلك فتواصل مع الدعم.',
    'wallet_recovery_set_birth_date_body' => 'إذا نسيت الرقم السري لمحفظتك يمكنك استرجاعه عن طريق تاريخ الميلاد. وإذا لم تكن أنت من قام بذلك فتواصل مع الدعم.',
    'wallet_recovery_set_id_photo_body' => 'إذا نسيت الرقم السري لمحفظتك يمكنك استرجاعه عن طريق صورة الهوية. وإذا لم تكن أنت من قام بذلك فتواصل مع الدعم.',
    'wallet_recovery_set_passport_photo_body' => 'إذا نسيت الرقم السري لمحفظتك يمكنك استرجاعه عن طريق صورة جواز السفر. وإذا لم تكن أنت من قام بذلك فتواصل مع الدعم.',
    'wallet_recovery_set_email_body' => 'إذا نسيت الرقم السري لمحفظتك يمكنك استرجاعه عن طريق البريد الإلكتروني. وإذا لم تكن أنت من قام بذلك فتواصل مع الدعم.',
    'wallet_pin_recovered_title' => 'تمت إعادة تعيين الرقم السري للمحفظة',
    'wallet_pin_recovered_body' => 'تمت إعادة تعيين الرقم السري لمحفظتك باستخدام وسيلة الاسترجاع. وإذا لم تكن أنت من قام بذلك فتواصل مع الدعم فوراً.',
    'wallet_recovery_requested_title' => 'تم استلام طلب استرجاع الرقم السري',
    'wallet_recovery_requested_body' => 'استلمنا طلبك لاسترجاع الرقم السري للمحفظة. الطلب قيد المراجعة وسيصلك إشعار بالنتيجة.',
    'wallet_recovery_review_title' => 'طلب استرجاع رقم سري جديد',
    'wallet_recovery_review_body' => 'طلب :name استرجاع الرقم السري لمحفظته بمستند، وهو بانتظار المراجعة.',
    'wallet_recovery_approved_title' => 'تمت الموافقة على طلب استرجاع الرقم السري',
    'wallet_recovery_approved_body' => 'تمت الموافقة على طلب استرجاع الرقم السري. الرقم السري الخاص بك الآن :pin (أربعة أصفار) — سيُطلب منك اختيار رقم سري جديد عند فتح المحفظة.',
    'wallet_recovery_rejected_title' => 'تم رفض طلب استرجاع الرقم السري',
    'wallet_recovery_rejected_body' => 'تم رفض طلب استرجاع الرقم السري. السبب: :reason',
    'wallet_security_requested_title' => 'تم استلام طلب التحقق من الهوية',
    'wallet_security_requested_body' => 'استلمنا صورة هويتك وصورتك الشخصية. سيقوم الدعم بالتحقق من هويتك في أقرب وقت.',
    'wallet_security_review_title' => 'طلب تحقق هوية لمحفظة مقفلة',
    'wallet_security_review_body' => 'محفظة :name اتقفلت بشكل دائم بعد محاولات خاطئة للرقم السري، وقدّم صاحبها صورة هويته وصورة شخصية للمراجعة.',
    'wallet_security_approved_title' => 'تم التحقق من هويتك',
    'wallet_security_approved_body' => 'لقد تم التحقق من هويتك، ورمز الـPIN الخاص بك هو :pin. سيُطلب منك اختيار رقم سري جديد عند فتح المحفظة.',

    // -------------------------------------------------------------- تذاكر الدعم
    'support_ticket_new_title' => 'تذكرة دعم جديدة',
    'support_ticket_new_body' => 'فتح :name التذكرة رقم :id.',
    'support_ticket_customer_reply_title' => 'رد من العميل',
    'support_ticket_customer_reply_body' => 'رد :name على التذكرة رقم :id.',
    'support_ticket_reply_title' => 'رد من الدعم',
    'support_ticket_reply_body' => 'لديك رد جديد على تذكرتك "#:id :title".',
    'support_ticket_status_title' => 'تم تحديث تذكرتك',
    'support_ticket_status_opened_body' => 'التذكرة #:id ":title" مفتوحة.',
    'support_ticket_status_reopened_body' => 'تمت إعادة فتح التذكرة #:id ":title".',
    'support_ticket_status_resolved_body' => 'تم تحديد التذكرة #:id ":title" كمحلولة. أعد فتحها إن كنت ما زلت بحاجة للمساعدة.',
    'support_ticket_status_closed_body' => 'تم إغلاق التذكرة #:id ":title".',
    'support_ticket_customer_status_title' => 'تغيرت حالة تذكرة',
    'support_ticket_customer_status_opened_body' => 'فتح :name التذكرة رقم :id.',
    'support_ticket_customer_status_reopened_body' => 'أعاد :name فتح التذكرة رقم :id.',
    'support_ticket_customer_status_resolved_body' => 'حدد :name التذكرة رقم :id كمحلولة.',
    'support_ticket_customer_status_closed_body' => 'أغلق :name التذكرة رقم :id.',

];
