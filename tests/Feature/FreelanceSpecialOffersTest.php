<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceConsultation;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelancePolicy;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceServiceOrder;
use Modules\Freelance\Models\FreelanceSpecialOffer;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Offers\SpecialOffers;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Services\WalletService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FL-L09 (part A): special offers to past clients. Only a client the freelancer finished work with can get one; it is custom work, a
 * service level at a lower price, or a consultation at a discount; accepting starts it at once (the offer is the freelancer's yes). Caps,
 * a pause after a no, a stop button for the client and no contact details keep it from being a nuisance.
 */
class FreelanceSpecialOffersTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1';

    private const CLIENT = '/api/mobile/v1/freelance';

    private Country $saudi;

    private User $mona;

    private Freelancer $sara;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
        // Monday 2 November 2026, 06:00 UTC.
        $this->travelTo(Carbon::parse('2026-11-02 06:00:00', 'UTC'));
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'chat', 'wallet_payment', 'commission', 'client_fee', 'services', 'consultations', 'special_offers'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();

        $this->mona = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
    }

    // -------------------------------------------------------------- helpers

    private function asSara(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->sara->refresh(), [], 'freelance_api');
    }

    private function asClient(?User $who = null): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(($who ?? $this->mona)->refresh(), [], 'user_api');
    }

    private function staff(string ...$permissions): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'Staff', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'admin_api');
            $admin->givePermissionTo($permission);
        }
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    private function policy(array $data): void
    {
        FreelancePolicy::query()->create(['country_id' => $this->saudi->id, 'specialty_id' => null, 'data' => $data, 'status' => true]);
        FreelancePolicies::forget();
    }

    /** A project the client and Sara finished together. */
    private function pastWork(?User $client = null, string $title = 'A logo for the shop'): void
    {
        $client ??= $this->mona;
        $p = FreelanceProject::query()->create([
            'client_id' => $client->id, 'country_id' => $this->saudi->id, 'title' => $title, 'description' => 'Old work finished last month.',
            'budget_type' => 'fixed', 'budget_min_minor' => 30000, 'currency_code' => 'SAR', 'duration_days' => 5, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Closed,
        ]);
        $o = FreelanceOffer::query()->create(['project_id' => $p->id, 'freelancer_id' => $this->sara->id, 'price_minor' => 30000, 'currency_code' => 'SAR', 'duration_days' => 5, 'details' => 'Done.', 'status' => 'accepted']);
        FreelanceAgreement::query()->create([
            'project_id' => $p->id, 'offer_id' => $o->id, 'client_id' => $client->id, 'freelancer_id' => $this->sara->id, 'scope' => 'A logo.',
            'price_minor' => 30000, 'currency_code' => 'SAR', 'duration_days' => 5, 'policy' => [], 'money' => [], 'status' => FreelanceAgreement::COMPLETED,
        ])->forceFill(['completed_at' => now()->subDays(10)])->save();
    }

    private function monaKey(): string
    {
        $this->asSara();

        return (string) collect($this->getJson(self::API.'/special-offers/clients')->assertOk()->json('data'))->firstWhere('name', 'Mona')['key'];
    }

    /** @return array<string, mixed> */
    private function custom(array $over = []): array
    {
        return $over + [
            'client' => $this->monaKey(), 'kind' => 'custom', 'title' => 'Business cards for the shop',
            'description' => 'Two-sided business cards that match the logo I made you, print-ready files and the source file.',
            'message' => 'Thanks for the last project, here is something for you.', 'price_minor' => 50000, 'delivery_days' => 6, 'revisions' => 2, 'valid_days' => 5,
        ];
    }

    private function send(array $body): \Illuminate\Testing\TestResponse
    {
        $this->asSara();

        return $this->postJson(self::API.'/special-offers', $body);
    }

    private function topUp(int $minor): void
    {
        $w = app(WalletService::class);
        $w->credit($w->firstOrCreateWallet($this->mona, $this->saudi), $minor, WalletBucket::Withdrawable, WalletTransactionType::Topup);
    }

    // -------------------------------------------------------------- tests

    public function test_only_a_client_the_freelancer_finished_work_with_can_get_one(): void
    {
        $this->asSara();
        $this->getJson(self::API.'/special-offers/clients')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(self::API.'/special-offers', ['client' => str_repeat('a', 32), 'kind' => 'custom'])
            ->assertStatus(422)->assertJsonPath('error_code', 'freelance_special_offer_client_unknown');

        $this->pastWork();
        $this->asSara();
        $this->getJson(self::API.'/special-offers/clients')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mona')->assertJsonPath('data.0.works', 1)->assertJsonPath('data.0.last_title', 'A logo for the shop')
            ->assertJsonPath('data.0.can_send', true)->assertJsonMissingPath('data.0.phone');
        $this->getJson(self::API.'/special-offers/compose')->assertOk()->assertJsonPath('data.kinds.custom', true)
            ->assertJsonPath('data.kinds.service', false)->assertJsonPath('data.today_left', 10)->assertJsonPath('data.currency', 'SAR');
        $this->getJson(self::API.'/special-offers/quote?price_minor=50000')->assertOk()
            ->assertJsonPath('data.commission_minor', 5000)->assertJsonPath('data.net_minor', 45000)->assertJsonPath('data.client_fee_minor', 1000);
    }

    public function test_custom_work_accepted_starts_a_private_project_with_its_agreement_active(): void
    {
        $this->pastWork();
        $uuid = $this->send($this->custom())->assertCreated()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.money.net_minor', 45000)
            ->assertJsonPath('data.can.withdraw', true)->json('data.uuid');
        $this->assertSame(1, $this->mona->notifications()->where('data->event', 'freelance.special_offer.received')->count());

        $this->asClient();
        $this->getJson(self::CLIENT.'/special-offers/waiting')->assertOk()->assertJsonPath('data.count', 1);
        $this->getJson(self::CLIENT.'/special-offers?tab=open')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('counts.open', 1);
        $this->getJson(self::CLIENT."/special-offers/{$uuid}")->assertOk()->assertJsonPath('data.freelancer.name', 'Sara')
            ->assertJsonPath('data.money.total_minor', 51000)->assertJsonPath('data.can.accept', true)->assertJsonPath('data.viewed_at', null);
        $this->asSara();
        $this->assertNotNull($this->getJson(self::API."/special-offers/{$uuid}")->json('data.viewed_at'));

        $this->asClient();
        $done = $this->postJson(self::CLIENT."/special-offers/{$uuid}/accept", ['request_id' => 'tap-1'])->assertOk()
            ->assertJsonPath('data.status', 'accepted')->json('data');
        $project = FreelanceProject::query()->where('uuid', $done['result']['project'])->firstOrFail();
        $this->assertSame(FreelanceProjectStatus::InProgress, $project->status);
        $this->assertSame('private', $project->visibility);
        $agreement = FreelanceAgreement::query()->where('project_id', $project->id)->firstOrFail();
        $this->assertSame(FreelanceAgreement::ACTIVE, $agreement->status);
        $this->assertSame(50000, (int) $agreement->price_minor);
        $this->assertSame(2, (int) $agreement->policy['review.free_revisions']);
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.special_offer.accepted')->count());

        // A second tap changes nothing.
        $this->postJson(self::CLIENT."/special-offers/{$uuid}/accept")->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertSame(1, FreelanceAgreement::query()->where('project_id', $project->id)->count());
        $this->getJson(self::CLIENT."/projects/{$project->uuid}/agreement")->assertOk()->assertJsonPath('data.special_offer', $uuid);
    }

    public function test_a_service_level_at_a_lower_price_becomes_an_order_already_accepted(): void
    {
        $this->pastWork();
        $this->asSara();
        $service = $this->postJson(self::API.'/services', [
            'title' => 'A logo for your shop', 'description' => 'I draw a clean logo that fits your shop, with the colours and the files you need for print and the web.',
            'tiers' => [
                ['level' => 'basic', 'name' => 'Starter', 'price_minor' => 20000, 'delivery_days' => 3, 'revisions' => 1, 'features' => ['One idea']],
                ['level' => 'standard', 'name' => 'Shop', 'price_minor' => 40000, 'delivery_days' => 5, 'revisions' => 3, 'features' => ['Three ideas']],
            ],
        ])->assertCreated()->json('data.uuid');
        $this->postJson(self::API."/services/{$service}/submit")->assertOk();
        $this->staff('freelance-services.view', 'freelance-services.update');
        $this->postJson("/api/admin/v1/freelance-services/{$service}/approve")->assertOk();

        $key = $this->monaKey();
        $this->getJson(self::API.'/special-offers/compose')->assertOk()->assertJsonPath('data.kinds.service', true)->assertJsonPath('data.services.0.levels.1.level', 'standard');
        $this->send(['client' => $key, 'kind' => 'service', 'service' => $service, 'level' => 'standard', 'price_minor' => 40000])
            ->assertStatus(422)->assertJsonPath('error_code', 'freelance_special_offer_price_not_lower');
        $uuid = $this->send(['client' => $key, 'kind' => 'service', 'service' => $service, 'level' => 'standard', 'price_minor' => 30000])->assertCreated()
            ->assertJsonPath('data.list_price_minor', 40000)->assertJsonPath('data.saving_minor', 10000)->assertJsonPath('data.discount_bp', 2500)
            ->assertJsonPath('data.service.tier_name', 'Shop')->assertJsonPath('data.revisions', 3)->json('data.uuid');

        $this->asClient();
        $result = $this->postJson(self::CLIENT."/special-offers/{$uuid}/accept")->assertOk()->json('data.result');
        $order = FreelanceServiceOrder::query()->where('uuid', $result['service_order'])->firstOrFail();
        $this->assertSame(30000, (int) $order->price_minor);
        $this->getJson(self::CLIENT."/service-orders/{$order->uuid}")->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_a_consultation_at_a_discount_is_booked_and_confirmed_at_once(): void
    {
        $this->pastWork();
        $this->asSara();
        $listing = $this->postJson(self::API.'/consultations/offers', [
            'title' => 'Brand advice', 'about' => 'A call about your logo, colours and the next step.',
            'options' => [['minutes' => 30, 'price_minor' => 20000]], 'weekly' => [['day' => 1, 'from' => '09:00', 'to' => '11:00']], 'timezone' => 'UTC',
        ])->assertCreated()->json('data.uuid');
        $this->postJson(self::API."/consultations/offers/{$listing}/submit")->assertOk();
        $this->staff('freelance-consultations.view', 'freelance-consultations.update');
        $this->postJson("/api/admin/v1/freelance-consultation-offers/{$listing}/approve")->assertOk();

        $key = $this->monaKey();
        $this->send(['client' => $key, 'kind' => 'consultation', 'consultation' => $listing, 'discount_bp' => 9500])
            ->assertStatus(422)->assertJsonPath('error_code', 'freelance_special_offer_discount_invalid');
        $uuid = $this->send(['client' => $key, 'kind' => 'consultation', 'consultation' => $listing, 'discount_bp' => 2500])->assertCreated()
            ->assertJsonPath('data.consultation.lengths.0.list_price_minor', 20000)->assertJsonPath('data.consultation.lengths.0.price_minor', 15000)->json('data.uuid');

        $this->asClient();
        $this->topUp(100000);
        $this->postJson(self::CLIENT."/special-offers/{$uuid}/accept", [])->assertStatus(422)->assertJsonValidationErrors(['minutes', 'starts_at', 'topic']);
        $result = $this->postJson(self::CLIENT."/special-offers/{$uuid}/accept", [
            'minutes' => 30, 'starts_at' => '2026-11-02T09:00:00Z', 'topic' => 'The colours of the new cards.', 'recording_consent' => true,
        ])->assertOk()->assertJsonPath('data.status', 'accepted')->json('data.result');
        $c = FreelanceConsultation::query()->where('uuid', $result['consultation'])->firstOrFail();
        $this->assertSame(FreelanceConsultation::BOOKED, $c->status);
        $this->assertSame(15000, (int) $c->price_minor);
        // No request for Sara to answer: her offer was her yes.
        $this->assertSame(0, $this->sara->notifications()->where('data->event', 'freelance.consultation.requested')->count());
    }

    public function test_one_open_offer_per_client_a_pause_after_a_no_and_the_client_can_stop_them(): void
    {
        $this->pastWork();
        $first = $this->send($this->custom())->assertCreated()->json('data.uuid');
        $this->send($this->custom(['request_id' => 'other']))->assertStatus(409)->assertJsonPath('error_code', 'freelance_special_offer_client_has_open');
        $this->getJson(self::API.'/special-offers/clients')->assertJsonPath('data.0.blocked_reason', 'has_open');

        // Withdrawn: room for another.
        $this->postJson(self::API."/special-offers/{$first}/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $second = $this->send($this->custom())->assertCreated()->json('data.uuid');

        // A no: a pause before the next one.
        $this->asClient();
        $this->postJson(self::CLIENT."/special-offers/{$second}/decline", ['reason' => 'Not now'])->assertOk()->assertJsonPath('data.status', 'declined');
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.special_offer.declined')->count());
        $this->send($this->custom())->assertStatus(409)->assertJsonPath('error_code', 'freelance_special_offer_cooldown');
        $this->travel(8)->days();
        $third = $this->send($this->custom())->assertCreated()->json('data.uuid');

        // Stopping her offers ends the one waiting and hides Mona from Sara.
        $this->asClient();
        $this->putJson(self::CLIENT."/special-offers/freelancers/{$this->sara->uuid}/mute", ['on' => true])->assertOk();
        $this->getJson(self::CLIENT."/special-offers/{$third}")->assertOk()->assertJsonPath('data.status', 'declined')->assertJsonPath('data.muted', true);
        $this->asSara();
        $this->getJson(self::API.'/special-offers/clients')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_daily_cap_contact_details_and_the_validity(): void
    {
        $this->policy(['special_offers.daily_limit' => 1, 'special_offers.max_valid_days' => 3]);
        $this->pastWork();
        $tom = User::query()->create(['name' => 'Tom', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->pastWork($tom, 'A flyer');

        $this->send($this->custom())->assertStatus(422)->assertJsonPath('error_code', 'freelance_special_offer_valid_days');
        $this->send($this->custom(['valid_days' => 3, 'message' => 'Call me on 0501234567']))->assertStatus(422)->assertJsonPath('error_code', 'freelance_offer_contact_blocked');
        $this->send($this->custom(['valid_days' => 3, 'description' => 'Too short']))->assertStatus(422)->assertJsonPath('error_code', 'freelance_special_offer_description_short');
        $this->send($this->custom(['valid_days' => 3, 'request_id' => 'tap-1']))->assertCreated();
        // The same tap again is the same offer, not a second one.
        $this->send($this->custom(['valid_days' => 3, 'request_id' => 'tap-1']))->assertOk();

        $tomKey = (string) collect($this->getJson(self::API.'/special-offers/clients')->json('data'))->firstWhere('name', 'Tom')['key'];
        $this->send($this->custom(['client' => $tomKey, 'valid_days' => 3]))->assertStatus(429)->assertJsonPath('error_code', 'freelance_special_offer_daily_limit');
        $this->assertSame(1, FreelanceSpecialOffer::query()->count());
    }

    public function test_the_clock_reminds_the_day_before_and_then_ends_it(): void
    {
        $this->pastWork();
        $uuid = $this->send($this->custom(['valid_days' => 2]))->assertCreated()->json('data.uuid');

        $this->travel(25)->hours();
        $this->assertSame(['expired' => 0, 'reminded' => 1], app(SpecialOffers::class)->sweep());
        $this->assertSame(['expired' => 0, 'reminded' => 0], app(SpecialOffers::class)->sweep());
        $this->assertSame(1, $this->mona->notifications()->where('data->event', 'freelance.special_offer.ending')->count());

        $this->travel(1)->days();
        $this->asClient();
        // Past its time it reads as expired even before the sweep, and can't be taken.
        $this->getJson(self::CLIENT."/special-offers/{$uuid}")->assertOk()->assertJsonPath('data.status', 'expired')->assertJsonPath('data.can.accept', false);
        $this->postJson(self::CLIENT."/special-offers/{$uuid}/accept")->assertStatus(409)->assertJsonPath('error_code', 'freelance_special_offer_not_open');
        $this->assertSame(['expired' => 1, 'reminded' => 0], app(SpecialOffers::class)->sweep());
    }

    public function test_the_switch_and_other_people(): void
    {
        $this->pastWork();
        $uuid = $this->send($this->custom())->assertCreated()->json('data.uuid');

        // Somebody else's offer is not found.
        $this->asClient(User::query()->create(['name' => 'Eve', 'phone' => '+966500000003', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]));
        $this->getJson(self::CLIENT."/special-offers/{$uuid}")->assertNotFound();
        $this->postJson(self::CLIENT."/special-offers/{$uuid}/accept")->assertNotFound();

        // The staff read them.
        $this->staff('freelance-special-offers.view');
        $this->getJson('/api/admin/v1/freelance-special-offers')->assertOk()->assertJsonPath('counts.sent', 1)->assertJsonPath('data.0.client.name', 'Mona');

        // Switched off: no new offers, but Mona can still answer the one she has.
        FreelanceFeatureRule::query()->where('feature', 'special_offers')->update(['enabled' => false]);
        FreelanceFeatures::forget();
        $this->asSara();
        $this->getJson(self::API.'/special-offers/clients')->assertStatus(403)->assertJsonPath('error_code', 'freelance_feature_off');
        $this->asClient();
        $this->postJson(self::CLIENT."/special-offers/{$uuid}/decline")->assertOk();
    }
}
