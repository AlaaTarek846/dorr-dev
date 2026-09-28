<?php

return [

    'providers' => [
        'created' => 'تم إنشاء مزود SMS بنجاح.',
        'fetched' => 'تم جلب مزود SMS بنجاح.',
        'updated' => 'تم تحديث مزود SMS بنجاح.',
        'deleted' => 'تم حذف مزود SMS بنجاح.',
        'activated' => 'تم تفعيل مزود SMS بنجاح.',
        'deactivated' => 'تم إيقاف مزود SMS بنجاح.',
        'ready' => 'المزود جاهز.',
        'not_ready' => 'المزود غير جاهز. تأكد من أن المزود مفعّل وأن محوّل الخاص به مسجّل.',
        'in_use' => 'لا يمكن حذف مزود SMS لوجود حسابات مرتبطة به.',
        'unsupported' => 'مزود SMS غير مدعوم: :key',

        'connection_successful' => 'تم الاتصال بنجاح.',
        'connection_failed' => 'فشل الاتصال.',
        'missing_configuration' => 'الحقول المطلوبة غير مكتملة: :fields',

        'name_required' => 'الاسم مطلوب.',
        'key_required' => 'المزود مطلوب.',
        'unknown_provider' => 'مزود SMS غير معروف.',
        'already_registered' => 'مزود SMS هذا مسجّل بالفعل.',

        'test_errors' => [
            'required_credentials' => 'بيانات الاعتماد مطلوبة لـ :provider',
            'sms_misr' => [
                'sent' => 'SMS Misr: تم إرسال الرسالة بنجاح',
                'insufficient_balance' => 'رصيد غير كافٍ',
                'invalid_credentials' => 'اسم المستخدم أو كلمة المرور غير صحيحة',
                'sender_not_approved' => 'المرسل غير معتمد',
                'invalid_sender' => 'مرسل غير صحيح',
                'invalid_mobile' => 'رقم جوال غير صحيح',
                'message_too_long' => 'الرسالة طويلة جداً أو فارغة',
                'invalid_language' => 'معامل اللغة غير صحيح',
                'invalid_environment' => 'يجب أن تكون قيمة البيئة 1 أو 2',
                'invalid_request' => 'معاملات الطلب غير صحيحة',
                'server_updating' => 'المزود قيد التحديث مؤقتاً، حاول مرة أخرى',
                'invalid_delay' => 'صيغة تاريخ التأجيل غير صحيحة',
                'invalid_message' => 'محتوى الرسالة غير صحيح',
                'generic' => 'أرجع المزود الرمز :code',
            ],
        ],
    ],

    'accounts' => [
        'created' => 'تم إنشاء حساب SMS بنجاح.',
        'fetched' => 'تم جلب حساب SMS بنجاح.',
        'updated' => 'تم تحديث حساب SMS بنجاح.',
        'deleted' => 'تم حذف حساب SMS بنجاح.',
        'default_set' => 'تم تعيين حساب SMS الافتراضي بنجاح.',
        'activated' => 'تم تفعيل حساب SMS بنجاح.',
        'deactivated' => 'تم إيقاف حساب SMS بنجاح.',

        'name_required' => 'الاسم مطلوب.',
        'provider_required' => 'المزود مطلوب.',
        'provider_not_found' => 'المزود المحدد غير موجود.',
        'invalid_sender_type' => 'نوع المرسل غير صحيح.',
        'missing_configuration' => 'إعدادات مطلوبة غير مكتملة: :fields',

        'provider_inactive' => 'مزود هذا الحساب غير مفعّل. قم بتفعيل المزود أولاً.',
        'not_configured' => 'لا توجد إعدادات لهذا الحساب. أدخل بيانات الاعتماد أولاً.',
        'none_active' => 'لا يوجد حساب SMS مفعّل. أنشئ حساباً وفعّله أولاً.',
        'account_unavailable' => 'حساب SMS هذا غير جاهز. يجب أن يكون مفعّلاً، وأن ينجح اختباره، وأن يكون مزوده مفعّلاً.',
        'unsupported_provider' => 'مزود SMS غير مدعوم: :key',

        'connection_successful' => 'تم الاتصال بنجاح.',
        'connection_failed' => 'فشل الاتصال.',
        'balance_not_supported' => 'هذا المزود لا يوفر واجهة برمجية للرصيد.',
        'balance_retrieved' => 'تم جلب الرصيد.',
        'balance_failed' => 'فشل جلب الرصيد.',

        'test_number_required' => 'رقم الهاتف التجريبي مطلوب.',
        'test_sent' => 'تم إرسال رسالة SMS تجريبية.',
        'test_failed' => 'تعذّر إرسال رسالة SMS تجريبية.',
        'default_test_message' => 'رسالة تجريبية',
        'live_test_sent' => 'لا توجد بيئة اختبار مهيأة: تم إرسال رسالة تجريبية فعلية.',

        'body_required' => 'نص الرسالة لا يمكن أن يكون فارغاً.',

        'country_required' => 'يجب اختيار الدولة لتنسيق رقم الهاتف.',
        'country_not_found' => 'الدولة المحددة غير موجودة.',
        'country_mismatch' => 'الرقم :phone لا يخص الدولة المحددة. اختر الدولة المناسبة أو أدخل رقماً محلياً.',
        'country_missing_dial_code' => 'الدولة المحددة لا تحتوي على رمز الاتصال الدولي.',
        'invalid_recipient' => 'رقم الهاتف ":phone" غير صحيح.',
        'invalid_recipient_length' => 'رقم الهاتف ":phone" غير صحيح للدولة المحددة. يجب أن يكون :length أرقام ويبدأ بالرقم :starts_with.',

        'test_status' => [
            'never_tested' => 'لم يتم الاختبار',
            'passed' => 'نجح',
            'failed' => 'فشل',
        ],

        'sender_type' => [
            'number' => 'رقم هاتف',
            'alphanumeric' => 'اسم مرسل أبجدوي رقمي',
        ],
    ],
];
