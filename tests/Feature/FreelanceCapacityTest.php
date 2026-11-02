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
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\FreelanceWaitlistEntry;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FL-L10: a freelancer caps how many projects run at once — only running work counts — and at the cap clients wait in line for their
 * services. A freed place goes to the oldest in line, kept for them for a while; starting work never passes the cap.
 */
class FreelanceCapacityTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1';

    private const CLIENT = '/api/mobile/v1/freelance';

    private Country $saudi;

    private FreelanceSpecialty $web;

    private Freelancer $sara;

    private User $mona;

    private User $khaled;

    private User $lina;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'wallet_payment', 'commission', 'client_fee', 'services'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();

        $this->web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $this->web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->mona = $this->client('Mona', '+966500000001');
        $this->khaled = $this->client('Khaled', '+966500000002');
        $this->lina = $this->client('Lina', '+966500000003');
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

    private function published(): FreelanceService
    {
        $this->asSara();
        $uuid = $this->postJson(self::API.'/services', [
            'title' => 'A logo for your shop',
            'description' => 'I draw a clean logo that fits your shop, with the colours and the files you need for print and the web.',
            'specialty_id' => $this->web->id,
            'tiers' => [['level' => 'basic', 'name' => 'Starter', 'price_minor' => 20000, 'delivery_days' => 3, 'revisions' => 1, 'features' => ['One idea', 'PNG']]],
        ])->assertCreated()->json('data.uuid');
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

    private function cap(?int $max): array
    {
        $this->asSara();

        return $this->putJson(self::API.'/capacity', ['max' => $max])->assertOk()->json('data');
    }

    private function order(User $u, FreelanceService $s)
    {
        $this->as($u);

        return $this->postJson(self::CLIENT."/services/{$s->uuid}/order", ['level' => 'basic']);
    }

    private function accept(string $orderUuid)
    {
        $this->asSara();

        return $this->postJson(self::API."/service-orders/{$orderUuid}/accept");
    }

    /** Mona's order accepted: one project runs. */
    private function oneRunning(FreelanceService $s): string
    {
        $uuid = $this->order($this->mona, $s)->assertCreated()->json('data.uuid');
        $this->accept($uuid)->assertOk()->assertJsonPath('data.status', 'active');

        return $uuid;
    }

    private function finishRunning(): void
    {
        FreelanceAgreement::query()->where('status', FreelanceAgreement::ACTIVE)->firstOrFail()->update(['status' => FreelanceAgreement::COMPLETED, 'completed_at' => now()]);
    }

    private function capacityOf(User $u, FreelanceService $s): array
    {
        $this->as($u);

        return $this->getJson(self::CLIENT."/services/{$s->uuid}")->assertOk()->json('data.capacity');
    }

    // -------------------------------------------------------------- the cap

    public function test_the_freelancer_sets_a_cap_within_the_limit_and_sees_what_runs(): void
    {
        $this->asSara();
        $this->getJson(self::API.'/capacity')->assertOk()->assertJsonPath('data.max', null)->assertJsonPath('data.running', 0)->assertJsonPath('data.full', false);
        $this->putJson(self::API.'/capacity', ['max' => 99])->assertStatus(422)->assertJsonPath('error_code', 'freelance_capacity_too_high');
        $this->assertSame(3, $this->cap(3)['max']);
        $this->assertNull($this->cap(null)['max']);
    }

    public function test_only_running_work_counts_and_starting_never_passes_the_cap(): void
    {
        $s = $this->published();
        $this->cap(1);

        // Two orders waiting for an answer do not fill the cap: both clients could order.
        $first = $this->order($this->mona, $s)->assertCreated()->json('data.uuid');
        $second = $this->order($this->khaled, $s)->assertCreated()->json('data.uuid');

        $this->accept($first)->assertOk();
        $this->accept($second)->assertStatus(409)->assertJsonPath('error_code', 'freelance_capacity_full');
        $this->asSara();
        $this->getJson(self::API.'/capacity')->assertJsonPath('data.running', 1)->assertJsonPath('data.full', true);
    }

    public function test_at_the_cap_a_client_cannot_order_but_can_wait_in_line(): void
    {
        $s = $this->published();
        $this->cap(1);
        $this->oneRunning($s);

        $this->as($this->khaled);
        $this->getJson(self::CLIENT.'/services')->assertOk()->assertJsonPath('data.0.at_capacity', true);
        $this->order($this->khaled, $s)->assertStatus(409)->assertJsonPath('error_code', 'freelance_at_capacity')->assertJsonPath('data.waitlist', true);

        $this->as($this->khaled);
        $this->postJson(self::CLIENT."/services/{$s->uuid}/waitlist")->assertOk()->assertJsonPath('data.mine.status', 'waiting')->assertJsonPath('data.mine.position', 1);
        $this->postJson(self::CLIENT."/services/{$s->uuid}/waitlist")->assertStatus(422)->assertJsonPath('error_code', 'freelance_waitlist_already');
        $this->as($this->lina);
        $this->postJson(self::CLIENT."/services/{$s->uuid}/waitlist")->assertOk()->assertJsonPath('data.mine.position', 2)->assertJsonPath('data.waiting', 2);

        $this->assertSame(2, $this->sara->notifications()->where('data->event', 'freelance.waitlist.joined')->count());
        $this->asSara();
        $line = $this->getJson(self::API.'/capacity')->assertOk()->json('data.line');
        $this->assertSame(['Khaled', 'Lina'], array_column(array_column($line, 'client'), 'name'));
    }

    public function test_a_freed_place_goes_to_the_first_in_line_and_is_kept_for_them(): void
    {
        $s = $this->published();
        $this->cap(1);
        $this->oneRunning($s);
        foreach ([$this->khaled, $this->lina] as $u) {
            $this->as($u);
            $this->postJson(self::CLIENT."/services/{$s->uuid}/waitlist")->assertOk();
        }

        $this->finishRunning();

        $mine = $this->capacityOf($this->khaled, $s);
        $this->assertSame('offered', $mine['mine']['status']);
        $this->assertNotNull($mine['mine']['hold_until']);
        $this->assertFalse($mine['full'], 'the place is kept for Khaled');
        $this->assertSame(1, $this->khaled->notifications()->where('data->event', 'freelance.waitlist.turn')->count());

        // Everyone else still finds the freelancer full: the place is Khaled's.
        $this->assertTrue($this->capacityOf($this->lina, $s)['full']);
        $this->order($this->lina, $s)->assertStatus(409);

        $this->order($this->khaled, $s)->assertCreated();
        $this->assertSame('ordered', FreelanceWaitlistEntry::query()->where('client_id', $this->khaled->id)->value('status'));
    }

    public function test_an_unused_hold_passes_to_the_next_in_line(): void
    {
        $s = $this->published();
        $this->cap(1);
        $this->oneRunning($s);
        foreach ([$this->khaled, $this->lina] as $u) {
            $this->as($u);
            $this->postJson(self::CLIENT."/services/{$s->uuid}/waitlist")->assertOk();
        }
        $this->finishRunning();

        $this->travel(25)->hours();
        $this->artisan('freelance:sweep-waitlist')->assertSuccessful();

        $this->assertSame('expired', FreelanceWaitlistEntry::query()->where('client_id', $this->khaled->id)->value('status'));
        $this->assertSame('offered', $this->capacityOf($this->lina, $s)['mine']['status']);
    }

    public function test_leaving_with_a_kept_place_hands_it_on(): void
    {
        $s = $this->published();
        $this->cap(1);
        $this->oneRunning($s);
        foreach ([$this->khaled, $this->lina] as $u) {
            $this->as($u);
            $this->postJson(self::CLIENT."/services/{$s->uuid}/waitlist")->assertOk();
        }
        $this->finishRunning();

        $this->as($this->khaled);
        $this->deleteJson(self::CLIENT."/services/{$s->uuid}/waitlist")->assertOk()->assertJsonPath('data.mine', null);
        $this->deleteJson(self::CLIENT."/services/{$s->uuid}/waitlist")->assertStatus(404);
        $this->assertSame('offered', $this->capacityOf($this->lina, $s)['mine']['status']);
    }

    public function test_raising_the_cap_lets_the_line_in_and_no_cap_means_no_line(): void
    {
        $s = $this->published();
        $this->cap(1);
        $this->oneRunning($s);
        $this->as($this->khaled);
        $this->postJson(self::CLIENT."/services/{$s->uuid}/waitlist")->assertOk();

        $this->cap(2);
        $this->assertSame('offered', $this->capacityOf($this->khaled, $s)['mine']['status']);

        $this->cap(null);
        $this->as($this->lina);
        $this->assertSame(['full' => false, 'waiting' => 0, 'mine' => null], $this->capacityOf($this->lina, $s));
        $this->as($this->lina);
        $this->postJson(self::CLIENT."/services/{$s->uuid}/waitlist")->assertStatus(422)->assertJsonPath('error_code', 'freelance_waitlist_not_full');
    }
}
