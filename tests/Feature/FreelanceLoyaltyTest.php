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
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Offers\Loyalty;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Services\WalletService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FL-L09 (part B): a freelancer's loyalty program. They set the steps (within the platform's limits); a client who keeps coming back gets
 * the step they earned off a project offer, a service order and a consultation, by itself and at the freelancer's cost; the client sees
 * their steps, is told when one opens, and the staff read the programs.
 */
class FreelanceLoyaltyTest extends TestCase
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
        foreach (['freelance', 'projects', 'bidding', 'chat', 'wallet_payment', 'commission', 'client_fee', 'services', 'consultations', 'special_offers', 'loyalty'] as $feature) {
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

    private function topUp(int $minor): void
    {
        $w = app(WalletService::class);
        $w->credit($w->firstOrCreateWallet($this->mona, $this->saudi), $minor, WalletBucket::Withdrawable, WalletTransactionType::Topup);
    }

    /** A work Sara finished with the client, or (not completed) one still going. */
    private function work(?User $client = null, string $status = FreelanceAgreement::COMPLETED): FreelanceAgreement
    {
        $client ??= $this->mona;
        $p = FreelanceProject::query()->create([
            'client_id' => $client->id, 'country_id' => $this->saudi->id, 'title' => 'A logo for the shop', 'description' => 'Old work finished last month.',
            'budget_type' => 'fixed', 'budget_min_minor' => 30000, 'currency_code' => 'SAR', 'duration_days' => 5, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Closed,
        ]);
        $o = FreelanceOffer::query()->create(['project_id' => $p->id, 'freelancer_id' => $this->sara->id, 'price_minor' => 30000, 'currency_code' => 'SAR', 'duration_days' => 5, 'details' => 'Done.', 'status' => 'accepted']);

        return FreelanceAgreement::query()->create([
            'project_id' => $p->id, 'offer_id' => $o->id, 'client_id' => $client->id, 'freelancer_id' => $this->sara->id, 'scope' => 'A logo.',
            'price_minor' => 30000, 'currency_code' => 'SAR', 'duration_days' => 5, 'policy' => [], 'money' => [], 'status' => $status,
        ]);
    }

    /** A project of the client open for offers. */
    private function openProject(?User $client = null): FreelanceProject
    {
        return FreelanceProject::query()->create([
            'client_id' => ($client ?? $this->mona)->id, 'country_id' => $this->saudi->id, 'title' => 'A booking website', 'description' => 'A small site where patients pick a doctor and a time.',
            'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR', 'duration_days' => 30,
            'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
    }

    /** @param  list<array{after: int, discount_bp: int}>  $tiers */
    private function program(array $tiers = [['after' => 1, 'discount_bp' => 1000]], bool $enabled = true): void
    {
        $this->asSara();
        $this->putJson(self::API.'/loyalty', ['enabled' => $enabled, 'tiers' => $tiers])->assertOk();
    }

    private function service(string $billing = 'once'): string
    {
        $this->asSara();
        $uuid = $this->postJson(self::API.'/services', [
            'title' => 'A logo for your shop', 'description' => 'I draw a clean logo that fits your shop, with the colours and the files you need for print and the web.',
            'billing' => $billing,
            'tiers' => [
                ['level' => 'basic', 'name' => 'Starter', 'price_minor' => 20000, 'delivery_days' => 3, 'revisions' => 1, 'features' => ['One idea']],
                ['level' => 'standard', 'name' => 'Shop', 'price_minor' => 40000, 'delivery_days' => 5, 'revisions' => 3, 'features' => ['Three ideas']],
            ],
        ])->assertCreated()->json('data.uuid');
        $this->postJson(self::API."/services/{$uuid}/submit")->assertOk();
        $this->staff('freelance-services.view', 'freelance-services.update');
        $this->postJson("/api/admin/v1/freelance-services/{$uuid}/approve")->assertOk();

        return $uuid;
    }

    private function consultationListing(): string
    {
        $this->asSara();
        $listing = $this->postJson(self::API.'/consultations/offers', [
            'title' => 'Brand advice', 'about' => 'A call about your logo, colours and the next step.',
            'options' => [['minutes' => 30, 'price_minor' => 20000]], 'weekly' => [['day' => 1, 'from' => '09:00', 'to' => '11:00']], 'timezone' => 'UTC',
        ])->assertCreated()->json('data.uuid');
        $this->postJson(self::API."/consultations/offers/{$listing}/submit")->assertOk();
        $this->staff('freelance-consultations.view', 'freelance-consultations.update');
        $this->postJson("/api/admin/v1/freelance-consultation-offers/{$listing}/approve")->assertOk();

        return $listing;
    }

    // -------------------------------------------------------------- tests

    public function test_a_freelancer_sets_a_program_within_the_platform_limits(): void
    {
        $this->asSara();
        $this->getJson(self::API.'/loyalty')->assertOk()->assertJsonPath('data.available', true)->assertJsonPath('data.program', null)
            ->assertJsonPath('data.rules.max_discount_bp', 3000)->assertJsonPath('data.rules.max_tiers', 4)->assertJsonCount(3, 'data.presets')
            ->assertJsonPath('data.presets.1.key', 'balanced')->assertJsonPath('data.presets.1.tiers.2.discount_bp', 1500)->assertJsonPath('data.stats.uses', 0);

        $this->putJson(self::API.'/loyalty', ['enabled' => true, 'tiers' => []])->assertStatus(422);
        $this->putJson(self::API.'/loyalty', ['enabled' => true, 'tiers' => [['after' => 2, 'discount_bp' => 5000]]])
            ->assertStatus(422)->assertJsonPath('error_code', 'freelance_loyalty_discount_invalid');
        $this->putJson(self::API.'/loyalty', ['enabled' => true, 'tiers' => [['after' => 0, 'discount_bp' => 500]]])
            ->assertStatus(422)->assertJsonPath('error_code', 'freelance_loyalty_after_invalid');
        // A client who comes back more never gets less.
        $this->putJson(self::API.'/loyalty', ['enabled' => true, 'tiers' => [['after' => 2, 'discount_bp' => 1000], ['after' => 5, 'discount_bp' => 500]]])
            ->assertStatus(422)->assertJsonPath('error_code', 'freelance_loyalty_tiers_order');
        $this->putJson(self::API.'/loyalty', ['enabled' => true, 'tiers' => collect(range(1, 5))->map(fn ($i) => ['after' => $i, 'discount_bp' => $i * 100])->all()])
            ->assertStatus(422)->assertJsonPath('error_code', 'freelance_loyalty_tiers_many');
        $this->assertNull($this->getJson(self::API.'/loyalty')->json('data.program'));

        // The steps are kept first step first, whatever order they came in.
        $this->putJson(self::API.'/loyalty', ['enabled' => true, 'tiers' => [['after' => 5, 'discount_bp' => 1000], ['after' => 2, 'discount_bp' => 500]]])
            ->assertOk()->assertJsonPath('data.program.enabled', true)->assertJsonPath('data.program.tiers.0.after', 2)->assertJsonPath('data.program.tiers.1.discount_bp', 1000);
        $this->postJson(self::API.'/loyalty/disable')->assertOk()->assertJsonPath('data.program.enabled', false)->assertJsonPath('data.program.tiers.1.after', 5);
        $this->postJson(self::API.'/loyalty/enable')->assertOk()->assertJsonPath('data.program.enabled', true);

        // The platform draws the limits.
        $this->policy(['loyalty.max_discount_bp' => 1000, 'loyalty.max_tiers' => 2]);
        $this->getJson(self::API.'/loyalty')->assertOk()->assertJsonPath('data.rules.max_discount_bp', 1000)->assertJsonPath('data.presets.1.tiers.1.discount_bp', 1000)->assertJsonCount(2, 'data.presets.1.tiers');
        $this->putJson(self::API.'/loyalty', ['enabled' => true, 'tiers' => [['after' => 2, 'discount_bp' => 1500]]])
            ->assertStatus(422)->assertJsonPath('error_code', 'freelance_loyalty_discount_invalid');
    }

    public function test_a_returning_client_gets_their_step_off_a_project_offer(): void
    {
        $this->work();
        $this->program([['after' => 1, 'discount_bp' => 1000], ['after' => 3, 'discount_bp' => 2000]]);
        $project = $this->openProject();

        // Sara sees it before she sends: the price the client pays and what is left for her.
        $this->getJson(self::API."/projects/{$project->uuid}/offer-preview?price_minor=100000")->assertOk()
            ->assertJsonPath('data.loyalty.bp', 1000)->assertJsonPath('data.loyalty.works', 1)->assertJsonPath('data.breakdown.price_minor', 90000)
            ->assertJsonPath('data.breakdown.list_price_minor', 100000)->assertJsonPath('data.breakdown.saving_minor', 10000)
            ->assertJsonPath('data.breakdown.commission_minor', 9000)->assertJsonPath('data.breakdown.net_minor', 81000);

        $uuid = $this->postJson(self::API."/projects/{$project->uuid}/offers", ['price_minor' => 100000, 'duration_days' => 21, 'details' => 'I built three booking sites like this; I will deliver in three steps with a demo each week.'])
            ->assertCreated()->assertJsonPath('data.price_minor', 90000)->assertJsonPath('data.list_price_minor', 100000)->assertJsonPath('data.loyalty_bp', 1000)
            ->assertJsonPath('data.saving_minor', 10000)->assertJsonPath('data.net_minor', 81000)->json('data.uuid');

        // Editing the price edits the price asked: the step comes off the new one.
        $this->putJson(self::API."/offers/{$uuid}", ['price_minor' => 120000])->assertOk()
            ->assertJsonPath('data.price_minor', 108000)->assertJsonPath('data.list_price_minor', 120000)->assertJsonPath('data.net_minor', 97200);
        $this->putJson(self::API."/offers/{$uuid}", ['price_minor' => 120000])->assertStatus(422)->assertJsonPath('error_code', 'freelance_offer_unchanged');

        // The client sees the price they pay with the usual one struck through.
        $this->asClient();
        $this->getJson(self::CLIENT."/projects/{$project->uuid}/offers")->assertOk()->assertJsonPath('data.0.price_minor', 108000)
            ->assertJsonPath('data.0.list_price_minor', 120000)->assertJsonPath('data.0.loyalty_bp', 1000)->assertJsonMissingPath('data.0.net_minor');

        // A client who never finished work with her gets nothing off.
        $tom = User::query()->create(['name' => 'Tom', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $other = $this->openProject($tom);
        $this->asSara();
        $this->getJson(self::API."/projects/{$other->uuid}/offer-preview?price_minor=100000")->assertOk()->assertJsonPath('data.loyalty', null)->assertJsonPath('data.breakdown.loyalty_bp', null);
        $this->postJson(self::API."/projects/{$other->uuid}/offers", ['price_minor' => 100000, 'duration_days' => 21, 'details' => 'I built three booking sites like this; I will deliver in three steps with a demo each week.'])
            ->assertCreated()->assertJsonPath('data.price_minor', 100000)->assertJsonPath('data.list_price_minor', null)->assertJsonPath('data.loyalty_bp', null);
    }

    public function test_a_service_order_and_a_consultation_take_the_step_and_the_numbers_add_up(): void
    {
        $this->work();
        $service = $this->service();
        $listing = $this->consultationListing();
        $this->program([['after' => 1, 'discount_bp' => 1000]]);

        $this->asClient();
        $this->getJson(self::CLIENT."/services/{$service}")->assertOk()->assertJsonPath('data.loyalty.current.discount_bp', 1000)
            ->assertJsonPath('data.tiers.1.price_minor', 40000)->assertJsonPath('data.tiers.1.loyalty_price_minor', 36000);
        $this->getJson(self::CLIENT."/services/{$service}/quote?level=standard")->assertOk()->assertJsonPath('data.price_minor', 36000)
            ->assertJsonPath('data.list_price_minor', 40000)->assertJsonPath('data.loyalty_bp', 1000)->assertJsonPath('data.saving_minor', 4000)->assertJsonPath('data.client_fee_minor', 720);
        $order = $this->postJson(self::CLIENT."/services/{$service}/order", ['level' => 'standard'])->assertCreated()
            ->assertJsonPath('data.price_minor', 36000)->assertJsonPath('data.list_price_minor', 40000)->assertJsonPath('data.loyalty_bp', 1000)->assertJsonPath('data.saving_minor', 4000)
            ->assertJsonPath('data.money.total_minor', 36720)->json('data.uuid');
        $this->asSara();
        $this->getJson(self::API."/service-orders/{$order}")->assertOk()->assertJsonPath('data.price_minor', 36000)->assertJsonPath('data.money.net_minor', 32400);

        // The consultation: every length carries the step, and the booking keeps both prices.
        $this->asClient();
        $this->getJson(self::CLIENT."/consultation-offers/{$listing}")->assertOk()->assertJsonPath('data.options.0.price_minor', 18000)
            ->assertJsonPath('data.options.0.list_price_minor', 20000)->assertJsonPath('data.options.0.loyalty_bp', 1000)->assertJsonPath('data.loyalty.next', null);
        $this->topUp(100000);
        $this->postJson(self::CLIENT.'/consultations', ['offer' => $listing, 'minutes' => 30, 'starts_at' => '2026-11-02T09:00:00Z', 'topic' => 'The colours of the new cards.', 'recording_consent' => true])
            ->assertCreated()->assertJsonPath('data.list_price_minor', 20000)->assertJsonPath('data.loyalty_bp', 1000)->assertJsonPath('data.saving_minor', 2000)
            ->assertJsonPath('data.money.price_minor', 18000);

        // Her numbers: one service order took a discount of 4000 (the consultation counts once she accepts it).
        $this->asSara();
        $this->getJson(self::API.'/loyalty')->assertOk()->assertJsonPath('data.stats.uses', 1)->assertJsonPath('data.stats.saved_minor', 4000)
            ->assertJsonPath('data.stats.past_clients', 1)->assertJsonPath('data.stats.in_program', 1)->assertJsonPath('data.stats.by_tier.0.clients', 1)
            ->assertJsonPath('data.clients.0.name', 'Mona')->assertJsonPath('data.clients.0.works', 1)->assertJsonPath('data.clients.0.current.discount_bp', 1000)
            ->assertJsonMissingPath('data.clients.0.phone');

        // The staff read the programs and the totals.
        $this->staff('freelance-loyalty.view');
        $this->getJson('/api/admin/v1/freelance-loyalty')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.freelancer.name', 'Sara')
            ->assertJsonPath('data.0.enabled', true)->assertJsonPath('totals.freelancers', 1)->assertJsonPath('data.0.uses', 1)->assertJsonPath('data.0.saved_minor', 4000)
            ->assertJsonPath('totals.uses', 1)->assertJsonPath('totals.saved.0.saved_minor', 4000)->assertJsonPath('totals.saved.0.currency', fn ($c) => is_string($c) && $c !== '');

        // Another permission (even the old project one) does not open it.
        $this->staff('freelance-projects.view');
        $this->getJson('/api/admin/v1/freelance-loyalty')->assertForbidden();
    }

    public function test_a_special_offer_price_is_never_put_under_a_loyalty_discount_and_a_monthly_service_stays_out(): void
    {
        $this->work();
        $listing = $this->consultationListing();
        $this->program([['after' => 1, 'discount_bp' => 1000]]);

        $this->asSara();
        $key = (string) collect($this->getJson(self::API.'/special-offers/clients')->assertOk()->json('data'))->firstWhere('name', 'Mona')['key'];
        $special = $this->postJson(self::API.'/special-offers', ['client' => $key, 'kind' => 'consultation', 'consultation' => $listing, 'discount_bp' => 2500])->assertCreated()->json('data.uuid');
        $this->asClient();
        $this->topUp(100000);
        $result = $this->postJson(self::CLIENT."/special-offers/{$special}/accept", ['minutes' => 30, 'starts_at' => '2026-11-02T09:00:00Z', 'topic' => 'The colours of the new cards.', 'recording_consent' => true])
            ->assertOk()->json('data.result');
        $c = FreelanceConsultation::query()->where('uuid', $result['consultation'])->firstOrFail();
        // The offer's 25% is the whole discount: 15000, not 13500.
        $this->assertSame(15000, (int) $c->price_minor);
        $this->assertNull($c->loyalty_bp);
        $this->assertNull($c->list_price_minor);

        // A monthly service keeps one price for every month it runs.
        $monthly = $this->service('monthly');
        $this->asClient();
        $this->getJson(self::CLIENT."/services/{$monthly}")->assertOk()->assertJsonPath('data.loyalty', null)->assertJsonPath('data.tiers.0.loyalty_price_minor', null);
        $this->getJson(self::CLIENT."/services/{$monthly}/quote?level=basic")->assertOk()->assertJsonPath('data.price_minor', 20000)->assertJsonPath('data.loyalty_bp', null);
    }

    public function test_a_client_sees_their_steps_and_is_told_when_one_opens(): void
    {
        $this->work();
        $this->program([['after' => 1, 'discount_bp' => 500], ['after' => 2, 'discount_bp' => 1000], ['after' => 4, 'discount_bp' => 1500]]);

        $this->asClient();
        $row = $this->getJson(self::CLIENT.'/loyalty')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.freelancer.name', 'Sara')
            ->assertJsonPath('data.0.works', 1)->assertJsonPath('data.0.current.discount_bp', 500)->assertJsonPath('data.0.next.after', 2)->assertJsonPath('data.0.next.remaining', 1)
            ->assertJsonPath('data.0.tiers.0.reached', true)->assertJsonPath('data.0.tiers.1.reached', false)->json('data.0');
        $this->assertEqualsWithDelta(0.0, $row['progress'], 0.001);

        // Before they have worked together: the plain ladder, nothing held yet.
        $tom = User::query()->create(['name' => 'Tom', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->asClient($tom);
        $this->getJson(self::CLIENT.'/loyalty')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::CLIENT."/freelancers/{$this->sara->uuid}/loyalty")->assertOk()->assertJsonPath('data.loyalty.works', 0)
            ->assertJsonPath('data.loyalty.current', null)->assertJsonPath('data.loyalty.next.after', 1)->assertJsonCount(3, 'data.loyalty.tiers');

        // A second work finished: the 10% step opens and Mona is told once.
        $second = $this->work(null, FreelanceAgreement::ACTIVE);
        $this->assertSame(0, $this->mona->notifications()->where('data->event', 'freelance.loyalty.unlocked')->count());
        $second->forceFill(['status' => FreelanceAgreement::COMPLETED, 'completed_at' => now()])->save();
        $this->assertSame(1, $this->mona->notifications()->where('data->event', 'freelance.loyalty.unlocked')->count());
        $this->asClient();
        $this->getJson(self::CLIENT.'/loyalty')->assertOk()->assertJsonPath('data.0.current.discount_bp', 1000)->assertJsonPath('data.0.next.after', 4);

        // A third lands between two steps: nothing new opens, nothing is sent.
        $this->work(null, FreelanceAgreement::ACTIVE)->forceFill(['status' => FreelanceAgreement::COMPLETED, 'completed_at' => now()])->save();
        $this->assertSame(1, $this->mona->notifications()->where('data->event', 'freelance.loyalty.unlocked')->count());
        $this->assertSame(3, app(Loyalty::class)->worksWith($this->sara, $this->mona->id));
    }

    public function test_a_program_that_is_off_or_a_country_with_the_switch_off_gives_nothing(): void
    {
        $this->work();
        $this->program([['after' => 1, 'discount_bp' => 1000]]);
        $project = $this->openProject();

        // Sara switches her program off: the client keeps no discount and the program is no longer listed.
        $this->asSara();
        $this->postJson(self::API.'/loyalty/disable')->assertOk();
        $this->getJson(self::API."/projects/{$project->uuid}/offer-preview?price_minor=100000")->assertOk()->assertJsonPath('data.loyalty', null)->assertJsonPath('data.breakdown.price_minor', 100000);
        $this->asClient();
        $this->getJson(self::CLIENT.'/loyalty')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::CLIENT."/freelancers/{$this->sara->uuid}/loyalty")->assertOk()->assertJsonPath('data.loyalty', null);

        // On again, then the country turns the feature off: no discount and her screen closes.
        $this->asSara();
        $this->postJson(self::API.'/loyalty/enable')->assertOk();
        $this->getJson(self::API."/projects/{$project->uuid}/offer-preview?price_minor=100000")->assertOk()->assertJsonPath('data.breakdown.price_minor', 90000);
        FreelanceFeatureRule::query()->where('feature', 'loyalty')->update(['enabled' => false]);
        FreelanceFeatures::forget();
        $this->getJson(self::API.'/loyalty')->assertForbidden();
        $this->getJson(self::API."/projects/{$project->uuid}/offer-preview?price_minor=100000")->assertOk()->assertJsonPath('data.loyalty', null)->assertJsonPath('data.breakdown.price_minor', 100000);
        $this->asClient();
        $this->getJson(self::CLIENT.'/loyalty')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_discount_rounds_to_the_nearest_minor_unit_and_never_below_one(): void
    {
        $this->assertSame(9000, Loyalty::discounted(10000, 1000));
        $this->assertSame(1425, Loyalty::discounted(1500, 500));
        // 333 minor units less 10% is 299.7: 300.
        $this->assertSame(300, Loyalty::discounted(333, 1000));
        $this->assertSame(1, Loyalty::discounted(1, 9000));
        $this->assertSame('15', Loyalty::percent(1500));
        $this->assertSame('2.5', Loyalty::percent(250));
    }
}
