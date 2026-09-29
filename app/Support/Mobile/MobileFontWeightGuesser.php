<?php

namespace App\Support\Mobile;

class MobileFontWeightGuesser
{
    public static function fromFileName(string $fileName): string
    {
        $base = pathinfo($fileName, PATHINFO_FILENAME);
        $base = str_replace(['_', ' '], '-', $base);
        $parts = explode('-', $base);
        $last = strtolower(end($parts) ?: 'regular');

        return match ($last) {
            'thin' => '100',
            'extralight', 'ultralight', 'extra-light' => '200',
            'light' => '300',
            'regular', 'normal', 'book' => '400',
            'medium' => '500',
            'semibold', 'semi-bold', 'demibold' => '600',
            'bold' => '700',
            'extrabold', 'extra-bold', 'ultra' => '800',
            'black', 'heavy' => '900',
            default => '400',
        };
    }
}
