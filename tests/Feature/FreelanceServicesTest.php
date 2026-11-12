<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelancePolicy;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceService;
use Modules\Freelance\Models\FreelanceServiceOrder;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Offers\FreelancerOffers;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Projects\Agreements;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Services\WalletService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FL-L04: ready-made services. A freelancer's service in levels with extras, reviewed by the staff before clients see it; a client orders
 * one without offers and it runs as a private project with the agreement already sent — the freelancer accepts or declines in their hours,
 * then the work is paid and released like any project. The order keeps what was bought; nothing is paid before the freelancer accepts.
 */
class FreelanceServicesTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1';

    private const CLIENT = '/api/mobile/v1/freelance';

    private Country $saudi;

    private FreelanceSpecialty $web;

    private User $client;

    private Freelancer $sara;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat', 'wallet_payment', 'free_offer_quota', 'commission', 'client_fee', 'services'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();

        $this->web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $this->web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
    }

    // -------------------------------------------------------------- helpers

    private function asSara(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->sara->refresh(), [], 'freelance_api');
    }

    private function asClient(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->client->refresh(), [], 'user_api');
    }

    private function staff(string ...$permissions): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'Staff', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'admin_api');
            $admin->givePermissionTo($permission);
        }
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    private function policy(array $data): void
    {
        FreelancePolicy::query()->create(['country_id' => $this->saudi->id, 'specialty_id' => null, 'data' => $data, 'status' => true]);
        FreelancePolicies::forget();
    }

    /** @return array<string, mixed> */
    private function body(array $over = []): array
    {
        return $over + [
            'title' => 'A logo for your shop',
            'description' => 'I draw a clean logo that fits your shop, with the colours and the files you need for print and the web.',
            'specialty_id' => $this->web->id,
            'tiers' => [
                ['level' => 'basic', 'name' => 'Starter', 'price_minor' => 20000, 'delivery_days' => 3, 'revisions' => 1, 'features' => ['One idea', 'PNG']],
                ['level' => 'standard', 'name' => 'Shop', 'price_minor' => 40000, 'delivery_days' => 5, 'revisions' => 3, 'features' => ['Three ideas', 'PNG + SVG']],
                ['level' => 'premium', 'name' => 'Brand', 'price_minor' => 80000, 'delivery_days' => 7, 'revisions' => 5, 'features' => ['Five ideas', 'Brand book']],
            ],
            'addons' => [
                ['title' => 'Delivered a day sooner', 'price_minor' => 5000, 'extra_days' => 0],
                ['title' => 'Source files', 'price_minor' => 7000, 'extra_days' => 1],
            ],
        ];
    }

    /** Sara's service, sent and approved by the staff: published. */
    private function published(array $over = []): FreelanceService
    {
        $this->asSara();
        $uuid = $this->postJson(self::API.'/services', $this->body($over))->assertCreated()->json('data.uuid');
        $this->postJson(self::API."/services/{$uuid}/submit")->assertOk()->assertJsonPath('data.status', 'pending_review');
        $this->staff('freelance-services.view', 'freelance-services.update');
        $this->postJson("/api/admin/v1/freelance-services/{$uuid}/approve")->assertOk()->assertJsonPath('data.status', 'published');

        return FreelanceService::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function order(FreelanceService $s, array $body = ['level' => 'standard'])
    {
        $this->asClient();

        return $this->postJson(self::CLIENT."/services/{$s->uuid}/order", $body);
    }

    private function topUp(int $minor): void
    {
        $w = app(WalletService::class);
        $w->credit($w->firstOrCreateWallet($this->client, $this->saudi), $minor, WalletBucket::Withdrawable, WalletTransactionType::Topup);
    }

    // -------------------------------------------------------------- the service

    public function test_a_service_is_reviewed_before_clients_see_it_and_the_freelancer_is_told(): void
    {
        $this->asSara();
        $created = $this->postJson(self::API.'/services', $this->body())->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.starting_price_minor', 20000)->assertJsonPath('data.tiers.2.level', 'premium')
            ->assertJsonPath('data.addons.1.title', 'Source files')->assertJsonPath('data.can.submit', true)->assertJsonPath('data.currency', 'SAR');
        $uuid = $created->json('data.uuid');

        // A draft is nobody's but Sara's.
        $this->asClient();
        $this->getJson(self::CLIENT.'/services')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::CLIENT."/services/{$uuid}")->assertNotFound();

        $this->asSara();
        $this->postJson(self::API."/services/{$uuid}/submit")->assertOk()->assertJsonPath('data.status', 'pending_review');
        $this->asClient();
        $this->getJson(self::CLIENT.'/services')->assertOk()->assertJsonCount(0, 'data');

        $this->staff('freelance-services.view', 'freelance-services.update');
        $this->getJson('/api/admin/v1/freelance-services')->assertOk()->assertJsonPath('data.counts.pending_review', 1)->assertJsonPath('data.services.0.uuid', $uuid);
        $this->postJson("/api/admin/v1/freelance-services/{$uuid}/approve")->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.service.approved')->count());

        $this->asClient();
        $this->getJson(self::CLIENT.'/services')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $uuid)
            ->assertJsonPath('data.0.starting_price_minor', 20000)->assertJsonPath('data.0.delivery_days', 3)->assertJsonPath('data.0.freelancer.name', 'Sara');
        $this->getJson(self::CLIENT."/services/{$uuid}")->assertOk()->assertJsonPath('data.tiers.1.revisions', 3)->assertJsonMissingPath('data.status')
            ->assertJsonPath('data.freelancer.completed_projects', 0)->assertJsonPath('data.answer_hours', 48);
    }

    /** Open across countries (owner, 2026-11-04): a client of another open country finds the service, with its country; a closed one cannot. */
    public function test_a_client_of_another_open_country_finds_the_service_with_its_country(): void
    {
        $s = $this->published();
        $egypt = Country::create(['code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'status' => true, 'flag_id' => Flag::create(['code' => 'eg'])->id, 'currency_id' => Currency::create(['code' => 'EGP', 'symbol' => 'EGP'])->id]);
        foreach (['freelance', 'projects', 'services'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $egypt->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        $hany = User::query()->create(['name' => 'Hany', 'phone' => '+201000000001', 'country_id' => $egypt->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($hany, [], 'user_api');

        $this->getJson(self::CLIENT.'/services')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $s->uuid)->assertJsonPath('data.0.country.code', 'SA');
        $this->getJson(self::CLIENT."/services/{$s->uuid}")->assertOk();

        // Services switched off in Saudi: nobody sees Saudi services any more.
        FreelanceFeatureRule::query()->where('country_id', $this->saudi->id)->where('feature', 'services')->update(['enabled' => false]);
        FreelanceFeatures::forget();
        $this->getJson(self::CLIENT.'/services')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_levels_must_make_sense_and_the_prices_follow_the_country_limits(): void
    {
        $this->asSara();
        $this->postJson(self::API.'/services', $this->body(['tiers' => [['level' => 'standard', 'price_minor' => 30000, 'delivery_days' => 3]]]))
            ->assertStatus(422)->assertJsonPath('error_code', 'freelance_service_tiers_basic');
        $this->postJson(self::API.'/services', $this->body(['tiers' => [
            ['level' => 'basic', 'price_minor' => 30000, 'delivery_days' => 3], ['level' => 'standard', 'price_minor' => 30000, 'delivery_days' => 5],
        ]]))->assertStatus(422)->assertJsonPath('error_code', 'freelance_service_tiers_order');
        $this->postJson(self::API.'/services', $this->body(['tiers' => [['level' => 'basic', 'price_minor' => 100, 'delivery_days' => 3], ['level' => 'basic', 'price_minor' => 200, 'delivery_days' => 3]]]))
            ->assertStatus(422)->assertJsonValidationErrors('tiers.0.level');

        $this->policy(['project.min_budget_minor' => 25000]);
        $this->postJson(self::API.'/services', $this->body())->assertStatus(422)->assertJsonPath('error_code', 'freelance_service_below_min');
        $this->assertSame(0, FreelanceService::query()->count());
    }

    public function test_only_a_verified_freelancer_offers_services_and_only_so_many_at_once(): void
    {
        $this->policy(['services.max_active' => 1]);
        $this->asSara();
        $one = $this->postJson(self::API.'/services', $this->body())->json('data.uuid');
        $two = $this->postJson(self::API.'/services', $this->body(['title' => 'A second logo offer']))->json('data.uuid');

        $this->sara->forceFill(['status' => 'profile_ready'])->save();
        $this->asSara();
        $this->postJson(self::API."/services/{$one}/submit")->assertStatus(403)->assertJsonPath('error_code', 'freelance_service_unverified');

        $this->sara->forceFill(['status' => 'verified'])->save();
        $this->asSara();
        $this->postJson(self::API."/services/{$one}/submit")->assertOk();
        $this->postJson(self::API."/services/{$two}/submit")->assertStatus(422)->assertJsonPath('error_code', 'freelance_service_limit')->assertJsonPath('data.max', 1);
        $this->getJson(self::API.'/services')->assertOk()->assertJsonPath('data.counts.pending_review', 1)->assertJsonPath('data.counts.draft', 1)
            ->assertJsonPath('data.currency', 'SAR')->assertJsonPath('data.decimals', 2)->assertJsonPath('data.limits.max_active', 1)->assertJsonPath('data.review', true)->assertJsonPath('data.answer_hours', 48);
    }

    public function test_without_review_it_is_published_at_once_and_with_review_an_edit_is_looked_at_again(): void
    {
        $this->policy(['services.require_review' => false]);
        $this->asSara();
        $uuid = $this->postJson(self::API.'/services', $this->body())->json('data.uuid');
        $this->postJson(self::API."/services/{$uuid}/submit")->assertOk()->assertJsonPath('data.status', 'published');
        $this->putJson(self::API."/services/{$uuid}", ['title' => 'A logo for your new shop'])->assertOk()->assertJsonPath('data.status', 'published');

        FreelancePolicy::query()->delete();
        FreelancePolicies::forget();
        $this->putJson(self::API."/services/{$uuid}", ['title' => 'A logo and a card for your shop'])->assertOk()->assertJsonPath('data.status', 'pending_review');
        $this->asClient();
        $this->getJson(self::CLIENT.'/services')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_pausing_hides_it_and_the_staff_can_send_one_back_or_stop_it(): void
    {
        $s = $this->published();
        $this->asSara();
        $this->postJson(self::API."/services/{$s->uuid}/pause")->assertOk()->assertJsonPath('data.status', 'paused');
        $this->asClient();
        $this->getJson(self::CLIENT."/services/{$s->uuid}")->assertNotFound();
        $this->asSara();
        $this->postJson(self::API."/services/{$s->uuid}/resume")->assertOk()->assertJsonPath('data.status', 'published');

        $this->staff('freelance-services.view', 'freelance-services.update');
        $this->postJson("/api/admin/v1/freelance-services/{$s->uuid}/suspend", ['reason' => 'Copied from another site'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.service.suspended')->count());
        $this->asSara();
        $this->putJson(self::API."/services/{$s->uuid}", ['title' => 'Something else entirely'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_service_invalid_state');
        $this->staff('freelance-services.view', 'freelance-services.update');
        $this->postJson("/api/admin/v1/freelance-services/{$s->uuid}/unsuspend")->assertOk()->assertJsonPath('data.status', 'paused');

        // Sent back with a reason: Sara edits it and sends it again.
        $this->asSara();
        $this->putJson(self::API."/services/{$s->uuid}", ['title' => 'My own logo work'])->assertOk()->assertJsonPath('data.status', 'pending_review');
        $this->staff('freelance-services.view', 'freelance-services.update');
        $this->postJson("/api/admin/v1/freelance-services/{$s->uuid}/reject", ['reason' => 'Show your own samples'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.service.rejected')->count());
        $this->asSara();
        $this->getJson(self::API."/services/{$s->uuid}")->assertOk()->assertJsonPath('data.status_reason', 'Show your own samples')->assertJsonPath('data.can.submit', true);
    }

    public function test_images_go_to_clients_as_links_that_expire(): void
    {
        $s = $this->published();
        $this->asSara();
        $this->post(self::API."/services/{$s->uuid}/images", ['image' => UploadedFile::fake()->image('logo.jpg', 600, 400)], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.images.0.path', fn ($p) => str_starts_with($p, "services/{$s->uuid}/images/"));

        $this->asClient();
        $url = $this->getJson(self::CLIENT."/services/{$s->uuid}")->assertOk()->json('data.images.0.url');
        $this->assertNotNull($url);
        $this->get($url)->assertOk();
        $this->get(strtok($url, '?'))->assertForbidden();
    }

    // -------------------------------------------------------------- ordering

    public function test_the_quote_shows_the_fee_and_the_order_becomes_a_project_with_the_agreement_sent(): void
    {
        $s = $this->published();
        $this->asClient();
        $addon = $s->addons()->where('title', 'Source files')->value('id');
        // 40000 + 7000 = 47000, the client fee 2% = 940.
        $this->getJson(self::CLIENT."/services/{$s->uuid}/quote?level=standard&addons[]={$addon}")->assertOk()
            ->assertJsonPath('data.price_minor', 47000)->assertJsonPath('data.client_fee_minor', 940)->assertJsonPath('data.total_minor', 47940)
            ->assertJsonPath('data.delivery_days', 6)->assertJsonPath('data.revisions', 3);

        $quotaBefore = app(FreelancerOffers::class)->quota($this->sara)['left'];
        $res = $this->order($s, ['level' => 'standard', 'addons' => [$addon], 'note' => 'The shop sells honey.', 'request_id' => 'tap-1'])->assertCreated()
            ->assertJsonPath('data.status', 'awaiting')->assertJsonPath('data.price_minor', 47000)->assertJsonPath('data.money.total_minor', 47940)
            ->assertJsonPath('data.addons.0.title', 'Source files')->assertJsonPath('data.can.cancel', true)->assertJsonPath('data.tier_name', 'Shop');
        $this->assertNotNull($res->json('data.answer_by'));
        // The same tap again: the same order.
        $this->order($s, ['level' => 'standard', 'addons' => [$addon], 'note' => 'The shop sells honey.', 'request_id' => 'tap-1'])->assertOk()->assertJsonPath('data.uuid', $res->json('data.uuid'));
        $this->assertSame(1, FreelanceServiceOrder::query()->count());

        $project = FreelanceProject::query()->where('service_id', $s->id)->sole();
        $this->assertSame(FreelanceProjectStatus::Awarded, $project->status);
        $this->assertSame('private', $project->visibility);
        $offer = FreelanceOffer::query()->where('project_id', $project->id)->sole();
        $this->assertSame('accepted', $offer->status->value);
        $this->assertFalse((bool) $offer->free);
        $this->assertSame(4700, (int) $offer->commission_minor);
        $a = FreelanceAgreement::query()->where('project_id', $project->id)->sole();
        $this->assertSame(FreelanceAgreement::PENDING, $a->status);
        $this->assertSame(3, (int) $a->policy['review.free_revisions']);
        $this->assertTrue($a->expires_at->between(now()->addHours(47), now()->addHours(49)));
        $this->assertStringContainsString('Source files', (string) $a->includes);
        $this->assertSame(1, FreelanceMilestone::query()->where('agreement_id', $a->id)->count());

        // No offer of Sara's was spent, and she was told.
        $this->assertSame($quotaBefore, app(FreelancerOffers::class)->quota($this->sara->refresh())['left']);
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.order.received')->count());
        $this->asSara();
        $this->getJson(self::API.'/service-orders?status=awaiting')->assertOk()->assertJsonPath('data.0.money.net_minor', 42300)->assertJsonPath('data.0.can.accept', true)
            ->assertJsonPath('data.0.client.name', 'Mona')->assertJsonPath('data.0.note', 'The shop sells honey.');
    }

    public function test_the_order_keeps_what_was_bought_and_can_not_be_changed_by_the_client(): void
    {
        $s = $this->published();
        $uuid = $this->order($s)->assertCreated()->json('data.uuid');
        $this->asSara();
        $this->putJson(self::API."/services/{$s->uuid}", ['tiers' => [['level' => 'basic', 'name' => 'Starter', 'price_minor' => 90000, 'delivery_days' => 9]]])->assertOk();

        $this->asClient();
        $this->getJson(self::CLIENT."/service-orders/{$uuid}")->assertOk()->assertJsonPath('data.price_minor', 40000)->assertJsonPath('data.delivery_days', 5)
            ->assertJsonPath('data.features.1', 'PNG + SVG');
        $project = FreelanceProject::query()->where('service_id', $s->id)->sole();
        $this->putJson(self::CLIENT."/projects/{$project->uuid}/agreement", ['scope' => 'Now a whole website too'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_service_order_fixed');
        $this->putJson(self::CLIENT."/projects/{$project->uuid}/agreement/milestones", ['milestones' => [['title' => 'A', 'amount_minor' => 20000], ['title' => 'B', 'amount_minor' => 20000]]])
            ->assertStatus(409)->assertJsonPath('error_code', 'freelance_service_order_fixed');
        $this->getJson(self::CLIENT."/projects/{$project->uuid}/agreement")->assertOk()->assertJsonPath('data.can.edit', false)->assertJsonPath('data.service_order', $uuid);
    }

    public function test_accepted_it_runs_like_a_project_to_the_end(): void
    {
        $s = $this->published();
        $uuid = $this->order($s, ['level' => 'basic'])->assertCreated()->json('data.uuid');
        $project = FreelanceProject::query()->where('service_id', $s->id)->sole();

        $this->asSara();
        $this->postJson(self::API."/service-orders/{$uuid}/accept")->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame(FreelanceProjectStatus::InProgress, $project->refresh()->status);
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.agreement.active')->count());

        $part = FreelanceMilestone::query()->whereHas('agreement', fn ($q) => $q->where('project_id', $project->id))->sole();
        $this->asClient();
        $this->topUp(100000);
        $this->postJson(self::CLIENT."/projects/{$project->uuid}/milestones/{$part->uuid}/fund")->assertOk();
        $this->asSara();
        $this->postJson(self::API."/projects/{$project->uuid}/milestones/{$part->uuid}/start")->assertOk();
        $this->postJson(self::API."/projects/{$project->uuid}/milestones/{$part->uuid}/deliver", ['note' => 'Here is the logo.'])->assertOk();
        $this->asClient();
        $this->postJson(self::CLIENT."/projects/{$project->uuid}/milestones/{$part->uuid}/approve")->assertOk();

        $this->getJson(self::CLIENT."/service-orders/{$uuid}")->assertOk()->assertJsonPath('data.status', 'completed');
        $this->getJson(self::CLIENT.'/services')->assertOk()->assertJsonPath('data.0.completed_orders', 1);
        $this->asSara();
        $this->getJson(self::API.'/service-orders?status=completed')->assertOk()->assertJsonCount(1, 'data');
        // The client can't take back what is already under way.
        $this->asClient();
        $this->postJson(self::CLIENT."/service-orders/{$uuid}/cancel")->assertStatus(409);
    }

    public function test_declined_unanswered_or_taken_back_the_order_ends_and_its_project_with_it(): void
    {
        $s = $this->published();
        $declined = $this->order($s, ['level' => 'basic', 'request_id' => 'a'])->json('data.uuid');
        $expired = $this->order($s, ['level' => 'basic', 'request_id' => 'b'])->json('data.uuid');
        $cancelled = $this->order($s, ['level' => 'basic', 'request_id' => 'c'])->json('data.uuid');

        $this->asSara();
        $this->postJson(self::API."/service-orders/{$declined}/decline", ['reason' => 'Fully booked this week'])->assertOk()->assertJsonPath('data.status', 'declined');
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.agreement.declined')->count());

        $this->asClient();
        $this->postJson(self::CLIENT."/service-orders/{$cancelled}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->travel(49)->hours();
        app(Agreements::class)->expireDue();
        $this->getJson(self::CLIENT."/service-orders/{$expired}")->assertOk()->assertJsonPath('data.status', 'expired')->assertJsonPath('data.answer_by', null);

        $this->assertSame(3, FreelanceProject::query()->where('service_id', $s->id)->where('status', FreelanceProjectStatus::Cancelled->value)->count());
        $this->getJson(self::CLIENT.'/service-orders?status=ended')->assertOk()->assertJsonCount(3, 'data');
        // Nothing going on it any more: it can be deleted, and the orders stay.
        $this->asSara();
        $this->deleteJson(self::API."/services/{$s->uuid}")->assertOk();
        $this->getJson(self::API.'/service-orders')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.service.title', 'A logo for your shop');
    }

    public function test_a_service_with_orders_going_is_not_deleted(): void
    {
        $s = $this->published();
        $this->order($s)->assertCreated();
        $this->asSara();
        $this->deleteJson(self::API."/services/{$s->uuid}")->assertStatus(409)->assertJsonPath('error_code', 'freelance_service_has_orders');
        $this->getJson(self::API."/services/{$s->uuid}")->assertOk()->assertJsonPath('data.orders.awaiting', 1)->assertJsonPath('data.can.delete', false);
    }

    public function test_what_the_freelancer_asks_is_answered_and_no_contact_goes_in_the_note(): void
    {
        $s = $this->published(['requirements' => 'What does your shop sell?']);
        $this->order($s)->assertStatus(422)->assertJsonPath('error_code', 'freelance_service_note_required');
        $this->order($s, ['level' => 'basic', 'note' => 'Honey — call me on 0501234567'])->assertStatus(422);
        $this->order($s, ['level' => 'premium', 'addons' => [999999], 'note' => 'Honey'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_service_addon_unknown');
        $this->order($s, ['level' => 'basic', 'note' => 'Honey and dates'])->assertCreated();
    }

    public function test_a_freelancer_who_is_no_longer_verified_takes_no_orders(): void
    {
        $s = $this->published();
        $this->sara->forceFill(['status' => 'suspended'])->save();
        $this->order($s)->assertNotFound();
        $this->asClient();
        $this->getJson(self::CLIENT.'/services')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_switch_hides_services_but_the_orders_carry_on(): void
    {
        $s = $this->published();
        $uuid = $this->order($s)->assertCreated()->json('data.uuid');
        FreelanceFeatureRule::query()->where('feature', 'services')->update(['enabled' => false]);
        FreelanceFeatures::forget();

        $this->asClient();
        $this->getJson(self::CLIENT.'/services')->assertStatus(403)->assertJsonPath('error_code', 'freelance_feature_off');
        $this->getJson(self::CLIENT."/service-orders/{$uuid}")->assertOk();
        $this->asSara();
        $this->getJson(self::API.'/services')->assertStatus(403);
        $this->postJson(self::API."/service-orders/{$uuid}/accept")->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_browsing_filters_and_sorts(): void
    {
        $this->published();
        $this->published(['title' => 'A quick banner', 'description' => 'One wide banner for the top of your page, ready the next day in the sizes you need.', 'tiers' => [['level' => 'basic', 'name' => 'Banner', 'price_minor' => 9000, 'delivery_days' => 1]]]);
        $this->asClient();
        $this->getJson(self::CLIENT.'/services?sort=price_low')->assertOk()->assertJsonPath('data.0.title', 'A quick banner');
        $this->getJson(self::CLIENT.'/services?sort=price_high')->assertOk()->assertJsonPath('data.0.title', 'A logo for your shop');
        $this->getJson(self::CLIENT.'/services?max_price=10000')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::CLIENT.'/services?max_days=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'A quick banner');
        $this->getJson(self::CLIENT.'/services?search=logo')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::CLIENT.'/services?freelancer='.$this->sara->uuid)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_desk_needs_its_permissions_and_lists_the_orders(): void
    {
        $s = $this->published();
        $this->order($s)->assertCreated();

        $this->staff();
        $this->getJson('/api/admin/v1/freelance-services')->assertForbidden();
        $this->staff('freelance-services.view');
        $this->getJson("/api/admin/v1/freelance-services/{$s->uuid}")->assertOk()->assertJsonPath('data.freelancer.name', 'Sara')->assertJsonPath('data.orders.awaiting', 1);
        $this->postJson("/api/admin/v1/freelance-services/{$s->uuid}/suspend", ['reason' => 'Breaks the rules'])->assertForbidden();
        $this->getJson('/api/admin/v1/freelance-services/orders?status=awaiting')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.client.name', 'Mona')->assertJsonPath('data.0.money.commission_minor', 4000);
        $this->getJson('/api/admin/v1/freelance-services/orders?search=Mona')->assertOk()->assertJsonCount(1, 'data');
    }
}
