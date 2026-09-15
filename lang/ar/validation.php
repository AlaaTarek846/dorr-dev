<?php

return [
    'accepted' => 'يجب قبول حقل :attribute.',
    'array' => 'يجب أن يكون حقل :attribute مصفوفة.',
    'boolean' => 'يجب أن يكون حقل :attribute صحيحاً أو خاطئاً.',
    'distinct' => 'حقل :attribute يحتوي على قيمة مكررة.',
    'exists' => 'القيمة المحددة في :attribute غير صالحة.',
    'integer' => 'يجب أن يكون حقل :attribute رقماً صحيحاً.',
    'max' => [
        'array' => 'يجب ألا يحتوي حقل :attribute على أكثر من :max عناصر.',
        'file' => 'يجب ألا يتجاوز حقل :attribute :max كيلوبايت.',
        'numeric' => 'يجب ألا يكون حقل :attribute أكبر من :max.',
        'string' => 'يجب ألا يتجاوز حقل :attribute :max حرفاً.',
    ],
    'image' => 'يجب أن يكون حقل :attribute صورة.',
    'mimes' => 'يجب أن يكون حقل :attribute ملفاً من نوع: :values.',
    'min' => [
        'array' => 'يجب أن يحتوي حقل :attribute على :min عناصر على الأقل.',
    ],
    'required' => 'حقل :attribute مطلوب.',
    'string' => 'يجب أن يكون حقل :attribute نصاً.',
    'unique' => 'قيمة :attribute مستخدمة بالفعل.',

    'custom' => [
        'code' => [
            'unique' => 'هذا الكود مستخدم بالفعل.',
        ],
        'translations' => [
            'required' => 'مطلوب إدخال ترجمة واحدة على الأقل.',
        ],
    ],

    'attributes' => [
        'avatar' => 'الصورة',
        'name' => 'الاسم',
        'email' => 'البريد الإلكتروني',
        'phone' => 'الهاتف',
        'gender' => 'الجنس',
        'country_id' => 'الدولة',
        'password' => 'كلمة المرور',
        'current_password' => 'كلمة المرور الحالية',
        'code' => 'الكود',
        'status' => 'الحالة',
        'translations' => 'الترجمات',
        'translations.*.locale' => 'اللغة',
        'translations.*.name' => 'الاسم',
        'ids' => 'العناصر المحددة',
        'ids.*' => 'العنصر المحدد',
    ],
];
