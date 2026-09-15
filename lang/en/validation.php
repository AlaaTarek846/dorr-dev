<?php

return [
    'accepted' => 'The :attribute field must be accepted.',
    'array' => 'The :attribute field must be an array.',
    'boolean' => 'The :attribute field must be true or false.',
    'distinct' => 'The :attribute field has a duplicate value.',
    'exists' => 'The selected :attribute is invalid.',
    'integer' => 'The :attribute field must be an integer.',
    'max' => [
        'array' => 'The :attribute field must not have more than :max items.',
        'file' => 'The :attribute field must not be greater than :max kilobytes.',
        'numeric' => 'The :attribute field must not be greater than :max.',
        'string' => 'The :attribute field must not be greater than :max characters.',
    ],
    'image' => 'The :attribute field must be an image.',
    'mimes' => 'The :attribute field must be a file of type: :values.',
    'min' => [
        'array' => 'The :attribute field must have at least :min items.',
    ],
    'required' => 'The :attribute field is required.',
    'string' => 'The :attribute field must be a string.',
    'unique' => 'The :attribute has already been taken.',

    'custom' => [
        'code' => [
            'unique' => 'This code is already in use.',
        ],
        'translations' => [
            'required' => 'At least one translation is required.',
        ],
    ],

    'attributes' => [
        'avatar' => 'avatar',
        'name' => 'name',
        'email' => 'email',
        'phone' => 'phone',
        'gender' => 'gender',
        'country_id' => 'country',
        'password' => 'password',
        'current_password' => 'current password',
        'code' => 'code',
        'status' => 'status',
        'translations' => 'translations',
        'translations.*.locale' => 'locale',
        'translations.*.name' => 'name',
        'ids' => 'selected items',
        'ids.*' => 'selected item',
        'app_name' => 'app name',
        'logo' => 'logo',
        'logo_dark' => 'logo dark',
        'favicon_ico' => 'favicon.ico',
        'favicon_16' => '16×16 icon',
        'favicon_32' => '32×32 icon',
        'apple_touch_icon' => 'Apple touch icon',
        'web_manifest' => 'web manifest',
    ],
];
