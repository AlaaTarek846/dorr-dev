<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceService;
use Modules\Freelance\Models\FreelanceServiceOrder;
use Modules\Freelance\Models\FreelanceServiceSubscription;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FL-L05: a service sold by the month. Its order opens a subscription at kept terms; the client confirms (and pays) every next month;
 * ending it takes notice; not confirmed in time it ends; and it holds one place of the freelancer's capacity while it lasts.
 */
class FreelanceServiceSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1';

    private const CLIENT = '/api/mobile/v1/freelance';

    private Country $saudi;

    private FreelanceSpecialty $web;

    private Freelancer $sara;

    private User $mona;

    private User $khaled;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'wallet_payment', 'services'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();

        $this->web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $this->web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->mona = $this->client('Mona', '+966500000001');
        $this->khaled = $this->client('Khaled', '+966500000002');
    }

    // -------------------------------------------------------------- helpers

    private function client(string $name, string $phone): User
    {
        return User::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    private function asSara(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->sara->refresh(), [], 'freelance_api');
    }

    private function as(User $u): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u->refresh(), [], 'user_api');
    }

    private function serviceBody(int $days = 10): array
    {
        return [
            'title' => 'Social media every month',
            'description' => 'I plan and post your social media every month: twelve posts, the designs and a short report at the end of the month.',
            'specialty_id' => $this->web->id,
            'billing' => 'monthly',
            'tiers' => [['level' => 'basic', 'name' => 'Starter', 'price_minor' => 50000, 'delivery_days' => $days, 'revisions' => 1, 'features' => ['12 posts']]],
        ];
    }

    private function published(): FreelanceService
    {
        $this->asSara();
        $uuid = $this->postJson(self::API.'/services', $this->serviceBody())->assertCreated()->assertJsonPath('data.billing', 'monthly')->json('data.uuid');
        $this->postJson(self::API."/services/{$uuid}/submit")->assertOk();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'Staff', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach (['freelance-services.view', 'freelance-services.update'] as $p) {
            Permission::findOrCreate($p, 'admin_api');
            $admin->givePermissionTo($p);
        }
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');
        $this->postJson("/api/admin/v1/freelance-services/{$uuid}/approve")->assertOk();

        return FreelanceService::query()->where('uuid', $uuid)->firstOrFail();
    }

    /** Mona subscribes and Sara accepts the first month: the subscription is active. Returns its uuid. */
    private function subscribed(FreelanceService $s, ?User $u = null): string
    {
        $u ??= $this->mona;
        $this->as($u);
        $order = $this->postJson(self::CLIENT."/services/{$s->uuid}/order", ['level' => 'basic'])->assertCreated();
        $this->assertSame(1, $order->json('data.subscription.cycle'));
        $this->asSara();
        $this->postJson(self::API.'/service-orders/'.$order->json('data.uuid').'/accept')->assertOk();

        return (string) $order->json('data.subscription.uuid');
    }

    private function topUp(User $u, int $minor): void
    {
        $w = app(\Modules\Wallet\Services\WalletService::class);
        $w->credit($w->firstOrCreateWallet($u, $this->saudi), $minor, \Modules\Wallet\Enums\WalletBucket::Withdrawable, \Modules\Wallet\Enums\WalletTransactionType::Topup);
    }

    private function mine(string $uuid, ?User $u = null): array
    {
        $this->as($u ?? $this->mona);

        return $this->getJson(self::CLIENT."/service-subscriptions/{$uuid}")->assertOk()->json('data');
    }

    private function renew(string $uuid)
    {
        $this->as($this->mona);

        return $this->postJson(self::CLIENT."/service-subscriptions/{$uuid}/renew");
    }

    // -------------------------------------------------------------- tests

    public function test_a_monthly_service_delivers_within_the_month_and_says_so(): void
    {
        $this->asSara();
        $this->postJson(self::API.'/services', $this->serviceBody(31))->assertStatus(422)->assertJsonPath('error_code', 'freelance_service_monthly_days');

        $s = $this->published();
        $this->as($this->mona);
        $this->getJson(self::CLIENT.'/services')->assertOk()->assertJsonPath('data.0.billing', 'monthly');
    }

    public function test_the_first_month_accepted_starts_it_and_declined_ends_it(): void
    {
        $s = $this->published();
        $uuid = $this->subscribed($s);

        $sub = $this->mine($uuid);
        $this->assertSame('active', $sub['status']);
        $this->assertSame(1, $sub['cycle']);
        $this->assertFalse($sub['renew']['open'], 'confirming opens near the end of the month');
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, strtotime($sub['period_ends_at']), 5);

        $this->as($this->khaled);
        $order = $this->postJson(self::CLIENT."/services/{$s->uuid}/order", ['level' => 'basic'])->assertCreated()->json('data');
        $this->asSara();
        $this->postJson(self::API."/service-orders/{$order['uuid']}/decline")->assertOk();
        $this->assertSame('ended', $this->mine($order['subscription']['uuid'], $this->khaled)['status']);
        $this->assertSame('first_declined', FreelanceServiceSubscription::query()->where('client_id', $this->khaled->id)->value('end_reason'));
    }

    public function test_the_client_confirms_and_pays_the_next_month_at_the_kept_price(): void
    {
        $s = $this->published();
        $uuid = $this->subscribed($s);
        $this->renew($uuid)->assertStatus(409)->assertJsonPath('error_code', 'freelance_subscription_renew_closed');

        // Sara raises the price later: the subscription keeps its own.
        $s->tiers()->update(['price_minor' => 90000]);

        $this->travel(28)->days();
        $this->assertTrue($this->mine($uuid)['can']['renew']);
        $this->renew($uuid)->assertStatus(422)->assertJsonPath('error_code', 'freelance_wallet_insufficient');
        $this->assertSame(1, FreelanceServiceOrder::query()->where('client_id', $this->mona->id)->count(), 'nothing made without the money');

        $this->topUp($this->mona, 100000);
        $end = strtotime($this->mine($uuid)['period_ends_at']);
        $this->renew($uuid)->assertOk()->assertJsonPath('data.cycle', 2)->assertJsonPath('data.current.cycle', 2)->assertJsonPath('data.current.status', 'active');

        $second = FreelanceServiceOrder::query()->where('cycle', 2)->firstOrFail();
        $this->assertSame(50000, $second->price_minor);
        $this->assertSame(FreelanceAgreement::ACTIVE, $second->agreement->status);
        $this->assertSame('funded', $second->agreement->milestones()->first()->status->value);
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.retainer.renewed')->count());
        $this->assertSame($end + 30 * 86400, strtotime($this->mine($uuid)['period_ends_at']), 'the months keep their calendar');
        $this->renew($uuid)->assertStatus(409);
    }

    public function test_it_holds_one_place_of_capacity_and_its_next_month_starts_in_it(): void
    {
        $s = $this->published();
        $this->asSara();
        $this->putJson(self::API.'/capacity', ['max' => 1])->assertOk();
        $uuid = $this->subscribed($s);

        // The first month's work is done, yet the subscription still holds the place.
        FreelanceAgreement::query()->where('status', FreelanceAgreement::ACTIVE)->update(['status' => FreelanceAgreement::COMPLETED, 'completed_at' => now()]);
        $this->asSara();
        $this->getJson(self::API.'/capacity')->assertJsonPath('data.running', 1)->assertJsonPath('data.full', true);
        $this->as($this->khaled);
        $this->postJson(self::CLIENT."/services/{$s->uuid}/order", ['level' => 'basic'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_at_capacity');

        $this->travel(28)->days();
        $this->topUp($this->mona, 100000);
        $this->renew($uuid)->assertOk();
        $this->asSara();
        $this->getJson(self::API.'/capacity')->assertJsonPath('data.running', 1);
    }

    public function test_ending_takes_notice_and_can_be_taken_back(): void
    {
        $s = $this->published();
        $uuid = $this->subscribed($s);
        $end = $this->mine($uuid)['period_ends_at'];

        // Asked early: it ends with this month and the next is not offered.
        $this->as($this->mona);
        $this->postJson(self::CLIENT."/service-subscriptions/{$uuid}/cancel")->assertOk()->assertJsonPath('data.cancel.by', 'client')
            ->assertJsonPath('data.cancel.ends_at', $end)->assertJsonPath('data.renew', null);
        $this->postJson(self::CLIENT."/service-subscriptions/{$uuid}/cancel")->assertStatus(409)->assertJsonPath('error_code', 'freelance_subscription_cancel_already');
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.retainer.cancel_requested')->count());
        $this->asSara();
        $this->postJson(self::API."/service-subscriptions/{$uuid}/keep")->assertStatus(409)->assertJsonPath('error_code', 'freelance_subscription_keep_invalid');

        $this->as($this->mona);
        $this->postJson(self::CLIENT."/service-subscriptions/{$uuid}/keep")->assertOk()->assertJsonPath('data.cancel', null);

        // Asked late (inside the notice): it ends with the next month.
        $this->travel(26)->days();
        $this->asSara();
        $late = $this->postJson(self::API."/service-subscriptions/{$uuid}/cancel")->assertOk()->json('data');
        $this->assertSame(strtotime($end) + 30 * 86400, strtotime($late['cancel']['ends_at']));
        $this->travel(2)->days();
        $this->assertTrue($this->mine($uuid)['can']['renew'], 'the last month is still confirmed by the client');
    }

    public function test_the_sweep_reminds_once_and_ends_what_is_over(): void
    {
        $s = $this->published();
        $lapsing = $this->subscribed($s);
        $leaving = $this->subscribed($s, $this->khaled);
        $this->as($this->khaled);
        $this->postJson(self::CLIENT."/service-subscriptions/{$leaving}/cancel")->assertOk();

        $this->travel(28)->days();
        $this->artisan('freelance:sweep-subscriptions')->assertSuccessful();
        $this->artisan('freelance:sweep-subscriptions')->assertSuccessful();
        $this->assertSame(1, $this->mona->notifications()->where('data->event', 'freelance.retainer.renew_due')->count());
        $this->assertSame(0, $this->khaled->notifications()->where('data->event', 'freelance.retainer.renew_due')->count(), 'no reminder for a month that will not come');

        $this->travel(3)->days();
        $this->artisan('freelance:sweep-subscriptions')->assertSuccessful();
        $this->assertSame('cancelled_client', FreelanceServiceSubscription::query()->where('uuid', $leaving)->value('end_reason'));
        $this->assertSame('active', FreelanceServiceSubscription::query()->where('uuid', $lapsing)->value('status'), 'still inside the grace days');

        $this->travel(2)->days();
        $this->artisan('freelance:sweep-subscriptions')->assertSuccessful();
        $this->assertSame('not_renewed', FreelanceServiceSubscription::query()->where('uuid', $lapsing)->value('end_reason'));
        $this->assertSame(1, $this->mona->notifications()->where('data->event', 'freelance.retainer.ended')->count());
        $this->assertSame(2, $this->sara->notifications()->where('data->event', 'freelance.retainer.ended')->count());

        $this->asSara();
        $this->getJson(self::API.'/service-subscriptions?status=ended')->assertOk()->assertJsonCount(2, 'data');
    }
}
