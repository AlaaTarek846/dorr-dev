<?php

namespace App\Enums;

enum TranslationFileStatus: string
{
    /** A draft is waiting to be published (a previous version may still be live). */
    case Draft = 'draft';

    /** The live file is current and there is no pending draft. */
    case Published = 'published';
}
