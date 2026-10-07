<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiLanguageVariant;
use Modules\AI\Models\AiUserLanguagePreference;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Business gap fix: ai_user_language_preferences was previously
 * admin-managed only (see the addendum's still-open gap #2 -
 * "لا اليوزر ولا كان حتى الأدمن يقدر يغيّرها" before the admin PUT was
 * added, and even after that fix, only the admin could reach it). This
 * proves the self-service screen: a user reads and writes only their own
 * row, found from their auth identity - never from a route id - and
 * cannot reach anyone else's.
 */
class AiUserLanguagePreferenceSelfScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $suffix = ''): User
    {
        return User::query()->create([
            'name' => 'Language Pref User'.$suffix,
            'email' => 'lang-pref-'.uniqid().$suffix.'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    protected function actingAsUser(?User $user = null): User
    {
        $user ??= $this->makeUser();
        Sanctum::actingAs($user, ['*'], 'user_api');

        return $user;
    }

    // Root-cause fix (languages consolidation): preferences now point at
    // the platform's general Language model, which requires a flag_id.
    protected function makeLanguage(string $code, string $direction, bool $aiEnabled): Language
    {
        $flag = Flag::query()->create(['code' => 'xx-'.uniqid(), 'status' => true]);

        return Language::query()->create([
            'code' => $code,
            'direction' => $direction,
            'is_default_website' => false,
            'is_default_dashboard' => false,
            'stores_translation' => false,
            'status' => true,
            'ai_enabled' => $aiEnabled,
            'flag_id' => $flag->id,
        ]);
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/user/v1/ai-language-preference')->assertStatus(401);
    }

    public function test_show_creates_a_default_row_on_first_visit_and_lists_active_languages(): void
    {
        $this->actingAsUser();

        $active = $this->makeLanguage('ar', 'rtl', true);
        $this->makeLanguage('fr', 'ltr', false);
        AiLanguageVariant::query()->create([
            'language_id' => $active->id, 'code' => 'egyptian_arabic', 'name' => 'Egyptian Arabic',
            'style' => 'conversational', 'is_default' => true, 'is_active' => true,
        ]);

        $response = $this->getJson('/api/user/v1/ai-language-preference');

        $response->assertOk();
        $response->assertJsonPath('data.preference.response_language_mode', AiUserLanguagePreference::MODE_FOLLOW_INPUT);
        $response->assertJsonPath('data.preference.auto_detect', true);
        $response->assertJsonCount(1, 'data.languages');
        $response->assertJsonPath('data.languages.0.code', 'ar');
        $response->assertJsonCount(1, 'data.variants');

        $this->assertSame(1, AiUserLanguagePreference::query()->count());
    }

    public function test_update_sets_a_fixed_language_and_variant(): void
    {
        $this->actingAsUser();

        $language = $this->makeLanguage('ar', 'rtl', true);
        $variant = AiLanguageVariant::query()->create([
            'language_id' => $language->id, 'code' => 'egyptian_arabic', 'name' => 'Egyptian Arabic',
            'style' => 'conversational', 'is_default' => true, 'is_active' => true,
        ]);

        $response = $this->putJson('/api/user/v1/ai-language-preference', [
            'response_language_mode' => AiUserLanguagePreference::MODE_FIXED,
            'language_id' => $language->id,
            'variant_id' => $variant->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.response_language_mode', AiUserLanguagePreference::MODE_FIXED);
        $response->assertJsonPath('data.language.id', $language->id);
        $response->assertJsonPath('data.variant.id', $variant->id);
        // Picking a fixed language is the opposite of "follow whatever
        // language the user typed in", so auto_detect must flip off with
        // it - not stay stuck at its old value.
        $response->assertJsonPath('data.auto_detect', false);
    }

    public function test_switching_to_fixed_mode_without_a_language_is_rejected(): void
    {
        $this->actingAsUser();

        $response = $this->putJson('/api/user/v1/ai-language-preference', [
            'response_language_mode' => AiUserLanguagePreference::MODE_FIXED,
        ]);

        $response->assertStatus(422);
    }

    public function test_a_user_can_never_read_or_change_another_users_preference(): void
    {
        $owner = $this->makeUser('-owner');
        AiUserLanguagePreference::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getAuthIdentifier(),
            'auto_detect' => false,
            'response_language_mode' => AiUserLanguagePreference::MODE_FIXED,
        ]);

        $this->actingAsUser($this->makeUser('-intruder'));

        $response = $this->getJson('/api/user/v1/ai-language-preference');

        $response->assertOk();
        // The intruder's own visit must not return or mutate the owner's
        // row - a fresh follow_input default row of their own instead.
        $response->assertJsonPath('data.preference.response_language_mode', AiUserLanguagePreference::MODE_FOLLOW_INPUT);
        $this->assertSame(2, AiUserLanguagePreference::query()->count());
    }
}
