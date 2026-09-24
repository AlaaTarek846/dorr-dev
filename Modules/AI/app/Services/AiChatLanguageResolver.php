<?php

namespace Modules\AI\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\AI\Models\AiUserLanguagePreference;

/**
 * Resolves the language/tone instruction (if any) that should be appended
 * to the system prompt for this owner, based on their Phase 10
 * ai_user_language_preferences row. Owners never see or manage this
 * directly yet (no user-facing settings screen), so a sane default
 * ("follow_input", auto-detect) is created the first time they chat and
 * simply lets the base system prompt's "reply in the same language the
 * user wrote in" instruction do the work.
 */
class AiChatLanguageResolver
{
    public function resolve(Authenticatable $owner): ?string
    {
        $preference = AiUserLanguagePreference::query()
            ->with(['language', 'variant'])
            ->firstOrCreate(
                ['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getAuthIdentifier()],
                ['auto_detect' => true, 'response_language_mode' => AiUserLanguagePreference::MODE_FOLLOW_INPUT],
            );

        if ($preference->response_language_mode !== AiUserLanguagePreference::MODE_FIXED || ! $preference->language) {
            return null;
        }

        $instruction = "Always reply in {$preference->language->name} ({$preference->language->code}), regardless of the language the user writes in.";

        if ($preference->variant) {
            $instruction .= " Use a {$preference->variant->style} tone, matching the \"{$preference->variant->name}\" style.";
        }

        return $instruction;
    }
}
