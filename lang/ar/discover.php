<?php

return [
    'errors' => [
        'off' => 'اكتشف الفعاليات مش متاح هنا لسه.',
        'not_found' => 'الفعالية دي مش متاحة.',
        'travel_range' => 'اختار لحد :max يوم.',
        'too_many_follows' => 'تقدر تتابع لحد :max مكان.',
        'invalid_status' => 'حالة الفعالية مش معروفة.',
        'invalid_time' => 'راجع وقت بداية ونهاية الفعالية.',
        'submissions_off' => 'إضافة الفعاليات مقفولة دلوقتي.',
        'organizer_suspended' => 'حساب المنظم بتاعك موقوف.',
        'not_organizer' => 'اطلب تبقى منظم الأول — وبعدها تقدر تضيف فعاليات.',
        'duplicate' => 'الفعالية دي موجودة بالفعل في اكتشف.',
        'ai_off' => 'اسأل DORR AI مش متاح في اكتشف دلوقتي.',
        'event_over' => 'الفعالية دي خلصت أو اتلغت.',
        'in_use' => 'فيه فعاليات مستخدماه — انقلها أو امسحها الأول.',
        'room_needs_members' => 'اختار شخص واحد على الأقل تروحوا سوا.',
    ],

    'push' => [
        'status' => [
            'confirmed' => 'اتأكدت — يوم :date الساعة :time',
            'postponed' => 'اتأجلت — هنقولك الميعاد الجديد',
            'moved' => 'ميعاد جديد: :date الساعة :time',
            'cancelled' => 'المنظم لغاها',
            'sold_out' => 'التذاكر خلصت',
        ],
        'review' => [
            'organizer_verified_title' => '✔ اتوثّقت كمنظّم',
            'organizer_verified_body' => 'تقدر تضيف فاعلياتك على DORR Discover دلوقتي.',
            'organizer_rejected_title' => 'طلبك كمنظّم اترفض',
            'organizer_rejected_body' => 'افتح صفحة المنظّم عشان تعرف التفاصيل.',
            'organizer_suspended_title' => 'حساب المنظّم بتاعك اتوقف',
            'organizer_suspended_body' => 'مش هتقدر تضيف فاعليات لحد ما يرجع.',
            'event_approved_title' => '✅ :title',
            'event_approved_body' => 'فاعليتك اتنشرت والناس تقدر تشوفها.',
            'event_rejected_title' => '❌ :title',
            'event_rejected_body' => 'فاعليتك اترفضت. افتحها عشان تعرف السبب.',
            'with_note' => 'ملاحظة الإدارة: :note',
        ],
        'alert_title' => '✨ متفوتهاش',
        'alert_many' => ':title و :count كمان ممكن يعجبوك',
    ],

    'poll' => [
        'question' => 'مين رايح ":title"؟',
        'yes' => 'رايح',
        'maybe' => 'يمكن',
        'no' => 'مش هقدر',
    ],
];
