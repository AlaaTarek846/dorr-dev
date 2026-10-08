<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\AI\Exceptions\AiSiteException;
use Modules\AI\Jobs\GenerateAiSiteVersionJob;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiSiteOffer;
use Modules\AI\Models\AiSiteProject;
use Modules\AI\Models\AiSubscription;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Services\AiGateway;
use Modules\AI\Services\AiModelResolver;
use Modules\AI\Services\Sites\AiSiteEntitlementService;
use Modules\AI\Services\Sites\AiSiteFileGuard;
use Modules\AI\Services\Sites\AiSiteGenerationService;
use Modules\AI\Services\Sites\AiSiteProjectService;
use Modules\AI\Services\Sites\AiSitePromptBuilder;
use Modules\AI\Services\Sites\AiSiteResponder;
use Modules\AI\Services\Sites\AiSiteResponseParser;
use Modules\AI\Services\Sites\AiSiteStorage;
use Modules\AI\Services\Sites\AiSiteTokenizer;
use Modules\User\Models\User;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;
use ZipArchive;

/** Website builder end to end, with the model replaced by a canned answer. */
class AiSiteProjectFlowTest extends TestCase
{
    use RefreshDatabase;

    private const GOOD = "=== FILE: index.html ===\n<!doctype html><html><body><a href=\"tel:{{PHONE}}\">{{PHONE}}</a><img src=\"assets/logo.png\"><script src=\"app.js\"></script></body></html>\n=== END FILE ===\n=== FILE: app.js ===\nconsole.log('hi');\n=== END FILE ===\n";

    private Country $country;

    private Currency $currency;

    private object $generator;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();

        $this->currency = Currency::create(['code' => 'EGP', 'symbol' => 'ج.م', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'eg']);
        $this->country = Country::create([
            'code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10,
            'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $this->currency->id, 'status' => true,
        ]);
        app()->instance('resolved_country', $this->country);

