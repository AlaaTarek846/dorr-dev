<?php

// أمان الحساب: الأجهزة المسجّل دخولها وسجل الأمان (التطبيق + لوحة التحكم).
return [
    'unknown_device' => 'جهاز غير معروف',
    'signed_out' => 'تم تسجيل خروج الجهاز.',
    'signed_out_others' => 'تم تسجيل خروج :count من الأجهزة الأخرى.',

    'platforms' => [
        'android' => 'أندرويد',
        'ios' => 'آيفون',
        'web' => 'ويب',
        'unknown' => 'غير معروف',
    ],

    'flows' => [
        'login' => 'تسجيل الدخول',
        'restore' => 'استعادة الحساب',
        'resend' => 'إعادة إرسال الكود',
    ],

    'events' => [
        'login' => 'تسجيل دخول',
        'login_new_device' => 'تسجيل دخول من جهاز جديد',
        'login_failed' => 'كلمة مرور خاطئة',
        'otp_requested' => 'طلب كود دخول',
        'otp_failed' => 'كود دخول خاطئ',
        'otp_locked' => 'أكواد خاطئة كثيرة',
        'logout' => 'تسجيل خروج',
        'device_signed_out' => 'تسجيل خروج جهاز',
        'other_devices_signed_out' => 'تسجيل خروج الأجهزة الأخرى',
        'session_limit' => 'خروج أقدم جهاز (الحد الأقصى للأجهزة)',
        'admin_signed_out' => 'تسجيل خروج بواسطة الدعم',
        'phone_changed' => 'تغيير رقم الهاتف',
        'pin_failed' => 'رمز محفظة خاطئ',
        'pin_locked' => 'قفل المحفظة مؤقتًا',
        'pin_frozen' => 'تجميد المحفظة',
        'account_restored' => 'استعادة الحساب',
    ],

    'details' => [
        'otp_requested' => 'العملية: :flow',
        'device_signed_out' => 'الجهاز: :device',
        'other_devices_signed_out' => ':count جهاز',
        'session_limit' => 'الجهاز: :device',
        'admin_signed_out' => 'الجهاز: :device',
        'admin_signed_out_all' => ':count جهاز',
        'phone_changed' => 'من :from إلى :to',
        'pin_failed' => 'المحاولة :attempt',
        'pin_locked' => 'لمدة :minutes دقيقة',
    ],
];
