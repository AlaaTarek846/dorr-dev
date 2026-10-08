<?php

namespace Modules\AI\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\AI\Models\AiUserLanguagePreference;

/**
 * Resolves the language/tone instruction that should be appended to the
 * system prompt for this owner, based on their
 * ai_user_language_preferences row. Owners never see or manage this
 * directly yet (no user-facing settings screen), so almost everyone is in
 * the default ("follow_input", auto-detect) mode.
 *
 * Root-cause fix: the default mode used to return null and leave
 * everything to the base system prompt's "reply in the same language the
 * user wrote in" line - that line only ever distinguishes Arabic from
 * English, never the REGISTER within a language, so a user who writes or
 * speaks casual Egyptian colloquial Arabic routinely got a reply in
 * formal/classical Arabic instead, which reads as "a weird/foreign
 * language" even though it is technically still Arabic - this is the
 * dialect-matching instruction AiLanguageVariant's "egyptian_arabic"
 * variant was clearly meant to drive, but could never reach, because it
 * was only ever wired into the FIXED-mode branch below that almost no one
 * is in. Voice messages make this worse (see
 * AiChatService::transcribeIncomingAudio()'s Whisper language hint fix)
 * because people speak far more colloquially than they type.
 */
class AiChatLanguageResolver
{
    /**
     * Applies to the default follow_input/auto_detect mode - i.e. almost
     * every owner - so the model matches the user's actual register
     * (formal vs. colloquial) and not just their language.
     */
    protected const AUTO_DETECT_INSTRUCTION = 'Match the exact register/dialect of the user\'s own last message, not just its '
        .'language: if they wrote or spoke in casual colloquial Arabic (e.g. Egyptian colloquial '
        .'"العامية المصرية", or Saudi/Gulf colloquial "اللهجة السعودية"), reply in natural colloquial Arabic '
        .'in that exact same dialect - do not switch to formal/classical Arabic ("الفصحى"), which reads '
        .'as stiff and foreign to a colloquial speaker. If they wrote in formal Arabic, reply formally. '
        .'Apply the same logic to English (casual vs. formal) and to any other language. A voice '
        .'message\'s transcript should be read the same way: match the spoken dialect, not an idealized '
        .'written form of the language.';

    public function resolve(Authenticatable $owner): ?string
    {
        $preference = AiUserLanguagePreference::query()
            ->with(['language.translations', 'variant'])
            ->firstOrCreate(
                ['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getAuthIdentifier()],
                ['auto_detect' => true, 'response_language_mode' => AiUserLanguagePreference::MODE_FOLLOW_INPUT],
            );

        if ($preference->response_language_mode !== AiUserLanguagePreference::MODE_FIXED || ! $preference->language) {
            return self::AUTO_DETECT_INSTRUCTION;
        }

        $instruction = "Always reply in {$preference->language->translatedName()} ({$preference->language->code}), regardless of the language the user writes in.";

        if ($preference->variant) {
            $instruction .= " Use a {$preference->variant->style} tone, matching the \"{$preference->variant->name}\" style.";
        } else {
            $instruction .= ' '.self::AUTO_DETECT_INSTRUCTION;
        }

        return $instruction;
    }

    /**
     * The ISO-639-1 code to hint OpenAI's Whisper transcription with for
     * this owner's voice messages (see
     * AiChatService::transcribeIncomingAudio()) - real, observed bug fix:
     * without this hint Whisper auto-detects the spoken language and
     * regularly misdetects short/accented Arabic clips, so the owner's
     * own fixed-language preference (if any) is the strongest signal
     * available even though it is otherwise only used for the reply
     * language, not the input. Falls back to Arabic, DORR's primary
     * market (see the base system prompt's own "Arabic or English"
     * framing), rather than leaving Whisper to guess with no hint at all.
     */
    public function preferredLanguageCode(Authenticatable $owner): string
    {
        $preference = AiUserLanguagePreference::query()
            ->with('language.translations')
            ->firstOrCreate(
                ['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getAuthIdentifier()],
                ['auto_detect' => true, 'response_language_mode' => AiUserLanguagePreference::MODE_FOLLOW_INPUT],
            );

        return $preference->language->code ?? 'ar';
    }
}