        $this->generator = $this->fakeGenerator();
    }

    private function fakeGenerator(): object
    {
        $a = $this->app;

        $generator = new class($a->make(AiGateway::class), $a->make(AiProviderRepository::class), $a->make(AiModelResolver::class), $a->make(AiSitePromptBuilder::class), $a->make(AiSiteResponseParser::class), $a->make(AiSiteFileGuard::class), $a->make(AiSiteStorage::class), $a->make(AiSiteEntitlementService::class)) extends AiSiteGenerationService
        {
            public string $answer = '';

            protected function resolveTarget(): array
            {
                return ['provider' => new AiProvider, 'model_key' => 'fake-model'];
            }

            protected function callModel(array $target, array $messages): array
            {
                return ['success' => true, 'message' => '', 'content' => $this->answer];
            }
        };

        $generator->answer = self::GOOD;
        $a->instance(AiSiteGenerationService::class, $generator);

        return $generator;
    }

    private function user(): User
    {
        return User::query()->create([
            'name' => 'Site User', 'email' => 'site-'.uniqid().'@example.test',
            'password' => bcrypt('not-a-real-password'), 'status' => UserStatus::Active,
        ]);
    }

    private function admin(): void
    {
        Sanctum::actingAs(Admin::query()->create(['name' => 'A', 'email' => 'a-'.uniqid().'@example.test', 'password' => 'password', 'status' => true]), ['*'], 'admin_api');
    }

    private function planUser(int $projects = 2, int $daily = 5): User
    {
        $user = $this->user();
        $plan = AiPlan::query()->create([
            'name' => 'Sites', 'code' => 'sites-'.uniqid(), 'usage_minutes' => 60, 'cooldown_minutes' => 0, 'duration_days' => 30,
            'price' => 10, 'currency_id' => $this->currency->id, 'is_active' => true, 'sort_order' => 1,
            'site_projects_limit' => $projects, 'site_daily_generations' => $daily,
        ]);
        AiSubscription::query()->create([
            'owner_type' => $user->getMorphClass(), 'owner_id' => $user->id, 'plan_id' => $plan->id,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'status' => AiSubscription::STATUS_ACTIVE,
        ]);

        return $user;
    }

    private function brief(): array
    {
        return [
            'title' => 'My site', 'business_name' => 'Acme', 'activity' => 'Plumbing', 'site_type' => 'business',
            'description' => 'We fix pipes fast and cheap.', 'languages' => ['ar'],
            'contact' => ['phone' => '+201001234567'],
        ];
    }

    private function build(User $user): AiSiteProject
    {
        $project = app(AiSiteProjectService::class)->create($user, $this->brief(), UploadedFile::fake()->image('logo.png'), []);
        $this->generator->run($project->versions()->firstOrFail());

        return $project->fresh(['versions', 'currentVersion']);
    }

    private function runLatest(AiSiteProject $project): void
    {
        $this->generator->run($project->fresh()->versions()->orderByDesc('number')->firstOrFail());
    }

    private function wallet(User $user, int $minor): Wallet
    {
        $wallet = app(WalletService::class)->firstOrCreateWallet($user, $this->country);
        $wallet->update(['spend_only_minor' => $minor, 'withdrawable_minor' => 0]);

        return $wallet->fresh();
    }

    private function offer(float $price = 100, int $generations = 3): AiSiteOffer
    {
        $offer = AiSiteOffer::query()->create(['name' => 'Portfolio', 'code' => 'portfolio-'.uniqid(), 'generations_included' => $generations, 'is_active' => true]);
        $offer->prices()->create(['country_id' => $this->country->id, 'currency_id' => $this->currency->id, 'price' => $price]);

        return $offer;
    }

    /** TestCase::get() trims a trailing slash, which the real site root URL has - so go through the kernel. */
    private function getRaw(string $uri): TestResponse
    {
        $kernel = $this->app->make(Kernel::class);

        return TestResponse::fromBaseResponse($kernel->handle(Request::create($uri)));
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

    // ---------------------------------------------------------------- access

    public function test_a_user_without_the_feature_cannot_create_a_site(): void
    {
        Sanctum::actingAs($this->user(), ['*'], 'user_api');

        $this->post('/api/user/v1/ai-sites', $this->brief(), ['Accept' => 'application/json'])->assertStatus(403);
        $this->assertSame(0, AiSiteProject::query()->count());
    }

    public function test_a_plan_user_creates_a_site_and_a_build_job_is_queued(): void
    {
        Sanctum::actingAs($this->planUser(), ['*'], 'user_api');

        $response = $this->post('/api/user/v1/ai-sites', $this->brief() + ['logo' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json']);

        $response->assertStatus(202);
        $project = AiSiteProject::query()->firstOrFail();
        $this->assertSame('plan', $project->access_type);
        $this->assertSame('generating', $project->status);
        $this->assertSame(40, strlen($project->slug));
        $this->assertSame('assets/logo.png', $project->brief['assets'][0]['path']);
        Storage::disk('local')->assertExists($project->assetsPath().'/logo.png');
        Queue::assertPushed(GenerateAiSiteVersionJob::class);
    }

    public function test_validation_errors_use_translated_field_names(): void
    {
        Sanctum::actingAs($this->planUser(), ['*'], 'user_api');

        foreach (['ar' => 'اللون الرئيسي', 'en' => 'main color'] as $locale => $label) {
            app()->setLocale($locale);

            $response = $this->postJson('/api/user/v1/ai-sites', $this->brief() + ['colors' => ['primary' => 'ازرق']], ['Accept-Language' => $locale]);

            $response->assertUnprocessable();
            $message = $response->json('errors')['colors.primary'][0];
            $this->assertStringContainsString($label, $message);
            $this->assertStringNotContainsString('colors.primary', $message);
        }
    }

    public function test_the_daily_and_project_limits_are_enforced(): void
    {
        $service = app(AiSiteProjectService::class);

        $user = $this->planUser(projects: 5, daily: 1);
        $service->create($user, $this->brief(), null, []);
        $this->assertSame('daily_limit_reached', $this->reason(fn () => $service->create($user, $this->brief(), null, [])));

        $other = $this->planUser(projects: 1, daily: 9);
        $service->create($other, $this->brief(), null, []);
        $this->assertSame('projects_limit_reached', $this->reason(fn () => $service->create($other, $this->brief(), null, [])));
    }

    public function test_another_account_gets_a_404_for_my_project(): void
    {
        $project = $this->build($this->planUser());
        Sanctum::actingAs($this->user(), ['*'], 'user_api');

        $this->getJson('/api/user/v1/ai-sites/'.$project->id)->assertNotFound();
    }

    // ------------------------------------------------------------ generation

    public function test_a_good_answer_becomes_the_current_version(): void
    {
        $project = $this->build($this->planUser());

        $this->assertSame('ready', $project->status);
        $this->assertNotNull($project->current_version_id);
        $this->assertSame('completed', $project->currentVersion->status);
        Storage::disk('local')->assertExists($project->currentVersion->path().'/index.html');
    }

    public function test_a_bad_edit_keeps_the_last_good_version_live(): void
    {
        $user = $this->planUser();
        $project = $this->build($user);
        $good = $project->current_version_id;

        $this->generator->answer = "=== FILE: index.html ===\n<html><input type=\"password\"></html>\n=== END FILE ===\n";
        app(AiSiteProjectService::class)->requestEdit($project, $user, 'add a login');
        $this->runLatest($project);

        $project->refresh();
        $this->assertSame($good, $project->current_version_id);
        $this->assertSame('ready', $project->status);
        $this->assertSame('unsafe_content', $project->last_error);

        Sanctum::actingAs($user, ['*'], 'user_api');
        $shown = $this->getJson('/api/user/v1/ai-sites/'.$project->id)->assertSuccessful()->json('data');
        $this->assertSame('unsafe_content', $shown['last_error']);
        $this->assertNotEmpty($shown['last_error_message']);
        $this->assertStringEndsWith('/', $shown['preview_url']);
        $this->assertSame('failed', $project->versions()->orderByDesc('number')->first()->status);
    }

    public function test_a_second_edit_while_one_is_running_is_refused(): void
    {
        $user = $this->planUser();
        $project = $this->build($user);
        $service = app(AiSiteProjectService::class);

        $service->requestEdit($project, $user, 'make it blue');

        $this->assertSame('project_busy', $this->reason(fn () => $service->requestEdit($project->fresh(), $user, 'and red')));
    }

    public function test_restore_creates_a_new_uncounted_version_with_the_old_files(): void
    {
        $user = $this->planUser();
        $project = $this->build($user);
        $first = $project->current_version_id;

        $this->generator->answer = "=== FILE: index.html ===\n<html><body>v2</body></html>\n=== END FILE ===\n";
        app(AiSiteProjectService::class)->requestEdit($project, $user, 'change it');
        $this->runLatest($project);

        $restored = app(AiSiteProjectService::class)->restore($project->fresh(), 1);

        $newest = $restored->versions()->orderByDesc('number')->first();
        $this->assertSame(3, $newest->number);
        $this->assertSame('restore', $newest->kind);
        $this->assertFalse($newest->counted);
        $this->assertSame($newest->id, $restored->current_version_id);
        $this->assertStringContainsString('{{PHONE}}', Storage::disk('local')->get($newest->path().'/index.html'));
        $this->assertNotSame($first, $restored->current_version_id);
    }

    // --------------------------------------------------------------- serving

    public function test_the_site_is_served_with_contact_details_filled_and_hardened_headers(): void
    {
        $project = $this->build($this->planUser());

        $response = $this->getRaw('/ai-sites/'.$project->slug.'/');

        $response->assertOk();
        $this->assertStringContainsString('+201001234567', $response->getContent());
        $this->assertStringNotContainsString('{{PHONE}}', $response->getContent());
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('text/html', $response->headers->get('Content-Type'));
        $this->assertEmpty($response->headers->getCookies());

        $this->get('/ai-sites/'.$project->slug.'/app.js')->assertOk();
        $this->get('/ai-sites/'.$project->slug.'/assets/logo.png')->assertOk();
        $this->get('/ai-sites/'.$project->slug.'/missing.html')->assertNotFound();
        $this->get('/ai-sites/'.$project->slug.'/assets/..%2Findex.html')->assertNotFound();
    }

    public function test_the_root_without_a_trailing_slash_is_served_without_a_redirect_and_with_absolute_links(): void
    {
        $project = $this->build($this->planUser());

        $with = $this->getRaw('/ai-sites/'.$project->slug.'/');
        $without = $this->getRaw('/ai-sites/'.$project->slug);

        $with->assertOk();
        $without->assertOk();
        $this->assertSame($with->getContent(), $without->getContent());
    }

    public function test_relative_links_are_made_absolute_but_anchors_and_external_ones_are_not(): void
    {
        $out = (new class(app(AiSiteStorage::class), app(AiSiteTokenizer::class)) extends AiSiteResponder
        {
            public function run(string $html, string $base): string
            {
                return $this->absolutizeLinks($html, $base);
            }
        })->run('<link href="style.css"><a href="#top"><a href="https://x.test/a"><a href="tel:{{PHONE}}"><img src="assets/logo.png"><a href="/abs">', '/ai-sites/abc/');

        $this->assertStringContainsString('href="/ai-sites/abc/style.css"', $out);
        $this->assertStringContainsString('src="/ai-sites/abc/assets/logo.png"', $out);
        $this->assertStringContainsString('href="#top"', $out);
        $this->assertStringContainsString('href="https://x.test/a"', $out);
        $this->assertStringContainsString('href="tel:{{PHONE}}"', $out);
        $this->assertStringContainsString('href="/abs"', $out);
    }

    public function test_unknown_and_disabled_sites_are_404(): void
    {
        $project = $this->build($this->planUser());

        $this->getRaw('/ai-sites/'.str_repeat('a', 40).'/')->assertNotFound();

        $project->forceFill(['disabled_at' => now()])->save();
        $this->getRaw('/ai-sites/'.$project->slug.'/')->assertNotFound();
    }

    public function test_the_zip_has_real_contact_details_and_the_assets(): void
    {
        $user = $this->planUser();
        $project = $this->build($user);
        Sanctum::actingAs($user, ['*'], 'user_api');

        $response = $this->get('/api/user/v1/ai-sites/'.$project->id.'/download');

        $response->assertOk();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $this->assertStringContainsString('+201001234567', $zip->getFromName('index.html'));
        $this->assertNotFalse($zip->getFromName('assets/logo.png'));
        $zip->close();
    }

    // -------------------------------------------------------------- purchase

    public function test_buying_a_site_charges_the_wallet_and_uses_one_generation(): void
    {
        $user = $this->user();
        $wallet = $this->wallet($user, 20000);
        $offer = $this->offer(price: 150, generations: 3);

        $project = app(AiSiteProjectService::class)->create($user, $this->brief(), null, [], $offer->id);

        $this->assertSame(5000, $wallet->fresh()->spend_only_minor);
        $this->assertSame('purchase', $project->access_type);
        $this->assertSame(1, $project->purchase->generations_used);
        $this->assertSame(2, $project->purchase->generationsLeft());
    }

    public function test_not_enough_balance_creates_nothing(): void
    {
        $user = $this->user();
        $this->wallet($user, 1000);
        $offer = $this->offer(price: 150);

        $this->assertSame('insufficient_balance', $this->reason(fn () => app(AiSiteProjectService::class)->create($user, $this->brief(), null, [], $offer->id)));
        $this->assertSame(0, AiSiteProject::query()->count());
    }

    public function test_an_offer_not_sold_in_the_country_is_unavailable(): void
    {
        $user = $this->user();
        $this->wallet($user, 99999);
        $offer = AiSiteOffer::query()->create(['name' => 'No price', 'code' => 'np-'.uniqid(), 'generations_included' => 3, 'is_active' => true]);

        $this->assertSame('offer_unavailable', $this->reason(fn () => app(AiSiteProjectService::class)->create($user, $this->brief(), null, [], $offer->id)));
    }

    public function test_a_failed_build_gives_the_generation_back_and_edits_run_out(): void
    {
        $user = $this->user();
        $this->wallet($user, 20000);
        $service = app(AiSiteProjectService::class);
        $project = $service->create($user, $this->brief(), null, [], $this->offer(generations: 2)->id);

        $this->generator->answer = 'no files here';
        $this->runLatest($project);

        $project->refresh();
        $this->assertSame('failed', $project->status);
        $this->assertSame(0, $project->purchase->fresh()->generations_used);

        $this->generator->answer = self::GOOD;
        $service->retry($project, $user);
        $this->runLatest($project);
        $this->assertSame(1, $project->purchase->fresh()->generations_used);

        $service->requestEdit($project->fresh(), $user, 'tweak');
        $this->runLatest($project);

        $this->assertSame('purchase_exhausted', $this->reason(fn () => $service->requestEdit($project->fresh(), $user, 'once more')));
    }

    // ----------------------------------------------------------------- admin

    public function test_admin_manages_offers_and_country_prices_and_customers_see_them(): void
    {
        $this->admin();

        $id = $this->postJson('/api/admin/v1/ai-site-offers', [
            'name' => 'Portfolio site', 'code' => 'portfolio', 'generations_included' => 20, 'is_active' => true,
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/admin/v1/ai-site-offers/{$id}/prices", ['prices' => [['country_id' => $this->country->id, 'price' => 250]]])->assertSuccessful();

        $row = collect($this->getJson("/api/admin/v1/ai-site-offers/{$id}/prices")->assertSuccessful()->json('data.rows'))->firstWhere('country_id', $this->country->id);
        $this->assertEquals(250, $row['price']);
        $this->assertSame('EGP', $row['currency_code']);

        $this->postJson('/api/admin/v1/ai-site-offers', ['name' => 'Dup', 'code' => 'portfolio', 'generations_included' => 1, 'is_active' => true])->assertUnprocessable();

        Sanctum::actingAs($this->user(), ['*'], 'user_api');
        $offers = $this->getJson('/api/user/v1/ai-sites/offers')->assertSuccessful()->json('data.offers');
        $this->assertCount(1, $offers);
        $this->assertEquals(250, $offers[0]['price']);
        $this->assertSame('EGP', $offers[0]['currency']);
    }

    public function test_admin_can_take_a_site_offline_and_back(): void
    {
        $project = $this->build($this->planUser());
        $this->admin();

        $this->postJson("/api/admin/v1/ai-sites/{$project->id}/disable")->assertSuccessful();
        $this->getRaw('/ai-sites/'.$project->slug.'/')->assertNotFound();

        $this->postJson("/api/admin/v1/ai-sites/{$project->id}/enable")->assertSuccessful();
        $this->getRaw('/ai-sites/'.$project->slug.'/')->assertOk();
    }

    public function test_plan_site_fields_are_saved_through_the_admin_api(): void
    {
        $this->admin();

        $response = $this->postJson('/api/admin/v1/ai-plans', [
            'name' => 'Pro', 'code' => 'pro-'.uniqid(), 'usage_minutes' => 120, 'cooldown_minutes' => 0, 'duration_days' => 30, 'price' => 10,
            'site_projects_limit' => 3, 'site_daily_generations' => 4,
        ])->assertSuccessful();

        $this->assertSame(3, $response->json('data.site_projects_limit'));
        $this->assertSame(4, $response->json('data.site_daily_generations'));
    }
}
