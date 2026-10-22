<?php

namespace Modules\Freelance\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Freelance\Models\FreelanceDocumentType;

/**
 * FL-107: the usual identity documents, ready to be made required per country from the admin screen. It requires nothing
 * by itself (which country asks for what is the admin's call), only adds what is missing, and leaves an admin's edits alone.
 */
class FreelanceDocumentTypeSeeder extends Seeder
{
    /** @var list<array{code: string, en: string, ar: string, number: bool, expiry: bool, back: bool}> */
    private const TYPES = [
        ['code' => 'national_id', 'en' => 'National ID', 'ar' => 'الهوية الوطنية', 'number' => true, 'expiry' => true, 'back' => true],
        ['code' => 'residence_permit', 'en' => 'Residence permit (Iqama)', 'ar' => 'الإقامة', 'number' => true, 'expiry' => true, 'back' => true],
        ['code' => 'passport', 'en' => 'Passport', 'ar' => 'جواز السفر', 'number' => true, 'expiry' => true, 'back' => false],
        ['code' => 'selfie_with_id', 'en' => 'Selfie holding the ID', 'ar' => 'صورة شخصية مع الهوية', 'number' => false, 'expiry' => false, 'back' => false],
    ];

    public function run(): void
    {
        foreach (self::TYPES as $i => $t) {
            if (FreelanceDocumentType::withTrashed()->where('code', $t['code'])->exists()) {
                continue;
            }
            $type = new FreelanceDocumentType(['code' => $t['code'], 'has_number' => $t['number'], 'has_expiry' => $t['expiry'], 'needs_back_side' => $t['back'], 'status' => true, 'sort_order' => $i + 1]);
            $type->fillAllTranslations(['name' => ['en' => $t['en'], 'ar' => $t['ar']]])->save();
        }
    }
}
