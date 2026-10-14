<?php

namespace Modules\Rides\Support;

use DateTimeInterface;
use DateTimeZone;
use IntlCalendar;
use IntlDateFormatter;

/**
 * Umm al-Qura dates for Wasl (the birth date is sent in Hijri, the vehicle licence expiry comes back
 * in Hijri). PHP's intl does the calendar — Jawad used a package for the same conversion.
 */
final class Hijri
{
    private const CALENDAR = 'en_US@calendar=islamic-umalqura';

    /** 1990-05-15 → "1410/10/20" (yyyy/MM/dd, as Wasl's dateOfBirthHijri). */
    public static function fromGregorian(DateTimeInterface $date): string
    {
        $formatter = new IntlDateFormatter(self::CALENDAR, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::TRADITIONAL, 'yyyy/MM/dd');

        return (string) $formatter->format(new \DateTimeImmutable($date->format('Y-m-d'), new DateTimeZone('UTC')));
    }

    /**
     * A Hijri date from Wasl to a Gregorian "Y-m-d". Accepts "dd/MM/yyyy" (what Wasl returns, as
     * Jawad reads it) and "yyyy/MM/dd"; null when it can't be read.
     */
    public static function toGregorian(?string $hijri): ?string
    {
        if ($hijri === null || ! preg_match('#^(\d{1,4})[/-](\d{1,2})[/-](\d{1,4})#', trim($hijri), $m)) {
            return null;
        }
        [$year, $month, $day] = strlen($m[1]) === 4 ? [(int) $m[1], (int) $m[2], (int) $m[3]] : [(int) $m[3], (int) $m[2], (int) $m[1]];
        if ($year < 1300 || $month < 1 || $month > 12 || $day < 1 || $day > 30) {
            return null;
        }

        $calendar = IntlCalendar::createInstance('UTC', self::CALENDAR);
        $calendar->clear();
        $calendar->set($year, $month - 1, $day, 12, 0, 0);

        return gmdate('Y-m-d', (int) ($calendar->getTime() / 1000));
    }
}
