<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiMessage;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Models\RideSetting;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * DORR rides inside DORR AI's chat: "I want to go to Cairo airport" → the answer recommends DORR (the model
 * is told to, and never another ride app) and a «احجز مشوارك مع دُرر» card comes under it with the place
 * from the admin's map and the rest of the sentence understood. Nothing when rides are off or the message
 * isn't about a trip.
 */
class RidesAiChatBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Country $country;

    /** @var list<string> */
    private array $chatSystems = [];

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai.chat.verification.enabled', false);
        $flag = Flag::create(['code' => 'eg']);
        $this->country = Country::create(['code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => Currency::create(['code' => 'EGP', 'symbol' => 'EGP', 'decimal_places' => 2])->id, 'status' => true]);
        Language::create(['code' => 'ar', 'direction' => 'rtl', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        $this->user = User::create(['name' => 'Sara', 'phone' => '+201000000001', 'country_id' => $this->country->id, 'status' => 'active', 'phone_verified_at' => now()]);

        RideCategory::query()->create(['slug' => 'economy', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 1, 'capacity_max' => 4]);
        foreach (['rides', 'category.economy', 'ai_booking'] as $feature) {
            RideFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->country->id, 'enabled' => true]);
        }
        RideFeatures::forget();
        RideSetting::current()->update(['map_provider' => 'mapbox', 'mapbox_token' => 'pk.test']);

        AiPlan::query()->create(['name' => 'Trial', 'code' => 'trial', 'is_trial' => true, 'is_active' => true, 'sort_order' => 1, 'usage_minutes' => 60, 'cooldown_minutes' => 5]);
        app(AiProviderRepository::class)->ensureDefaults();
        AiProvider::query()->where('key', 'openai')->firstOrFail()->update(['is_enabled' => true, 'is_default' => true, 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);

        Http::fake([
            'api.openai.com/v1/chat/completions' => function (Request $request) {
                $first = (string) ($request['messages'][0]['content'] ?? '');
                if (str_starts_with($first, 'You help a rider book a car ride')) {
                    // «دُرر يفهم مقصدك»: the trip in the person's words, understood.
                    return Http::response(['choices' => [['message' => ['content' => '{"destination": "مطار القاهرة الدولي", "category": "economy", "passengers": 2, "payment": "cash"}']]]]);
                }
                if (str_starts_with($first, 'You classify a request')) {
                    return Http::response(['choices' => [['message' => ['content' => 'not json']]]]);
                }
                $this->chatSystems[] = collect($request['messages'])->where('role', 'system')->pluck('content')->implode("\n");

                return Http::response(['choices' => [['message' => ['content' => 'تقدر تحجز مشوارك للمطار مع دُرر من الكارت تحت.']]]]);
            },
            'api.mapbox.com/geocoding/*' => Http::response(['features' => [
                ['text' => 'مطار القاهرة الدولي', 'place_name' => 'مطار القاهرة الدولي، القاهرة', 'center' => [31.4056, 30.1219]],
            ]]),
            '*' => Http::response([], 200),
        ]);
        Sanctum::actingAs($this->user, [], 'user_api');
    }

    public function test_a_trip_in_the_chat_recommends_dorr_and_brings_a_filled_in_booking_card(): void
    {
        $chat = $this->postJson('/api/user/v1/ai-chat/conversations')->assertCreated()->json('data.id');
        $card = $this->postJson("/api/user/v1/ai-chat/conversations/{$chat}/messages", ['message' => 'عايز اروح مطار القاهرة احنا اتنين وهدفع كاش'])
            ->assertOk()
            ->assertJsonPath('data.assistant_message.actions.0.type', 'ride_booking')
            ->assertJsonPath('data.assistant_message.actions.0.service', 'passenger_ride')
            ->json('data.assistant_message.actions.0');

        $this->assertSame('مطار القاهرة الدولي', $card['destination']['name']);
        $this->assertEqualsWithDelta(30.1219, $card['destination']['lat'], 0.0001);
        $this->assertSame('economy', $card['understood']['category']);
        $this->assertSame(2, $card['understood']['passengers']);
        $this->assertSame('cash', $card['understood']['payment']);
        $this->assertSame('عايز اروح مطار القاهرة احنا اتنين وهدفع كاش', $card['text']);
        // The model was told to recommend DORR and never another ride app.
        $this->assertStringContainsString('DORR Rides', $this->chatSystems[0]);
        $this->assertStringContainsString('Never recommend or mention other ride-hailing apps', $this->chatSystems[0]);
        // Kept on the answer: the conversation shows the card again later.
        $this->assertSame('ride_booking', AiMessage::query()->where('role', 'assistant')->latest('id')->value('actions')[0]['type']);
    }

    public function test_no_card_when_the_message_isnt_a_trip_or_rides_are_off(): void
    {
        $chat = $this->postJson('/api/user/v1/ai-chat/conversations')->assertCreated()->json('data.id');
        $this->postJson("/api/user/v1/ai-chat/conversations/{$chat}/messages", ['message' => 'اكتبلي قصيدة عن البحر'])
            ->assertOk()->assertJsonPath('data.assistant_message.actions', []);
        $this->assertStringNotContainsString('DORR Rides', end($this->chatSystems));

        RideFeatureRule::query()->where('feature', 'rides')->update(['enabled' => false]);
        RideFeatures::forget();
        $this->postJson("/api/user/v1/ai-chat/conversations/{$chat}/messages", ['message' => 'وديني مطار القاهرة'])
            ->assertOk()->assertJsonPath('data.assistant_message.actions', []);
    }

    public function test_without_the_assistant_switch_the_card_still_opens_rides_with_the_words(): void
    {
        RideFeatureRule::query()->where('feature', 'ai_booking')->delete();
        RideFeatures::forget();
        $chat = $this->postJson('/api/user/v1/ai-chat/conversations')->assertCreated()->json('data.id');
        $this->postJson("/api/user/v1/ai-chat/conversations/{$chat}/messages", ['message' => 'take me to Cairo airport'])
            ->assertOk()
            ->assertJsonPath('data.assistant_message.actions.0.type', 'ride_booking')
            ->assertJsonPath('data.assistant_message.actions.0.destination', null)
            ->assertJsonPath('data.assistant_message.actions.0.text', 'take me to Cairo airport');
    }
}
