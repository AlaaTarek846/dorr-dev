<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceConversation;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceMessage;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\FreelanceWorkroomTask;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Projects\ProjectHealth;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Phase 8 — AI Project Manager (light): the summary of a running project for the client on his DORR AI plan (FS-801), overdue Workroom
 * tasks in the project's health for both sides (FS-802), and the safety line: the AI only reads and suggests (FS-806).
 */
class FreelanceAiProjectManagerTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '/api/mobile/v1/freelance/projects';

    private const FREELANCER = '/api/freelance/v1/projects';

    private Country $saudi;

    private User $owner;

    private Freelancer $sara;

    private FreelanceProject $project;

    private string $aiAnswer = '{}';

    /** @var list<string> */
    private array $aiPrompts = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        app(AiProviderRepository::class)->ensureDefaults();
        AiProvider::query()->where('key', 'openai')->firstOrFail()->update(['is_enabled' => true, 'is_default' => true, 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);
        Http::fake([
            'api.openai.com/v1/chat/completions' => function (HttpRequest $request) {
                $this->aiPrompts[] = (string) json_encode($request->data()['messages'] ?? [], JSON_UNESCAPED_UNICODE);

                return Http::response(['choices' => [['message' => ['content' => $this->aiAnswer]]]]);
            },
            '*' => Http::response([], 200),
        ]);

        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        $this->switches(['freelance', 'projects', 'bidding', 'chat', 'client_team', 'workroom']);
        FreelancePolicies::forget();
        $web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->owner = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->project = FreelanceProject::query()->create([
            'client_id' => $this->owner->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $web->id, 'title' => 'A booking website',
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->as($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/offers", [
            'price_minor' => 350000, 'duration_days' => 21, 'details' => 'I built three booking sites like this; I will deliver in three steps with a demo each week.',
        ])->assertCreated();
        $this->active();
    }

    public function test_the_client_gets_a_short_summary_of_the_running_project_on_his_plan(): void
    {
        $url = self::CLIENT."/{$this->project->uuid}/workroom/ai/summary";
        $this->as($this->owner);
        $this->getJson($url)->assertStatus(403)->assertJsonPath('error_code', 'freelance_feature_off');

        $this->switches(['ai_project_manager']);
        $this->getJson(self::CLIENT.'/ai/status')->assertJsonPath('data.manager', true);
        $this->getJson($url)->assertStatus(402)->assertJsonPath('error_code', 'freelance_ai_plan_no_plan');
        $this->trialPlan();

        // Nothing happened yet: the numbers, and no AI call (a quiet week costs nothing).
        $this->getJson($url)->assertOk()->assertJsonPath('data.quiet', true)->assertJsonPath('data.summary', null)->assertJsonPath('data.facts.parts', 2);
        $this->assertSame([], $this->aiPrompts);

        // Some work: a shared task done, a team-only task (never read), a report, and the chat.
        $this->as($this->sara);
        $done = $this->postJson(self::FREELANCER."/{$this->project->uuid}/workroom/tasks", ['title' => 'Draft the home page'])->assertCreated()->json('data.task.uuid');
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/workroom/tasks/{$done}/status", ['status' => 'done'])->assertOk();
        FreelanceWorkroomTask::query()->create(['uuid' => (string) Str::uuid(), 'project_id' => $this->project->id, 'agreement_id' => $this->agreement()->id,
            'title' => 'Our secret team note', 'status' => 'todo', 'scope' => FreelanceWorkroomTask::TEAM, 'assignee_side' => 'freelancer', 'created_by_side' => 'freelancer', 'created_by_id' => $this->sara->id]);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/reports", ['note' => 'The home page draft is ready for a look.', 'percent' => 40])->assertSuccessful();
        $room = FreelanceConversation::query()->create(['uuid' => (string) Str::uuid(), 'client_id' => $this->owner->id, 'freelancer_id' => $this->sara->id,
            'context_type' => 'project', 'context_id' => $this->project->id, 'status' => 'open']);
        FreelanceMessage::query()->create(['conversation_id' => $room->id, 'sender_type' => 'client', 'sender_id' => $this->owner->id, 'kind' => 'text',
            'body' => 'Mona here — can you also add an online shop? call me on 0501234567', 'created_at' => now()]);

        $this->aiAnswer = json_encode([
            'headline' => 'The home page draft is ready (40%).',
            'progress' => ['Home page drafted'], 'decisions' => [], 'changes' => ['An online shop was asked in the chat — outside the agreed parts'],
            'risks' => [], 'next_client' => ['Review the home page draft'], 'next_freelancer' => ['Start the Build part'],
        ]);
        $money = $this->moneyState();
        $this->as($this->owner);
        $data = $this->getJson($url)->assertOk()
            ->assertJsonPath('data.quiet', false)
            ->assertJsonPath('data.summary.headline', 'The home page draft is ready (40%).')
            ->assertJsonPath('data.summary.changes.0', 'An online shop was asked in the chat — outside the agreed parts')
            ->assertJsonPath('data.facts.tasks_done', 1)
            ->assertJsonPath('data.facts.percent', 40)
            ->assertJsonPath('data.facts.messages', 1)
            ->assertJsonPath('data.health.state', 'healthy')
            ->json('data');
        $this->assertNotNull($data['generated_at']);

        // The model read the work, never a name, a phone or the team's own items.
        $prompt = $this->aiPrompts[0];
        $this->assertStringContainsString('Draft the home page', $prompt);
        $this->assertStringContainsString('online shop', $prompt);
        $this->assertStringNotContainsString('0501234567', $prompt);
        $this->assertStringNotContainsString('Sara', $prompt);
        $this->assertStringNotContainsString('Our secret team note', $prompt);

        // Opened again with nothing new: the same summary, no new AI call. `refresh` asks again.
        $this->getJson($url)->assertOk()->assertJsonPath('data.summary.headline', 'The home page draft is ready (40%).');
        $this->assertCount(1, $this->aiPrompts);
        $this->getJson($url.'?refresh=1')->assertOk();
        $this->assertCount(2, $this->aiPrompts);
        $this->getJson($url.'?days=3')->assertStatus(422);

        // FS-806: the AI only read — not a part, an amount, the agreement or a wallet moved.
        $this->assertSame($money, $this->moneyState());
    }

    public function test_only_the_owner_reaches_the_summary(): void
    {
        $this->switches(['ai_project_manager']);
        $this->trialPlan();
        $url = self::CLIENT."/{$this->project->uuid}/workroom/ai/summary";

        // The freelancer's app has no such route; another client does not find the project.
        $this->as($this->sara);
        $this->getJson($url)->assertUnauthorized();
        $this->getJson(self::FREELANCER."/{$this->project->uuid}/workroom/ai/summary")->assertNotFound();
        $other = User::query()->create(['name' => 'Ali', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->as($other);
        $this->getJson($url)->assertNotFound();
        $this->assertSame([], $this->aiPrompts);
    }

    public function test_an_overdue_shared_task_puts_the_project_at_risk_for_whoever_it_is_on(): void
    {
        $this->as($this->owner);
        $task = $this->postJson(self::CLIENT."/{$this->project->uuid}/workroom/tasks", [
            'title' => 'Draft the home page', 'assignee_side' => 'freelancer', 'due_on' => now()->addDay()->toDateString(),
        ])->assertCreated()->json('data.task.uuid');
        FreelanceWorkroomTask::query()->create(['uuid' => (string) Str::uuid(), 'project_id' => $this->project->id, 'agreement_id' => $this->agreement()->id,
            'title' => 'A team-only item', 'status' => 'todo', 'due_on' => now()->subDays(5)->toDateString(), 'scope' => FreelanceWorkroomTask::TEAM,
            'assignee_side' => 'freelancer', 'created_by_side' => 'freelancer', 'created_by_id' => $this->sara->id]);
        $health = app(ProjectHealth::class);
        $this->assertSame('healthy', $health->evaluate($this->agreement())['state']);

        $this->travel(2)->days(); // under the 72h of an unfunded part, so the task is the only reason
        $mine = $health->present($this->agreement()->refresh(), 'freelancer');
        $this->assertSame('at_risk', $mine['state']);
        $late = collect($mine['reasons'])->firstWhere('code', 'task_overdue');
        $this->assertTrue($late['mine']);
        $this->assertSame('Draft the home page', $late['data']['part']);
        $this->assertSame(1, $late['data']['count']);
        $this->assertSame(1, $late['data']['days']);
        $this->assertFalse(collect($health->present($this->agreement(), 'client')['reasons'])->firstWhere('code', 'task_overdue')['mine']);

        // Done: the warning goes.
        $this->as($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/workroom/tasks/{$task}/status", ['status' => 'done'])->assertOk();
        $this->assertSame('healthy', $health->evaluate($this->agreement())['state']);
    }

    /** FS-806: every DORR AI route of the freelance service only reads, except the builder, which writes nothing (its test proves it). */
    public function test_the_ai_routes_cannot_move_money_or_change_an_agreement(): void
    {
        $ai = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->getActionName(), 'ClientAiController'));
        $this->assertNotEmpty($ai);
        foreach ($ai as $route) {
            $writes = array_diff($route->methods(), ['GET', 'HEAD']);
            $this->assertTrue($writes === [] || str_ends_with($route->uri(), 'projects/ai/build'), $route->uri().' must only read');
        }

        // The AI services never reach the money or the agreement.
        foreach (glob(base_path('Modules/Freelance/app/Services/Ai/*.php')) as $file) {
            $code = (string) file_get_contents($file);
            foreach (['MilestonePayments', 'MilestoneRelease', 'FreelanceEarnings', 'Wallet', 'Agreements;', 'ChangeRequests;', 'Disputes;', 'ProjectStateMachine'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, basename($file).' must not use '.$forbidden);
            }
            $this->assertDoesNotMatchRegularExpression('/->(save|update|delete|forceFill|increment|decrement)\(/', $code, basename($file).' must not write');
        }
    }

    private function agreement(): FreelanceAgreement
    {
        return FreelanceAgreement::query()->where('project_id', $this->project->id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function moneyState(): array
    {
        return [
            'agreement' => $this->agreement()->only(['status', 'version']),
            'parts' => FreelanceMilestone::query()->orderBy('position')->get(['status', 'amount_minor', 'paid_minor', 'version'])->toArray(),
        ];
    }

    private function active(): void
    {
        $this->as($this->owner);
        $offer = FreelanceOffer::query()->where('freelancer_id', $this->sara->id)->firstOrFail();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers")->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$offer->uuid}/choose")->assertOk();
        $this->putJson(self::CLIENT."/{$this->project->uuid}/agreement/milestones", ['milestones' => [
            ['title' => 'Design', 'amount_minor' => 100000], ['title' => 'Build', 'amount_minor' => 250000],
        ]])->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/send")->assertOk();
        $this->as($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/agreement/approve")->assertOk();
    }

    private function as(User|Freelancer $who): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($who->refresh(), [], $who instanceof Freelancer ? 'freelance_api' : 'user_api');
    }

    private function trialPlan(): AiPlan
    {
        return AiPlan::query()->create(['name' => 'Trial', 'code' => 'trial', 'is_trial' => true, 'is_active' => true, 'sort_order' => 1, 'usage_minutes' => 600, 'cooldown_minutes' => 5]);
    }

    /** @param  list<string>  $features */
    private function switches(array $features): void
    {
        foreach ($features as $feature) {
            FreelanceFeatureRule::query()->updateOrCreate(['feature' => $feature, 'country_id' => $this->saudi->id], ['enabled' => true]);
        }
        FreelanceFeatures::forget();
    }
}
