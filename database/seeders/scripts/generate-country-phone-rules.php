<?php

declare(strict_types=1);

/**
 * One-off generator for database/seeders/data/country-phone-rules.json
 * Run: php database/seeders/scripts/generate-country-phone-rules.php
 */

$examplesPath = $argv[1] ?? null;
$lengthsPath = $argv[2] ?? null;

$examples = json_decode((string) file_get_contents(
    $examplesPath ?? 'https://unpkg.com/libphonenumber-js@1.11.17/examples.mobile.json'
), true, flags: JSON_THROW_ON_ERROR);

$lengthRows = json_decode((string) file_get_contents(
    $lengthsPath ?? 'https://raw.githubusercontent.com/chintanjoshi01/country-code-data/main/country_codes_data.json'
), true, flags: JSON_THROW_ON_ERROR);

/** @var array<string, int> $lengthByCode */
$lengthByCode = [];
foreach ($lengthRows as $row) {
    $lengthByCode[strtoupper((string) $row['code'])] = (int) $row['mobile_number_length'];
}

/** @var array<string, array{phone_starts_with: string, phone_length: int}> $overrides */
$overrides = [
    'EG' => ['phone_starts_with' => '1', 'phone_length' => 10],
    'SA' => ['phone_starts_with' => '5', 'phone_length' => 9],
    'AE' => ['phone_starts_with' => '5', 'phone_length' => 9],
    'GB' => ['phone_starts_with' => '7', 'phone_length' => 10],
    'US' => ['phone_starts_with' => '2', 'phone_length' => 10],
    'CA' => ['phone_starts_with' => '5', 'phone_length' => 10],
];

/** @var array<string, string> $territoryFallbacks */
$territoryFallbacks = [
    'AX' => 'FI',
    'BV' => 'NO',
    'HM' => 'AU',
    'TF' => 'FR',
    'UM' => 'US',
    'SJ' => 'NO',
    'CC' => 'AU',
    'CX' => 'AU',
    'NF' => 'AU',
    'IO' => 'GB',
    'EH' => 'MA',
    'XK' => 'RS',
    'BL' => 'FR',
    'MF' => 'FR',
    'GP' => 'FR',
    'GF' => 'FR',
    'MQ' => 'FR',
    'RE' => 'FR',
    'YT' => 'FR',
    'PM' => 'FR',
    'NC' => 'FR',
    'PF' => 'FR',
    'WF' => 'FR',
    'PR' => 'US',
    'VI' => 'US',
    'GU' => 'US',
    'AS' => 'US',
    'MP' => 'US',
    'BQ' => 'NL',
    'CW' => 'NL',
    'SX' => 'NL',
    'AW' => 'NL',
    'AI' => 'AG',
    'BM' => 'GB',
    'KY' => 'GB',
    'VG' => 'GB',
    'FK' => 'GB',
    'GI' => 'GB',
    'GG' => 'GB',
    'IM' => 'GB',
    'JE' => 'GB',
    'MS' => 'GB',
    'PN' => 'GB',
    'SH' => 'GB',
    'GS' => 'GB',
    'TC' => 'GB',
    'HK' => 'CN',
    'MO' => 'CN',
];

$countries = json_decode(
    (string) file_get_contents(__DIR__.'/../data/countries.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);

$rules = [];

foreach ($countries as $country) {
    $code = strtoupper(trim((string) ($country['iso2'] ?? '')));

    if ($code === '') {
        continue;
    }

    if (isset($overrides[$code])) {
        $rules[$code] = $overrides[$code];

        continue;
    }

    $sourceCode = $code;
    while (! isset($examples[$sourceCode]) && isset($territoryFallbacks[$sourceCode])) {
        $sourceCode = $territoryFallbacks[$sourceCode];
    }

    $example = $examples[$sourceCode] ?? $examples[$code] ?? null;

    if ($example !== null && $example !== '') {
        $phoneStartsWith = substr($example, 0, 1);
    } elseif (isset($examples[$sourceCode])) {
        $phoneStartsWith = substr((string) $examples[$sourceCode], 0, 1);
    } else {
        $phoneStartsWith = '0';
    }

    if (isset($lengthByCode[$code])) {
        $phoneLength = $lengthByCode[$code];
    } elseif (isset($lengthByCode[$sourceCode])) {
        $phoneLength = $lengthByCode[$sourceCode];
    } elseif ($example !== null && $example !== '') {
        $phoneLength = strlen($example);
    } else {
        $phoneLength = 10;
    }

    $phoneLength = max(5, min(15, $phoneLength));

    $rules[$code] = [
        'phone_starts_with' => $phoneStartsWith,
        'phone_length' => $phoneLength,
    ];
}

ksort($rules);

$outputPath = __DIR__.'/../data/country-phone-rules.json';
file_put_contents(
    $outputPath,
    json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL,
);

echo 'Generated '.count($rules).' country phone rules to '.$outputPath.PHP_EOL;
