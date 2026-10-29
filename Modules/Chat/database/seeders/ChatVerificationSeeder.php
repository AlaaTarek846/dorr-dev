<?php

namespace Modules\Chat\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Chat\Enums\VerificationTrack;
use Modules\Chat\Models\Verification\ChatVerificationDocumentType;
use Modules\Chat\Models\Verification\ChatVerificationSetting;

/**
 * Account verification's starting values (docs/verification_tasks.md, "القيم الافتراضية"): the settings
 * row (its column defaults) and the documents each track asks for. Only fills what is empty, so it never
 * overwrites what the admin changed.
 */
class ChatVerificationSeeder extends Seeder
{
    public function run(): void
    {
        ChatVerificationSetting::query()->firstOrCreate([]);

        if (ChatVerificationDocumentType::withTrashed()->exists()) {
            return;
        }

        $both = [VerificationTrack::Paid->value, VerificationTrack::Celebrity->value];
        $documents = [
            ['identity_document', $both, true, ['بطاقة الرقم القومي أو الباسبور', 'National ID or passport'],
                ['صورة واضحة للوش والضهر، والبيانات كلها باينة.', 'A clear photo of the front and back, with every detail readable.']],
            ['selfie_with_id', $both, false, ['سيلفي وانت ماسك البطاقة', 'Selfie holding your ID'],
                ['وشك والبطاقة باينين في نفس الصورة.', 'Your face and the ID both visible in the same photo.']],
            ['account_ownership', [VerificationTrack::Celebrity->value], false, ['إثبات ملكية الحسابات', 'Proof you own the accounts'],
                ['سكرين من لوحة تحكم كل حساب ذكرته في الطلب، وانت داخل بيه.', 'A screenshot of the dashboard of each account named in the request, signed in.']],
        ];

        foreach ($documents as $i => [$code, $tracks, $backSide, [$nameAr, $nameEn], [$hintAr, $hintEn]]) {
            $type = new ChatVerificationDocumentType(['code' => $code, 'tracks' => $tracks, 'is_required' => true, 'needs_back_side' => $backSide, 'status' => true, 'sort_order' => $i]);
            $type->fillAllTranslations([
                'name' => ['ar' => $nameAr, 'en' => $nameEn],
                'instructions' => ['ar' => $hintAr, 'en' => $hintEn],
            ])->save();
        }
    }
}
