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
use Modules\AI\Services\Ar\AiArCommandInterpreter;
use Modules\AI\Services\AiGateway;
use Modules\User\Models\User;
use Tests\TestCase;

/** AR-020: the model only proposes; whatever is not on the whitelist, or refers to an id the device does not have, is dropped. */
class AiArCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $currency = Currency::create(['code' => 'EGP', 'symbol' => 'ج.م', 'decimal_places' => 2]);
        Country::create(['code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'is_default' => true, 'status' => true, 'flag_id' => Flag::create(['code' => 'eg'])->id, 'currency_id' => $currency->id]);
        AiProvider::query()->updateOrCreate(['key' => 'google'], ['name' => 'Google', 'is_enabled' => true, 'api_key' => 'k-not-real', 'model' => 'gemini']);
        Sanctum::actingAs(User::query()->create(['name' => 'A', 'email' => 'a-'.uniqid().'@example.test', 'password' => bcrypt('x-not-real'), 'status' => UserStatus::Active]), ['*'], 'user_api');
    }

    private function model(string $content, bool $success = true): void
    {
        $this->mock(AiGateway::class, fn (MockInterface $m) => $m->shouldReceive('chat')->andReturn(['success' => $success, 'message' => '', 'content' => $content]));
    }

    public function test_only_whitelisted_actions_on_known_ids_survive_and_values_are_clamped(): void
    {
        $this->model('Sure: [{"do":"move","item_id":1,"dx":99,"dz":-0.5},{"do":"delete_everything","item_id":1},{"do":"remove","item_id":777},'
            .'{"do":"scale","item_id":1,"factor":50},{"do":"tint","item_id":1,"color":"red; drop table"},{"do":"tint","item_id":1,"color":"#abcdef"},{"do":"add","asset_id":5},{"do":"add","asset_id":6}]');

        $res = $this->postJson('/api/user/v1/ai-ar/command', ['text' => 'make it nicer', 'items' => [['id' => 1, 'name' => 'Sofa']]])->assertOk()->json('data');

        $this->assertSame('ai', $res['source']);
        $this->assertEquals([
            ['do' => 'move', 'item_id' => 1, 'dx' => 5, 'dz' => -0.5],
            ['do' => 'scale', 'item_id' => 1, 'factor' => 4],
            ['do' => 'tint', 'item_id' => 1, 'color' => '#ABCDEF'],
        ], $res['actions']);
    }

    public function test_unusable_or_failed_model_answers_give_no_actions_not_errors(): void
    {
        foreach ([['not json at all', true], ['[]', true], ['[{"do":"remove","item_id":1}]', false]] as [$content, $ok]) {
            $this->model($content, $ok);
            $out = app(AiArCommandInterpreter::class)->interpret('anything', [['id' => 1, 'name' => 'Sofa']], []);
            $this->assertSame([], $out['actions']);
        }
    }

    public function test_validation_and_cap_on_action_count(): void
    {
        $this->postJson('/api/user/v1/ai-ar/command', ['text' => ''])->assertStatus(422);

        $many = json_encode(array_fill(0, 30, ['do' => 'remove', 'item_id' => 1]));
        $this->assertCount(10, app(AiArCommandInterpreter::class)->clean(json_decode($many, true), [1], []));
    }

    public function test_captures_need_consent_real_image_type_and_expire(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $exp = $this->postJson('/api/user/v1/ai-ar/experiences', ['type' => 'free', 'title' => 'Room'])->assertStatus(201)->json('data.id');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $img = fn (string $bytes = null) => \Illuminate\Http\UploadedFile::fake()->createWithContent('shot.png', $bytes ?? $png);

        $this->postJson("/api/user/v1/ai-ar/experiences/$exp/captures", ['file' => $img(), 'kind' => 'photo', 'consent' => false])->assertStatus(422);
        $this->postJson("/api/user/v1/ai-ar/experiences/$exp/captures", ['file' => $img('<?php echo 1;'), 'kind' => 'photo', 'consent' => true])->assertStatus(422);

        $id = $this->postJson("/api/user/v1/ai-ar/experiences/$exp/captures", ['file' => $img(), 'kind' => 'photo', 'consent' => true])->assertStatus(201)->json('data.id');
        $capture = \Modules\AI\Models\Ar\AiArCapture::find($id);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($capture->file_path);

        $capture->update(['expires_at' => now()->subDay()]);
        $this->artisan('ai:purge-ar')->assertSuccessful();
        $this->assertNull(\Modules\AI\Models\Ar\AiArCapture::find($id));
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing($capture->file_path);

        $this->postJson("/api/user/v1/ai-ar/experiences/$exp/captures", ['file' => $img(), 'kind' => 'photo', 'consent' => true])->assertStatus(201);
        $this->deleteJson("/api/user/v1/ai-ar/experiences/$exp/captures/".\Modules\AI\Models\Ar\AiArCapture::first()->id)->assertOk();
        $this->assertSame(0, \Modules\AI\Models\Ar\AiArCapture::count());
    }

    public function test_vision_returns_clamped_estimates_with_notice_and_rejects_non_images(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $this->model('[{"name":"Sofa","width_m":2.1,"height_m":0.9,"confidence":0.8},{"name":"Wall","width_m":400,"height_m":-1,"confidence":9},{"name":""}]');

        $res = $this->postJson('/api/user/v1/ai-ar/vision', ['photo' => \Illuminate\Http\UploadedFile::fake()->createWithContent('r.png', $png)])->assertOk()->json('data');

        $this->assertTrue($res['estimate']);
        $this->assertNotSame('', $res['notice']);
        $this->assertCount(2, $res['objects']);
        $this->assertEquals([10, null, 1], [$res['objects'][1]['width_m'], $res['objects'][1]['height_m'], $res['objects'][1]['confidence']]);

        $this->postJson('/api/user/v1/ai-ar/vision', ['photo' => \Illuminate\Http\UploadedFile::fake()->createWithContent('x.png', 'not an image')])->assertStatus(422);

        $this->model('nothing useful', true);
        $this->postJson('/api/user/v1/ai-ar/vision', ['photo' => \Illuminate\Http\UploadedFile::fake()->createWithContent('r.png', $png)])->assertStatus(503);
    }
}
