<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\World\AiWorldLocation;
use Modules\AI\Models\World\AiWorldPrice;
use Modules\AI\Services\AiGateway;
use Modules\User\Models\User;
use Tests\TestCase;

/** DORR AI WORLD Phase 3: the Director proposes scenes and a price without charging. */
class AiWorldDirectorTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;

    private AiWorldLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create(['code' => 'EGP', 'symbol' => 'ج.م', 'decimal_places' => 2]);
        $this->country = Country::create([
            'code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'eg'])->id, 'currency_id' => $currency->id,
        ]);
        app()->instance('resolved_country', $this->country);
        AiWorldPrice::create(['country_id' => $this->country->id, 'quality' => 'standard', 'price_per_second_minor' => 100]);
        $this->location = AiWorldLocation::create([
            'source' => 'curated', 'provider_place_id' => 'giza', 'name_en' => 'Giza Pyramids', 'name_ar' => 'أهرامات الجيزة',
            'lat' => 29.97, 'lng' => 31.13, 'search_text' => 'giza pyramids',
        ]);
        Sanctum::actingAs(User::query()->create([
            'name' => 'D', 'email' => 'd-'.uniqid().'@example.test', 'password' => bcrypt('x-not-real'), 'status' => UserStatus::Active,
        ]), ['*'], 'user_api');
    }

    private function enableProvider(): void
    {
        AiProvider::query()->updateOrCreate(['key' => 'google'], ['name' => 'Google', 'is_enabled' => true, 'api_key' => 'k-not-real', 'model' => 'gemini']);
    }

    private function modelSays(?string $content, bool $success = true): void
    {
        $this->mock(AiGateway::class, fn (MockInterface $m) => $m->shouldReceive('chat')->andReturn(['success' => $success, 'message' => '', 'content' => $content]));
    }

    public function test_director_returns_clamped_scenes_with_a_price(): void
    {
        $this->enableProvider();
        $id = $this->location->id;
        $this->modelSays("Sure!\n".json_encode([
            ['prompt' => 'Drone shot at sunrise', 'seconds' => 7, 'location_id' => $id],
            ['prompt' => 'Close up of stone', 'seconds' => 8, 'location_id' => 999],
        ]));

        $res = $this->postJson('/api/user/v1/ai-world/projects/plan', ['brief' => 'A day at the pyramids', 'location_ids' => [$id]])->assertOk();

        $this->assertSame('ai', $res->json('data.source'));
        $this->assertSame(2, count($res->json('data.scenes')));
        $this->assertSame(6, $res->json('data.scenes.0.seconds')); // 7 -> nearest allowed (6)
        $this->assertSame('Giza Pyramids', $res->json('data.scenes.0.location_name'));
        $this->assertNull($res->json('data.scenes.1.location_id'));
        $this->assertSame(1400, $res->json('data.estimate_minor'));
    }

    public function test_falls_back_to_one_scene_per_place_when_the_model_is_unusable(): void
    {
        $this->enableProvider();
        $this->modelSays('not json at all');

        $res = $this->postJson('/api/user/v1/ai-world/projects/plan', ['brief' => 'Pyramids trip', 'location_ids' => [$this->location->id]])->assertOk();

        $this->assertSame('fallback', $res->json('data.source'));
        $this->assertSame($this->location->id, $res->json('data.scenes.0.location_id'));
        $this->assertSame(800, $res->json('data.estimate_minor'));
    }

    public function test_plan_needs_a_brief(): void
    {
        $this->postJson('/api/user/v1/ai-world/projects/plan', [])->assertStatus(422);
    }
}
