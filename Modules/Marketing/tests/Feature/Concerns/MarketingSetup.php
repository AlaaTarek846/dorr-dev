<?php

namespace Modules\Marketing\Tests\Feature\Concerns;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\Marketing\Models\MarketingFeatureRule;
use Modules\Marketing\Models\MarketingPackage;
use Modules\Marketing\Models\MarketingSubscription;
use Modules\Marketing\Services\Features\MarketingFeatures;
use Modules\User\Models\User;

/**
 * Shared ground for the marketing tests: Saudi Arabia with SAR, the service and every feature on, one package priced
 * there, a fake AI (answers taken from a queue, prompts recorded) and a fake Tavily (results set per test).
 */
trait MarketingSetup
{
    protected Country $saudi;

    protected Country $egypt;

    protected User $owner;

    protected MarketingPackage $package;

    /** @var list<string> AI answers, used in order */
    protected array $aiQueue = [];

    /** @var list<array{system: string, user: string}> */
    protected array $aiPrompts = [];

    protected bool $aiDown = false;

    /** @var list<array<string, mixed>> */
    protected array $searchResults = [];

    /** @var list<string> */
    protected array $searchQueries = [];

    protected function setUpMarketing(): void
    {
        config(['broadcasting.default' => 'null', 'marketing.research.driver' => 'tavily', 'marketing.research.tavily.key' => 'tvly-test']);

        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2]);
        $egp = Currency::create(['code' => 'EGP', 'symbol' => 'EGP', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'sa']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $sar->id, 'status' => true]);
        $this->egypt = Country::create(['code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'is_default' => false, 'flag_id' => Flag::create(['code' => 'eg'])->id, 'currency_id' => $egp->id, 'status' => true]);
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $code => $direction) {
            Language::create(['code' => $code, 'direction' => $direction, 'is_default_website' => $code === 'en', 'is_default_dashboard' => $code === 'en', 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        }

        foreach (MarketingFeatures::all() as $feature) {
            MarketingFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }

        $this->package = MarketingPackage::query()->create([
            'code' => 'pro', 'name' => ['ar' => 'احترافية', 'en' => 'Pro'], 'period_days' => 30,
            'limits' => ['projects' => 3, 'research_runs' => 5, 'plan_generations' => 5, 'members' => 2],
        ]);
        $this->package->prices()->create(['country_id' => $this->saudi->id, 'price_minor' => 9900]);

        $this->owner = $this->user('Owner', '+966500000001');

        app(AiProviderRepository::class)->ensureDefaults();
        AiProvider::query()->where('key', 'openai')->firstOrFail()->update(['is_enabled' => true, 'is_default' => true, 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);
        Http::fake([
            'api.openai.com/v1/chat/completions' => function (HttpRequest $request) {
                $messages = $request->data()['messages'] ?? [];
                $this->aiPrompts[] = ['system' => (string) ($messages[0]['content'] ?? ''), 'user' => (string) ($messages[1]['content'] ?? '')];
                if ($this->aiDown) {
                    return Http::response(['error' => ['message' => 'down']], 503);
                }

                return Http::response(['choices' => [['message' => ['content' => array_shift($this->aiQueue) ?? '{}']]]]);
            },
            'api.tavily.com/*' => function (HttpRequest $request) {
                $this->searchQueries[] = (string) ($request->data()['query'] ?? '');

                return Http::response(['results' => $this->searchResults]);
            },
            '*' => Http::response([], 200),
        ]);
    }

    protected function user(string $name, string $phone, ?Country $country = null): User
    {
        return User::create(['name' => $name, 'phone' => $phone, 'country_id' => ($country ?? $this->saudi)->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    protected function subscribe(object $owner, ?MarketingPackage $package = null): MarketingSubscription
    {
        return MarketingSubscription::query()->create([
            'owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey(),
            'package_id' => ($package ?? $this->package)->id, 'country_id' => $this->saudi->id, 'status' => 'active',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(30), 'price_minor' => 9900, 'currency_code' => 'SAR',
        ]);
    }

    protected function as(object $who, string $guard = 'user_api'): static
    {
        Sanctum::actingAs($who, [], $guard);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($who, [], $guard);

        return $this;
    }

    protected function api(string $path): string
    {
        return '/api/user/v1/marketing/'.ltrim($path, '/');
    }

    protected function createProject(array $overrides = []): TestResponse
    {
        return $this->postJson($this->api('projects'), $overrides + ['name' => 'Lamar Bakery', 'industry' => 'bakery', 'stage' => 'launch', 'cities' => ['Jeddah']]);
    }

    /** A project of the owner, subscribed, with the core discovery answered (budget optional). */
    protected function readyProject(?int $budgetMinor = 500000): string
    {
        $this->subscribe($this->owner);
        $this->as($this->owner);
        $id = $this->createProject()->assertCreated()->json('data.id');
        $this->putJson($this->api("projects/{$id}/discovery"), ['answers' => array_filter([
            'offer' => 'Fresh sourdough delivered every morning',
            'customer_segments' => ['Families', 'Cafes'],
            'monthly_budget' => $budgetMinor,
        ], fn ($v) => $v !== null)])->assertOk();

        return $id;
    }

    /** @return list<array<string, mixed>> */
    protected function tavilyResults(int $count): array
    {
        return array_map(fn ($i) => [
            'url' => "https://example{$i}.com/report",
            'title' => "Bakery report {$i}",
            'content' => "Bakery demand in Jeddah grew 12% in 2025 according to report {$i}.",
            'score' => 0.8,
            'published_date' => '2025-06-01',
        ], range(1, $count));
    }

    protected function aiReturns(array ...$answers): void
    {
        foreach ($answers as $answer) {
            $this->aiQueue[] = json_encode($answer, JSON_UNESCAPED_UNICODE);
        }
    }

    /** @return array<string, mixed> */
    protected function gtmAnswer(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Go to market in Jeddah',
            'summary' => 'Launch in two districts first, then the whole city.',
            'assumptions' => ['Delivery partners are available'],
            'risks' => ['Ingredient prices'],
            'sources' => [],
            'confidence_notes' => [],
            'horizon_days' => 90,
            'segments' => ['Families'],
            'channels' => ['Instagram', 'WhatsApp'],
            'phases' => [
                ['day_from' => 1, 'day_to' => 30, 'title' => 'Pilot', 'tasks' => ['Open preorders'], 'kpis' => ['Orders per day'], 'go_no_go_criteria' => ['Repeat orders']],
                ['day_from' => 31, 'day_to' => 90, 'title' => 'Scale', 'tasks' => ['Add cafes'], 'kpis' => ['Cafe accounts'], 'go_no_go_criteria' => ['Margin holds']],
            ],
        ];
    }
}
