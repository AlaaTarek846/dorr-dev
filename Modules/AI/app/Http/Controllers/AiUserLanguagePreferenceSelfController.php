<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Language;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AI\Http\Requests\AiUserLanguagePreferenceSelfUpdateRequest;
use Modules\AI\Http\Resources\AiLanguageResource;
use Modules\AI\Http\Resources\AiLanguageVariantResource;
use Modules\AI\Http\Resources\AiUserLanguagePreferenceResource;
use Modules\AI\Models\AiLanguageVariant;
use Modules\AI\Models\AiUserLanguagePreference;
use Modules\User\Models\User;

/**
 * Business gap fix: ai_user_language_preferences existed since Phase 10 and
 * was already read on every chat reply (AiChatLanguageResolver), but the
 * user themself had no screen to change it - only an admin could, and only
 * after the earlier PUT /admin/v1/ai-user-language-preferences/{id} fix.
 * This is the missing self-service counterpart: the authenticated user
 * reads and writes only their own row, found by owner_type/owner_id
 * (never by a numeric id in the URL, so one user can never reach another's
 * preference), and gets back the active languages/variants to choose from
 * in the same call so the frontend needs no second request to populate
 * the picker.
 */
class AiUserLanguagePreferenceSelfController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $preference = $this->findOrCreateOwn($request);

        return ApiResponse::success([
            'preference' => new AiUserLanguagePreferenceResource($preference),
            // Root-cause fix (languages consolidation): sourced from the
            // platform's general Language model now, filtered by the
            // "ai_enabled" flag instead of a separate ai_languages row.
            'languages' => AiLanguageResource::collection(
                Language::query()->with('translations')->where('ai_enabled', true)->orderBy('code')->get()
            ),
            'variants' => AiLanguageVariantResource::collection(
                AiLanguageVariant::query()->with('language.translations')->where('is_active', true)->orderBy('name')->get()
            ),
        ]);
    }

    public function update(AiUserLanguagePreferenceSelfUpdateRequest $request): JsonResponse
    {
        $preference = $this->findOrCreateOwn($request);
        $data = $request->validated();

        $mode = $data['response_language_mode'] ?? $preference->response_language_mode;
        $languageId = array_key_exists('language_id', $data) ? $data['language_id'] : $preference->language_id;

        if ($mode === AiUserLanguagePreference::MODE_FIXED && $languageId === null) {
            return ApiResponse::error(
                __('ai.fixed_mode_requires_language'),
                422,
                ['language_id' => [__('ai.fixed_mode_requires_language')]],
            );
        }

        // Picking "follow the input language" makes auto_detect meaningful
        // again and a stale variant (tied to the old fixed language) is no
        // longer implied, so it is left as-is only when a language is
        // still present; clearing the language clears the variant with it.
        if (array_key_exists('language_id', $data) && $data['language_id'] === null) {
            $data['variant_id'] = null;
        }

        $preference->update(array_merge($data, [
            'auto_detect' => $mode === AiUserLanguagePreference::MODE_FOLLOW_INPUT,
        ]));

        $preference->load(['language', 'variant']);

        return ApiResponse::success(
            new AiUserLanguagePreferenceResource($preference),
            __('api.updated'),
        );
    }

    protected function findOrCreateOwn(Request $request): AiUserLanguagePreference
    {
        /** @var User $user */
        $user = $request->user('user_api');

        return AiUserLanguagePreference::query()
            ->with(['language.translations', 'variant'])
            ->firstOrCreate(
                ['owner_type' => $user->getMorphClass(), 'owner_id' => $user->getAuthIdentifier()],
                ['auto_detect' => true, 'response_language_mode' => AiUserLanguagePreference::MODE_FOLLOW_INPUT],
            );
    }
}
