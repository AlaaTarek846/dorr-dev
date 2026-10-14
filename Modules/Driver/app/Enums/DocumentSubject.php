<?php

namespace Modules\Driver\Enums;

/** Whom a document type belongs to. */
enum DocumentSubject: string
{
    case Driver = 'driver';
    case Vehicle = 'vehicle';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
