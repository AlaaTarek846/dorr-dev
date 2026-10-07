<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\AI\Exceptions\AiSiteException;
use Modules\AI\Http\Middleware\ServeHostedSiteMiddleware;
use Modules\AI\Models\AiSiteHosting;
use Modules\AI\Models\AiSiteHostingPlan;
use Modules\AI\Models\AiSiteProject;
use Modules\AI\Models\AiSiteVersion;
use Modules\AI\Services\Sites\AiSiteHostingService;
use Modules\AI\Services\Sites\AiSiteProjectService;
use Modules\AI\Services\Sites\AiSiteStorage;
use Modules\User\Models\User;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Services\WalletService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/** Paid hosting of a finished site: names, charging, publishing, serving, renewal and suspension. */
class AiSiteHostingTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->currency = Currency::create(['code' => 'EGP', 'symbol' => 'ج.م', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'eg']);
        $this->country = Country::create([
            'code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10,
            'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $this->currency->id, 'status' => true,
        ]);
        app()->instance('resolved_country', $this->country);
    }

    private function user(): User
    {
        return User::query()->create([
            'name' => 'Host User', 'email' => 'host-'.uniqid().'@example.test',
            'password' => bcrypt('not-a-real-password'), 'status' => UserStatus::Active,
        ]);
    }

    private function wallet(User $user, int $minor): Wallet
    {
        $wallet = app(WalletService::class)->firstOrCreateWallet($user, $this->country);
        $wallet->update(['spend_only_minor' => $minor, 'withdrawable_minor' => 0]);

        return $wallet->fresh();
    }

    /** A finished site with one completed version, built without the AI. */
    private function site(User $user, string $body = 'v1'): AiSiteProject
    {
        $project = AiSiteProject::query()->create([
            'owner_type' => $user->getMorphClass(), 'owner_id' => $user->id, 'title' => 'Site', 'slug' => \Illuminate\Support\Str::random(40),
            'status' => 'ready', 'access_type' => 'plan',
            'brief' => ['title' => 'Site', 'contact' => ['phone' => '+201001234567']],
        ]);

        return $this->addVersion($project, $body);
    }

    private function addVersion(AiSiteProject $project, string $body): AiSiteProject
    {
        $number = (int) $project->versions()->max('number') + 1;
        $version = AiSiteVersion::query()->create([
            'project_id' => $project->id, 'number' => $number, 'kind' => 'generate', 'status' => 'completed', 'counted' => true, 'completed_at' => now(),
        ]);
        $written = app(AiSiteStorage::class)->writeVersion($version, ['index.html' => "<!doctype html><html><body>{$body} {{PHONE}}</body></html>"]);
        $version->forceFill(['files' => $written['manifest'], 'total_bytes' => $written['total']])->save();
        $project->forceFill(['current_version_id' => $version->id])->save();

        return $project->fresh();
    }

    private function plan(string $period = 'monthly', float $price = 50): AiSiteHostingPlan
    {
        $plan = AiSiteHostingPlan::query()->create(['name' => ucfirst($period), 'code' => $period.'-'.uniqid(), 'period' => $period, 'is_active' => true]);
        $plan->prices()->create(['country_id' => $this->country->id, 'currency_id' => $this->currency->id, 'price' => $price]);

        return $plan;
    }

    private function host(User $user, AiSiteProject $project, ?AiSiteHostingPlan $plan = null, string $name = 'ahmed-plumbing'): AiSiteHosting
    {
        return app(AiSiteHostingService::class)->subscribe($user, $project, $plan ?? $this->plan(), $name);
    }

    private function reason(callable $fn): ?string
    {
        try {
            $fn();
        } catch (AiSiteException $e) {
            return $e->reason;
        }

        return null;
    }

    private function getRaw(string $uri): \Illuminate\Testing\TestResponse
    {
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);

        return \Illuminate\Testing\TestResponse::fromBaseResponse($kernel->handle(Request::create($uri)));
    }

    // ------------------------------------------------------------------ names

    public function test_name_rules(): void
    {
        $hostings = app(AiSiteHostingService::class);

        $this->assertNull($hostings->nameProblem('ahmed-plumbing'));
        foreach (['ab', 'Ahmed Shop', '-ahmed', 'ahmed-', 'a--b', 'اسم', str_repeat('a', 41)] as $bad) {
            $this->assertSame('invalid_subdomain', $hostings->nameProblem($bad), $bad);
        }
        foreach (['admin', 'www', 'paypal', 'dorr', 'LOGIN'] as $reserved) {
            $this->assertSame('reserved_subdomain', $hostings->nameProblem($reserved), $reserved);
        }

        config(['ai.sites.hosting.reserved' => ['acme']]);
        $this->assertSame('reserved_subdomain', $hostings->nameProblem('acme'));
    }

    public function test_a_taken_name_is_refused(): void
    {
        $owner = $this->user();
        $this->wallet($owner, 100000);
        $this->host($owner, $this->site($owner));

        $this->assertSame('subdomain_taken', app(AiSiteHostingService::class)->nameProblem('ahmed-plumbing'));
    }

    // -------------------------------------------------------------- subscribing

    public function test_subscribing_charges_the_wallet_and_publishes_the_current_version(): void
    {
        $owner = $this->user();
        $wallet = $this->wallet($owner, 20000);
        $project = $this->site($owner);

        $hosting = $this->host($owner, $project, $this->plan('yearly', 120));

        $this->assertSame(8000, $wallet->fresh()->spend_only_minor);
        $this->assertTrue($hosting->isLive());
        $this->assertSame($project->current_version_id, $hosting->published_version_id);
        $this->assertEqualsWithDelta(now()->addYear()->timestamp, $hosting->ends_at->timestamp, 5);
        $this->assertSame(1, $hosting->payments()->where('kind', 'initial')->count());
    }

    public function test_not_enough_balance_or_no_price_creates_nothing(): void
    {
        $owner = $this->user();
        $this->wallet($owner, 1000);
        $project = $this->site($owner);

        $this->assertSame('insufficient_balance', $this->reason(fn () => $this->host($owner, $project)));

        $plan = AiSiteHostingPlan::query()->create(['name' => 'No price', 'code' => 'np-'.uniqid(), 'period' => 'monthly', 'is_active' => true]);
        $this->assertSame('plan_unavailable', $this->reason(fn () => $this->host($owner, $project, $plan, 'other-name')));
        $this->assertSame(0, AiSiteHosting::query()->count());
    }

    public function test_a_site_cannot_be_hosted_twice_and_a_site_without_a_version_cannot_be_hosted(): void
    {
        $owner = $this->user();
        $this->wallet($owner, 100000);
        $project = $this->site($owner);
        $this->host($owner, $project);

        $this->assertSame('already_hosted', $this->reason(fn () => $this->host($owner, $project, null, 'another')));

        $empty = AiSiteProject::query()->create([
            'owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id, 'title' => 'Empty', 'slug' => \Illuminate\Support\Str::random(40),
            'status' => 'failed', 'access_type' => 'plan', 'brief' => ['title' => 'Empty'],
        ]);
        $this->assertSame('no_version_yet', $this->reason(fn () => $this->host($owner, $empty, null, 'empty-site')));
    }

    // ------------------------------------------------------------------ serving

    public function test_path_mode_serves_the_published_version_until_a_new_one_is_published(): void
    {
        $owner = $this->user();
        $this->wallet($owner, 100000);
        $project = $this->site($owner, 'first');
        $hosting = $this->host($owner, $project);

        $response = $this->getRaw('/sites/ahmed-plumbing/');
        $response->assertOk();
        $this->assertStringContainsString('first', $response->getContent());
        $this->assertStringContainsString('+201001234567', $response->getContent());
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
        $this->assertNull($response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));

        $project = $this->addVersion($project, 'second');
        $this->assertStringContainsString('first', $this->getRaw('/sites/ahmed-plumbing/')->getContent());
        $this->assertTrue((new \Modules\AI\Http\Resources\AiSiteHostingResource($hosting->fresh()->load('project')))->toArray(Request::create('/api/x'))['has_unpublished_changes']);

        app(AiSiteHostingService::class)->publish($hosting->fresh());
        $this->assertStringContainsString('second', $this->getRaw('/sites/ahmed-plumbing/')->getContent());
        $this->assertSame($project->current_version_id, $hosting->fresh()->published_version_id);
    }

    public function test_subdomain_middleware_serves_its_hosts_and_ignores_the_rest(): void
    {
        config(['ai.sites.hosting.domain' => 'dorrsites.test']);
        $owner = $this->user();
        $this->wallet($owner, 100000);
        $this->host($owner, $this->site($owner));
        $middleware = app(ServeHostedSiteMiddleware::class);
        $next = fn () => response('app');

        $served = $middleware->handle(Request::create('http://ahmed-plumbing.dorrsites.test/'), $next);
        $this->assertSame(200, $served->getStatusCode());
        $this->assertStringContainsString('+201001234567', $served->getContent());

        $this->assertSame('app', $middleware->handle(Request::create('http://dorr.test/'), $next)->getContent());
        $this->assertSame('app', $middleware->handle(Request::create('http://dorrsites.test/'), $next)->getContent());

        foreach (['http://nobody.dorrsites.test/', 'http://a.b.dorrsites.test/'] as $url) {
            try {
                $middleware->handle(Request::create($url), $next);
                $this->fail('expected 404 for '.$url);
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertStringStartsWith('https://ahmed-plumbing.dorrsites.test', app(AiSiteHostingService::class)->url(AiSiteHosting::query()->firstOrFail()));
    }

    public function test_disabled_project_and_admin_suspension_take_the_site_down(): void
    {
        $owner = $this->user();
        $this->wallet($owner, 100000);
        $project = $this->site($owner);
        $hosting = $this->host($owner, $project);

        $project->forceFill(['disabled_at' => now()])->save();
        $this->getRaw('/sites/ahmed-plumbing/')->assertNotFound();
        $project->forceFill(['disabled_at' => null])->save();
        $this->getRaw('/sites/ahmed-plumbing/')->assertOk();

        $admin = Admin::query()->create(['name' => 'A', 'email' => 'a-'.uniqid().'@example.test', 'password' => 'password', 'status' => true]);
        Sanctum::actingAs($admin, ['*'], 'admin_api');
        $this->postJson("/api/admin/v1/ai-site-hostings/{$hosting->id}/suspend")->assertSuccessful();
        $this->getRaw('/sites/ahmed-plumbing/')->assertNotFound();
        $this->assertSame('hosting_suspended', $this->reason(fn () => app(AiSiteHostingService::class)->renew($hosting->fresh())));

        $this->postJson("/api/admin/v1/ai-site-hostings/{$hosting->id}/resume")->assertSuccessful();
        $this->getRaw('/sites/ahmed-plumbing/')->assertOk();
    }

    // ------------------------------------------------------------------ renewal

    public function test_due_hosting_renews_from_the_wallet(): void
    {
        $owner = $this->user();
        $wallet = $this->wallet($owner, 20000);
        $hosting = $this->host($owner, $this->site($owner), $this->plan('monthly', 50));
        $oldEnd = $hosting->ends_at->copy();
        $hosting->forceFill(['ends_at' => now()->subMinute()])->save();
        $oldEnd = $hosting->ends_at->copy();

        $stats = app(AiSiteHostingService::class)->processDue();

        $this->assertSame(1, $stats['renewed']);
        $hosting->refresh();
        $this->assertSame('active', $hosting->status);
        $this->assertEqualsWithDelta($oldEnd->copy()->addMonth()->timestamp, $hosting->ends_at->timestamp, 5);
        $this->assertSame(10000, $wallet->fresh()->spend_only_minor);
        $this->assertSame(1, $hosting->payments()->where('kind', 'renewal')->count());
    }

    public function test_failed_renewal_goes_to_grace_then_suspension_then_the_name_is_released(): void
    {
        $owner = $this->user();
        $this->wallet($owner, 5000);
        $hosting = $this->host($owner, $this->site($owner), $this->plan('monthly', 50));
        $service = app(AiSiteHostingService::class);

        $hosting->forceFill(['ends_at' => now()->subHour()])->save();
        $this->assertSame(1, $service->processDue()['grace']);
        $this->assertSame('grace', $hosting->fresh()->status);
        $this->getRaw('/sites/ahmed-plumbing/')->assertOk();

        $hosting->forceFill(['grace_ends_at' => now()->subMinute()])->save();
        $this->assertSame(1, $service->processDue()['suspended']);
        $this->assertSame('suspended', $hosting->fresh()->status);
        $this->getRaw('/sites/ahmed-plumbing/')->assertNotFound();

        $hosting->forceFill(['suspended_at' => now()->subDays(31)])->save();
        $this->assertSame(1, $service->processDue()['deleted']);
        $this->assertNull($service->nameProblem('ahmed-plumbing'));
    }

    public function test_cancelling_keeps_the_site_until_the_paid_period_ends(): void
    {
        $owner = $this->user();
        $wallet = $this->wallet($owner, 20000);
        $hosting = $this->host($owner, $this->site($owner), $this->plan('monthly', 50));
        $service = app(AiSiteHostingService::class);

        $service->setAutoRenew($hosting, false);
        $this->assertSame('cancelled', $hosting->fresh()->status);
        $this->getRaw('/sites/ahmed-plumbing/')->assertOk();

        $hosting->forceFill(['ends_at' => now()->subMinute()])->save();
        $stats = $service->processDue();

        $this->assertSame(0, $stats['renewed']);
        $this->assertSame(1, $stats['suspended']);
        $this->assertSame(15000, $wallet->fresh()->spend_only_minor);
        $this->getRaw('/sites/ahmed-plumbing/')->assertNotFound();

        // A lapsed customer can pay by hand to come back, and that does not switch auto-renew on again.
        $renewed = $service->renew($hosting->fresh());
        $this->assertTrue($renewed->isLive());
        $this->assertFalse($renewed->auto_renew);
        $this->assertSame('cancelled', $renewed->status);
    }

    // ---------------------------------------------------------------------- API

    public function test_customer_api_lists_plans_checks_names_and_shows_the_hosting(): void
    {
        $owner = $this->user();
        $this->wallet($owner, 100000);
        $project = $this->site($owner);
        $plan = $this->plan('yearly', 300);
        AiSiteHostingPlan::query()->create(['name' => 'Unpriced', 'code' => 'u-'.uniqid(), 'period' => 'monthly', 'is_active' => true]);
        Sanctum::actingAs($owner, ['*'], 'user_api');

        $plans = $this->getJson('/api/user/v1/ai-sites/hosting/plans')->assertSuccessful();
        $this->assertSame('path', $plans->json('data.mode'));
        $this->assertCount(1, $plans->json('data.plans'));
        $this->assertEquals(300, $plans->json('data.plans.0.price'));

        $this->assertTrue($this->getJson('/api/user/v1/ai-sites/hosting/check?name=free-name')->json('data.available'));
        $this->assertSame('reserved_subdomain', $this->getJson('/api/user/v1/ai-sites/hosting/check?name=admin')->json('data.reason'));

        $this->assertNull($this->getJson("/api/user/v1/ai-sites/{$project->id}/hosting")->assertSuccessful()->json('data.hosting'));

        $this->host($owner, $project, $plan);
        $shown = $this->getJson("/api/user/v1/ai-sites/{$project->id}/hosting")->assertSuccessful()->json('data.hosting');
        $this->assertSame('ahmed-plumbing', $shown['subdomain']);
        $this->assertTrue($shown['is_live']);
        $this->assertStringContainsString('/sites/ahmed-plumbing/', $shown['url']);

        Sanctum::actingAs($this->user(), ['*'], 'user_api');
        $this->getJson("/api/user/v1/ai-sites/{$project->id}/hosting")->assertNotFound();
    }

    public function test_a_hosted_site_cannot_be_deleted_by_its_owner_until_it_ends(): void
    {
        $owner = $this->user();
        $this->wallet($owner, 100000);
        $project = $this->site($owner);
        $this->host($owner, $project);

        $this->assertSame('hosting_active', $this->reason(fn () => app(AiSiteProjectService::class)->delete($project)));

        app(AiSiteProjectService::class)->delete($project, force: true);
        $this->assertSame(0, AiSiteHosting::query()->count());
    }

    public function test_admin_manages_hosting_plans_and_country_prices(): void
    {
        Sanctum::actingAs(Admin::query()->create(['name' => 'A', 'email' => 'a-'.uniqid().'@example.test', 'password' => 'password', 'status' => true]), ['*'], 'admin_api');

        $id = $this->postJson('/api/admin/v1/ai-site-hosting-plans', ['name' => 'Monthly', 'code' => 'monthly', 'period' => 'monthly', 'is_active' => true])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/admin/v1/ai-site-hosting-plans', ['name' => 'Bad', 'code' => 'bad', 'period' => 'weekly', 'is_active' => true])->assertUnprocessable();

        $this->putJson("/api/admin/v1/ai-site-hosting-plans/{$id}/prices", ['prices' => [['country_id' => $this->country->id, 'price' => 75]]])->assertSuccessful();
        $row = collect($this->getJson("/api/admin/v1/ai-site-hosting-plans/{$id}/prices")->json('data.rows'))->firstWhere('country_id', $this->country->id);
        $this->assertEquals(75, $row['price']);

        $this->getJson('/api/admin/v1/ai-site-hostings')->assertSuccessful();
    }
}
