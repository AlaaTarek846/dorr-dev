<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatFeatureRule;
use Modules\Discover\Models\DiscoverCity;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Chat features on or off by country and city (spec "أمان 2", shared requirement 10): the city
 * nearest the phone beats the country, no rule means on, calls carry voice rooms and lives with
 * them, and only the city is kept — never the coordinates.
 */
class ChatFeatureRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private Country $saudi;

    private DiscoverCity $riyadh;

    private DiscoverCity $jeddah;

    protected function setUp(): void
    {
        parent::setUp();

        $flag = Flag::create(['code' => 'sa']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->alice = $make('Alice', '+966500000001');
        $this->bob = $make('Bob', '+966500000002');
        foreach ([[$this->alice, $this->bob], [$this->bob, $this->alice]] as [$owner, $contact]) {
            ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
        }
        $city = function (string $name, float $lat, float $lng) {
            $c = DiscoverCity::query()->create(['country_id' => $this->saudi->id, 'timezone' => 'Asia/Riyadh', 'lat' => $lat, 'lng' => $lng, 'status' => true, 'sort_order' => 1]);
            $c->translations()->create(['locale' => 'en', 'name' => $name]);

            return $c;
        };
        $this->riyadh = $city('Riyadh', 24.7136, 46.6753);
        $this->jeddah = $city('Jeddah', 21.5433, 39.1728);

        config(['chat.livekit.url' => 'wss://live.example.test', 'chat.livekit.api_key' => 'key', 'chat.livekit.api_secret' => 'secret-that-is-long-enough']);
        app(AiProviderRepository::class)->ensureDefaults();
        AiProvider::query()->where('key', 'openai')->firstOrFail()->update(['is_enabled' => true, 'is_default' => true, 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]), '*' => Http::response([])]);
        Sanctum::actingAs($this->alice, [], 'user_api');
    }

    public function test_the_city_beats_the_country_and_calls_carry_rooms_and_lives(): void
    {
        // No rules: everything on, and no city yet.
        $this->getJson('/api/mobile/v1/chat/features', $this->headers())->assertOk()
            ->assertJsonPath('data.features', ['calls' => true, 'voice_rooms' => true, 'live' => true, 'stories' => true, 'ai' => true, 'verification_paid' => false, 'verification_celebrity' => false, 'education_groups' => true, 'work_groups' => true])
            ->assertJsonPath('data.city', null);

        // Calls off in the country — and with them voice rooms and lives.
        ChatFeatureRule::query()->create(['feature' => 'calls', 'country_id' => $this->saudi->id, 'enabled' => false]);
        // …but on in Riyadh (a pilot).
        ChatFeatureRule::query()->create(['feature' => 'calls', 'country_id' => $this->saudi->id, 'city_id' => $this->riyadh->id, 'enabled' => true]);

        $direct = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->json('data.id');
        $this->getJson('/api/mobile/v1/chat/features', $this->headers())
            ->assertJsonPath('data.features.calls', false)->assertJsonPath('data.features.voice_rooms', false)->assertJsonPath('data.features.live', false);
        $this->postJson("/api/mobile/v1/chat/conversations/{$direct}/calls", ['type' => 'audio'], $this->headers())
            ->assertForbidden()->assertJsonPath('error_code', 'chat_calls_unavailable_country');

        // The phone is in Riyadh: the city's rule wins.
        $this->putJson('/api/mobile/v1/chat/location', ['latitude' => 24.70, 'longitude' => 46.70], $this->headers())->assertOk()
            ->assertJsonPath('data.city.name', 'Riyadh')->assertJsonPath('data.features.calls', true)->assertJsonPath('data.features.voice_rooms', true);
        // Bob, still nowhere in particular, can't be rung yet; once his phone is in Riyadh too, he can.
        $this->postJson("/api/mobile/v1/chat/conversations/{$direct}/calls", ['type' => 'audio'], $this->headers())
            ->assertForbidden()->assertJsonPath('error_code', 'chat_calls_unavailable_peer_country');
        Sanctum::actingAs($this->bob, [], 'user_api');
        $this->putJson('/api/mobile/v1/chat/location', ['latitude' => 24.72, 'longitude' => 46.66], $this->headers())->assertOk();
        Sanctum::actingAs($this->alice, [], 'user_api');
        $this->postJson("/api/mobile/v1/chat/conversations/{$direct}/calls", ['type' => 'audio'], $this->headers())->assertCreated();

        // Only the city is kept — never the coordinates.
        $row = (array) DB::table('chat_privacy_settings')->where('owner_id', $this->alice->id)->first();
        $this->assertSame($this->riyadh->id, (int) $row['city_id']);
        $this->assertStringNotContainsString('24.7', json_encode($row));

        // In Jeddah: back to the country's rule.
        $this->putJson('/api/mobile/v1/chat/location', ['latitude' => 21.55, 'longitude' => 39.20], $this->headers())->assertOk()
            ->assertJsonPath('data.city.name', 'Jeddah')->assertJsonPath('data.features.calls', false);

        // Far from every city: no city, the country's rule.
        $this->putJson('/api/mobile/v1/chat/location', ['latitude' => 28.0, 'longitude' => 45.0], $this->headers())->assertOk()
            ->assertJsonPath('data.city', null)->assertJsonPath('data.features.calls', false);

        // A city learned long ago no longer counts.
        $this->putJson('/api/mobile/v1/chat/location', ['latitude' => 24.70, 'longitude' => 46.70], $this->headers());
        $this->travel(31)->days();
        $this->getJson('/api/mobile/v1/chat/features', $this->headers())->assertJsonPath('data.city', null)->assertJsonPath('data.features.calls', false);
    }

    public function test_stories_and_ai_switched_off_in_a_city(): void
    {
        ChatFeatureRule::query()->create(['feature' => 'stories', 'country_id' => $this->saudi->id, 'city_id' => $this->jeddah->id, 'enabled' => false]);
        ChatFeatureRule::query()->create(['feature' => 'ai', 'country_id' => $this->saudi->id, 'city_id' => $this->jeddah->id, 'enabled' => false]);

        $this->getJson('/api/mobile/v1/chat/stories', $this->headers())->assertOk();
        $this->getJson('/api/mobile/v1/chat/ai', $this->headers())->assertJsonPath('data.enabled', true);

        $this->putJson('/api/mobile/v1/chat/location', ['latitude' => 21.55, 'longitude' => 39.20], $this->headers())->assertOk();
        $this->getJson('/api/mobile/v1/chat/stories', $this->headers())->assertForbidden()->assertJsonPath('error_code', 'chat_feature_unavailable');
        $this->getJson('/api/mobile/v1/chat/ai', $this->headers())->assertJsonPath('data.enabled', false)->assertJsonPath('data.assistant', false);
        $this->postJson('/api/mobile/v1/chat/ai/proofread', ['text' => 'helo'], $this->headers())->assertForbidden()->assertJsonPath('error_code', 'chat_feature_unavailable');
    }

    public function test_the_admin_manages_the_rules(): void
    {
        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['chat-settings.view', 'chat-settings.update'] as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo(['chat-settings.view', 'chat-settings.update']);
        Sanctum::actingAs($admin, [], 'admin_api');

        $this->getJson('/api/admin/v1/chat-feature-rules/options')->assertOk()
            ->assertJsonPath('data.features', ChatFeatureRule::FEATURES)->assertJsonCount(2, 'data.cities');

        $rule = $this->postJson('/api/admin/v1/chat-feature-rules', ['feature' => 'live', 'country_id' => $this->saudi->id, 'city_id' => $this->riyadh->id, 'enabled' => false, 'note' => 'Pilot'])
            ->assertCreated()->assertJsonPath('data.city.name', 'Riyadh')->json('data.id');
        // A city of another country is refused.
        $other = Country::create(['code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'is_default' => false, 'flag_id' => Flag::create(['code' => 'eg'])->id, 'currency_id' => Currency::create(['code' => 'EGP', 'symbol' => 'EGP', 'decimal_places' => 2])->id, 'status' => true]);
        $this->postJson('/api/admin/v1/chat-feature-rules', ['feature' => 'live', 'country_id' => $other->id, 'city_id' => $this->riyadh->id, 'enabled' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('city_id');

        $this->putJson("/api/admin/v1/chat-feature-rules/{$rule}", ['enabled' => true])->assertOk()->assertJsonPath('data.enabled', true);
        $this->getJson('/api/admin/v1/chat-feature-rules')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/admin/v1/chat-feature-rules/{$rule}")->assertOk();
        $this->assertSame(0, ChatFeatureRule::query()->count());
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Country' => 'SA'];
    }
}
