<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Services\Ar\AiArAssetService;
use Modules\Provider\Models\Provider;
use Modules\User\Models\User;
use Tests\TestCase;

/** Decor review loop: customer submits a pinned version, the provider answers, the customer accepts and the design locks. */
class AiArApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Provider $provider;

    private Provider $stranger;

    private int $assetId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->mock(\App\Services\Notifications\NotificationCenter::class, fn ($m) => $m->shouldReceive('send')->andReturnNull());
        $currency = Currency::create(['code' => 'EGP', 'symbol' => 'ج.م', 'decimal_places' => 2]);
        $country = Country::create(['code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'is_default' => true, 'status' => true, 'flag_id' => Flag::create(['code' => 'eg'])->id, 'currency_id' => $currency->id]);

        $this->customer = User::query()->create(['name' => 'C', 'email' => 'c-'.uniqid().'@example.test', 'password' => bcrypt('x-not-real'), 'status' => UserStatus::Active]);
        $mk = fn (string $n) => Provider::query()->create(['name' => $n, 'email' => $n.uniqid().'@example.test', 'phone' => '10'.random_int(10000000, 99999999), 'country_id' => $country->id, 'password' => bcrypt('x-not-real'), 'status' => UserStatus::Active]);
        $this->provider = $mk('p1');
        $this->stranger = $mk('p2');

        $json = json_encode(['asset' => ['version' => '2.0'], 'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0], 'indices' => 1]]]], 'accessors' => [['count' => 8, 'min' => [0, 0, 0], 'max' => [1, 1, 1]], ['count' => 36]]]);
        $json .= str_repeat(' ', (4 - strlen($json) % 4) % 4);
        $glb = pack('V3', 0x46546C67, 2, 20 + strlen($json)).pack('V2', strlen($json), 0x4E4F534A).$json;
        $this->assetId = app(AiArAssetService::class)->create(['name_en' => 'Sofa', 'category' => 'furniture'], UploadedFile::fake()->createWithContent('s.glb', $glb), $this->customer)->id;
    }

    private function asCustomer(): void
    {
        Sanctum::actingAs($this->customer, ['*'], 'user_api');
    }

    private function asProvider(Provider $p): void
    {
        Sanctum::actingAs($p, ['*'], 'provider_api');
    }

    private function scene(): array
    {
        return ['items' => [['asset_id' => $this->assetId, 'transform' => ['position' => [0, 0, -1], 'rotation' => [0, 0, 0, 1], 'scale' => [1, 1, 1]]]]];
    }

    private function designed(): int
    {
        $this->asCustomer();
        $id = $this->postJson('/api/user/v1/ai-ar/experiences', ['type' => 'decor', 'title' => 'Living room'])->assertStatus(201)->json('data.id');
        $this->putJson("/api/user/v1/ai-ar/experiences/$id/scene", $this->scene())->assertOk();

        return $id;
    }

    public function test_full_happy_path_then_design_is_locked(): void
    {
        $exp = $this->designed();
        $review = $this->postJson("/api/user/v1/ai-ar/experiences/$exp/submit", ['provider_id' => $this->provider->id, 'note' => 'Please check'])->assertStatus(201)->json('data.id');
        $this->putJson("/api/user/v1/ai-ar/experiences/$exp/scene", $this->scene())->assertStatus(409);
        $this->postJson("/api/user/v1/ai-ar/experiences/$exp/accept")->assertStatus(409);

        $this->asProvider($this->provider);
        $this->getJson('/api/provider/v1/ai-ar/inbox')->assertOk()->assertJsonCount(1, 'data');
        $show = $this->getJson("/api/provider/v1/ai-ar/inbox/$review")->assertOk()->assertJsonCount(1, 'data.scene');
        $this->get($show->json('data.scene.0.download_url'))->assertOk();
        $this->postJson("/api/provider/v1/ai-ar/inbox/$review/decide", ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/provider/v1/ai-ar/inbox/$review/decide", ['decision' => 'reject', 'note' => 'late'])->assertStatus(409);

        $this->asCustomer();
        $this->postJson("/api/user/v1/ai-ar/experiences/$exp/accept")->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->putJson("/api/user/v1/ai-ar/experiences/$exp/scene", $this->scene())->assertStatus(409);
    }

    public function test_changes_requested_needs_a_note_and_returns_the_design_to_the_customer(): void
    {
        $exp = $this->designed();
        $review = $this->postJson("/api/user/v1/ai-ar/experiences/$exp/submit", ['provider_id' => $this->provider->id])->json('data.id');

        $this->asProvider($this->provider);
        $this->postJson("/api/provider/v1/ai-ar/inbox/$review/decide", ['decision' => 'request_changes'])->assertStatus(422);
        $this->postJson("/api/provider/v1/ai-ar/inbox/$review/decide", ['decision' => 'request_changes', 'note' => 'Move it left'])->assertOk();

        $this->asCustomer();
        $this->getJson("/api/user/v1/ai-ar/experiences/$exp/reviews")->assertJsonPath('data.0.provider_note', 'Move it left');
        $this->putJson("/api/user/v1/ai-ar/experiences/$exp/scene", $this->scene())->assertOk();
        $this->postJson("/api/user/v1/ai-ar/experiences/$exp/submit", ['provider_id' => $this->provider->id])->assertStatus(201);
    }

    public function test_editing_after_approval_drops_it_and_acceptance_needs_a_fresh_approval(): void
    {
        $exp = $this->designed();
        $review = $this->postJson("/api/user/v1/ai-ar/experiences/$exp/submit", ['provider_id' => $this->provider->id])->json('data.id');
        $this->asProvider($this->provider);
        $this->postJson("/api/provider/v1/ai-ar/inbox/$review/decide", ['decision' => 'approve'])->assertOk();

        $this->asCustomer();
        $this->putJson("/api/user/v1/ai-ar/experiences/$exp/scene", $this->scene())->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson("/api/user/v1/ai-ar/experiences/$exp/accept")->assertStatus(409);
    }

    public function test_withdraw_unlocks_and_other_providers_and_customers_see_nothing(): void
    {
        $exp = $this->designed();
        $review = $this->postJson("/api/user/v1/ai-ar/experiences/$exp/submit", ['provider_id' => $this->provider->id])->json('data.id');

        $this->asProvider($this->stranger);
        $this->getJson('/api/provider/v1/ai-ar/inbox')->assertJsonCount(0, 'data');
        $this->getJson("/api/provider/v1/ai-ar/inbox/$review")->assertStatus(404);
        $this->postJson("/api/provider/v1/ai-ar/inbox/$review/decide", ['decision' => 'approve'])->assertStatus(404);

        $this->asCustomer();
        $this->postJson("/api/user/v1/ai-ar/experiences/$exp/reviews/$review/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->putJson("/api/user/v1/ai-ar/experiences/$exp/scene", $this->scene())->assertOk();
        $this->postJson("/api/user/v1/ai-ar/experiences/$exp/submit", ['provider_id' => 999999])->assertStatus(422);

        $other = User::query()->create(['name' => 'O', 'email' => 'o-'.uniqid().'@example.test', 'password' => bcrypt('x-not-real'), 'status' => UserStatus::Active]);
        Sanctum::actingAs($other, ['*'], 'user_api');
        $this->postJson("/api/user/v1/ai-ar/experiences/$exp/submit", ['provider_id' => $this->provider->id])->assertStatus(404);
    }

    public function test_empty_design_cannot_be_submitted(): void
    {
        $this->asCustomer();
        $id = $this->postJson('/api/user/v1/ai-ar/experiences', ['type' => 'decor', 'title' => 'Empty'])->json('data.id');
        $this->postJson("/api/user/v1/ai-ar/experiences/$id/submit", ['provider_id' => $this->provider->id])->assertStatus(422);
    }

    public function test_each_step_notifies_the_other_side_in_their_own_language_keys(): void
    {
        $sent = [];
        $this->mock(\App\Services\Notifications\NotificationCenter::class, function ($m) use (&$sent) {
            $m->shouldReceive('send')->andReturnUsing(function ($to, $event, $title, $body, $vars = [], $data = []) use (&$sent) {
                $sent[] = [get_class($to), $to->getKey(), $event, $title, $body, $vars['note'] ?? null];
            });
        });

        $exp = $this->designed();
        $first = $this->postJson("/api/user/v1/ai-ar/experiences/$exp/submit", ['provider_id' => $this->provider->id])->json('data.id');
        $this->postJson("/api/user/v1/ai-ar/experiences/$exp/reviews/$first/withdraw")->assertOk();
        $second = $this->postJson("/api/user/v1/ai-ar/experiences/$exp/submit", ['provider_id' => $this->provider->id])->json('data.id');

        $this->asProvider($this->provider);
        $this->postJson("/api/provider/v1/ai-ar/inbox/$second/decide", ['decision' => 'approve'])->assertOk();
        $this->asCustomer();
        $this->postJson("/api/user/v1/ai-ar/experiences/$exp/accept")->assertOk();

        $this->assertSame([
            [Provider::class, $this->provider->id, 'ai_ar.submitted', 'ai_ar_submitted_title', 'ai_ar_submitted_body', null],
            [Provider::class, $this->provider->id, 'ai_ar.withdrawn', 'ai_ar_withdrawn_title', 'ai_ar_withdrawn_body', null],
            [Provider::class, $this->provider->id, 'ai_ar.submitted', 'ai_ar_submitted_title', 'ai_ar_submitted_body', null],
            [User::class, $this->customer->id, 'ai_ar.approved', 'ai_ar_approved_title', 'ai_ar_approved_body', ''],
            [Provider::class, $this->provider->id, 'ai_ar.accepted', 'ai_ar_accepted_title', 'ai_ar_accepted_body', null],
        ], $sent);

        foreach (['en', 'ar'] as $lang) {
            foreach (array_unique(array_merge(...array_map(fn ($r) => [$r[3], $r[4]], $sent))) as $key) {
                $this->assertNotSame($key, trans('notifications.'.$key, [], $lang), "$lang is missing $key");
            }
        }
    }

    public function test_experiences_carry_a_type_notice_and_admin_can_oversee_reviews(): void
    {
        $this->asCustomer();
        $maint = $this->postJson('/api/user/v1/ai-ar/experiences', ['type' => 'maintenance', 'title' => 'Boiler'])->assertStatus(201)->json('data');
        $this->assertStringContainsString(trans('ai.ar_notice_maintenance'), $maint['notice']);
        $this->assertNull($this->postJson('/api/user/v1/ai-ar/experiences', ['type' => 'education', 'title' => 'Cell'])->json('data.notice'));
        $this->getJson('/api/user/v1/ai-ar/info')->assertJsonPath('data.notices.maintenance', trans('ai.ar_notice_maintenance'));

        $exp = $this->designed();
        $this->postJson("/api/user/v1/ai-ar/experiences/$exp/submit", ['provider_id' => $this->provider->id, 'note' => 'Hi'])->assertStatus(201);

        Sanctum::actingAs(\Modules\Admin\Models\Admin::query()->create(['name' => 'A', 'email' => 'ad-'.uniqid().'@example.test', 'password' => 'password', 'status' => true]), ['*'], 'admin_api');
        $this->getJson('/api/admin/v1/ai-ar/reviews')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer_note', 'Hi')->assertJsonPath('data.0.status', 'pending');
        $this->getJson('/api/admin/v1/ai-ar/reviews?status=approved')->assertJsonCount(0, 'data');
    }

    public function test_provider_quote_travels_with_the_approval_and_acceptance_records_it(): void
    {
        $exp = $this->designed();
        $review = $this->postJson("/api/user/v1/ai-ar/experiences/$exp/submit", ['provider_id' => $this->provider->id])->json('data.id');

        $this->asProvider($this->provider);
        $this->postJson("/api/provider/v1/ai-ar/inbox/$review/decide", ['decision' => 'request_changes', 'note' => 'x', 'quote_minor' => 5000])->assertOk()->assertJsonPath('data.quote_minor', null);
        $this->postJson("/api/provider/v1/ai-ar/inbox/$review/decide", ['decision' => 'approve', 'quote_minor' => 123400])->assertStatus(409);

        $this->asCustomer();
        $this->putJson("/api/user/v1/ai-ar/experiences/$exp/scene", $this->scene())->assertOk();
        $second = $this->postJson("/api/user/v1/ai-ar/experiences/$exp/submit", ['provider_id' => $this->provider->id])->json('data.id');

        $this->asProvider($this->provider);
        $this->postJson("/api/provider/v1/ai-ar/inbox/$second/decide", ['decision' => 'approve', 'quote_minor' => 123400])->assertOk()
            ->assertJsonPath('data.quote_minor', 123400)->assertJsonPath('data.currency', 'EGP');

        $this->asCustomer();
        $this->getJson("/api/user/v1/ai-ar/experiences/$exp/reviews")->assertJsonPath('data.0.quote_minor', 123400);
        $this->postJson("/api/user/v1/ai-ar/experiences/$exp/accept")->assertStatus(409); // priced designs are accepted only by paying (see AiArOrderTest)
        $this->assertNull(\Modules\AI\Models\Ar\AiArApproval::find($second)->accepted_at);
    }

    public function test_item_info_keeps_only_whitelisted_https_fields(): void
    {
        $this->asCustomer();
        $id = $this->postJson('/api/user/v1/ai-ar/experiences', ['type' => 'commerce', 'title' => 'Shop'])->json('data.id');
        $item = fn (array $info) => ['items' => [['asset_id' => $this->assetId, 'transform' => ['position' => [0, 0, -1], 'rotation' => [0, 0, 0, 1], 'scale' => [1, 1, 1]], 'info' => $info]]];

        $this->putJson("/api/user/v1/ai-ar/experiences/$id/scene", $item(['title' => 'Sofa', 'price_minor' => 990000, 'url' => 'https://shop.example/sofa', 'script' => '<script>x</script>']))->assertOk();
        $this->assertSame(['title' => 'Sofa', 'price_minor' => 990000, 'url' => 'https://shop.example/sofa'], \Modules\AI\Models\Ar\AiArSceneItem::first()->info);
        $this->putJson("/api/user/v1/ai-ar/experiences/$id/scene", $item(['url' => 'http://insecure.example']))->assertStatus(422);
        $this->putJson("/api/user/v1/ai-ar/experiences/$id/scene", $item(['url' => 'javascript:alert(1)']))->assertStatus(422);
    }

    public function test_provider_search_returns_only_id_and_name_of_active_matches(): void
    {
        $this->asCustomer();
        $this->provider->update(['name' => 'Nile Decor']);
        $this->stranger->update(['name' => 'Other', 'status' => UserStatus::Inactive]);
        $res = $this->getJson('/api/user/v1/ai-ar/providers/search?q=decor')->assertOk()->json('data');
        $this->assertSame([['id' => $this->provider->id, 'name' => 'Nile Decor']], $res);
        $this->getJson('/api/user/v1/ai-ar/providers/search?q=oth')->assertJsonCount(0, 'data');
        $this->getJson('/api/user/v1/ai-ar/providers/search?q=a')->assertStatus(422);
    }
}
