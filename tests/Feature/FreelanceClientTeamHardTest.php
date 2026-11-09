<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceProjectMember;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FS-104 / FS-003: the people a client brings into his project (docs/modules/freelance/PERMISSIONS.md) - invited by phone, each role seeing
 * and doing only its part, a delegate approving within his limit and time from the owner's wallet, every step saying who took it.
 */
class FreelanceClientTeamHardTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '/api/mobile/v1/freelance/projects';

    private const TEAM = '/api/mobile/v1/freelance/team';

    private const FREELANCER = '/api/freelance/v1/projects';

    private Country $saudi;

    private User $owner;

    private User $omar;

    private User $huda;

    private FreelanceProject $project;

    private Freelancer $sara;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat', 'wallet_payment', 'disputes', 'client_team'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->owner = $this->user('Mona', '+966500000001');
        $this->omar = $this->user('Omar', '+966500000002');
        $this->huda = $this->user('Huda', '+966500000003');
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $name, string $phone): User
    {
        return User::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    private function as(User|Freelancer $who): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($who->refresh(), [], $who instanceof Freelancer ? 'freelance_api' : 'user_api');
    }

    /** The owner invites, the person accepts: an active member with this role. */
    private function join(User $who, string $role, array $limits = []): FreelanceProjectMember
    {
        $this->as($this->owner);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/team", ['phone' => $who->phone, 'role' => $role] + $limits)->assertOk();
        $member = FreelanceProjectMember::query()->where('user_id', $who->id)->firstOrFail();
        $this->as($who);
        $this->postJson(self::TEAM."/invitations/{$member->uuid}/accept")->assertOk();

        return $member->refresh();
    }

    /** Sara chosen, two parts, the agreement active; returns the parts' uuids. */
    private function active(): array
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

        return FreelanceMilestone::query()->orderBy('position')->pluck('uuid')->all();
    }

    private function topUp(User $who, int $minor): void
    {
        $w = app(\Modules\Wallet\Services\WalletService::class);
        $w->credit($w->firstOrCreateWallet($who, $this->saudi), $minor, \Modules\Wallet\Enums\WalletBucket::Withdrawable, \Modules\Wallet\Enums\WalletTransactionType::Topup);
    }

    private function balance(User $who): int
    {
        return (int) app(\Modules\Wallet\Services\WalletService::class)->firstOrCreateWallet($who, $this->saudi)->fresh()->spendableMinor();
    }

    private function delivered(string $part): void
    {
        $this->as($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$part}/start")->assertOk();
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$part}/deliver", ['note' => 'Done, see the demo.'])->assertOk();
    }

    private array $extras = [];

    private function rolePerson(string $role): User
    {
        return match ($role) {
            'manager' => $this->omar, 'reviewer' => $this->huda, default => $this->extra($role),
        };
    }

    private function extra(string $role): User
    {
        return $this->extras[$role] ??= $this->user(ucfirst($role), '+9665000001'.str_pad((string) (count($this->extras) + 10), 2, '0', STR_PAD_LEFT));
    }

    /** One member per role, all active; a delegate with a limit nobody reaches. */
    private function fullTeam(): array
    {
        $people = [];
        foreach (['manager', 'reviewer', 'collaborator', 'finance', 'delegate'] as $role) {
            $people[$role] = $this->rolePerson($role);
            $this->join($people[$role], $role, $role === 'delegate' ? ['max_amount_minor' => 900_000_000, 'days' => 30] : []);
        }

        return $people;
    }

    /** What FS-003 (PERMISSIONS.md) says each role may call: method, path, roles. Written by hand, apart from the code's own map. */
    private function oracle(string $design, string $offer, string $item, string $change): array
    {
        $all = ['manager', 'reviewer', 'collaborator', 'finance', 'delegate'];
        $offers = ['manager', 'finance', 'delegate'];
        $p = self::CLIENT.'/'.$this->project->uuid;

        return [
            ['GET', $p, $all], ['GET', "$p/preview", $all], ['GET', "$p/agreement", $all], ['GET', "$p/agreement/trail", $all],
            ['GET', "$p/agreement/changes", $all], ['GET', "$p/milestones/$design/deliveries", $all], ['GET', "$p/reports", $all], ['GET', "$p/review", $all],
            ['GET', "$p/offers", $offers], ['GET', "$p/offers/compare", $offers], ['GET', "$p/offers/$offer", $offers], ['GET', "$p/offers/$offer/freelancer", $offers],
            ['POST', "$p/offers/$offer/shortlist", ['manager']], ['POST', "$p/offers/$offer/unshortlist", ['manager']],
            ['PUT', "$p/milestones/$design/checklist", ['manager', 'reviewer']],
            ['POST', "$p/milestones/$design/checklist/$item/check", ['manager', 'reviewer', 'delegate']],
            ['POST', "$p/milestones/$design/revision", ['manager', 'reviewer']],
            ['POST', "$p/milestones/$design/fund", ['delegate']], ['POST', "$p/milestones/$design/approve", ['delegate']],
            ['POST', "$p/agreement/changes", ['manager']], ['POST', "$p/agreement/changes/$change/withdraw", ['manager']],
            ['POST', "$p/agreement/changes/$change/accept", ['delegate']], ['POST', "$p/agreement/changes/$change/reject", ['delegate']],
            // FS-150a: every role reads the Workroom's tasks; only a manager works on them.
            ['GET', "$p/workroom/tasks", $all], ['POST', "$p/workroom/tasks", ['manager']], ['PUT', "$p/workroom/tasks/$item", ['manager']],
            ['POST', "$p/workroom/tasks/$item/status", ['manager']], ['DELETE', "$p/workroom/tasks/$item", ['manager']],
            // FS-150b: the same for the File Hub and its notes - every role reads and opens, only a manager adds, changes and deletes.
            ['GET', "$p/workroom/files", $all], ['GET', "$p/workroom/files/$item", $all], ['GET', "$p/workroom/files/$item/versions/1/download", $all],
            ['POST', "$p/workroom/files", ['manager']], ['PUT', "$p/workroom/files/$item", ['manager']], ['DELETE', "$p/workroom/files/$item", ['manager']],
            ['POST', "$p/workroom/files/$item/versions", ['manager']],
            ['GET', "$p/workroom/comments", $all], ['POST', "$p/workroom/comments", ['manager']],
            ['PUT', "$p/workroom/comments/$item", ['manager']], ['DELETE', "$p/workroom/comments/$item", ['manager']],
            // FS-150c: every role reads the decisions and the timeline; only a manager proposes, answers, changes and withdraws a decision.
            ['GET', "$p/workroom/decisions", $all], ['GET', "$p/workroom/decisions/$item", $all], ['GET', "$p/workroom/timeline", $all],
            ['POST', "$p/workroom/decisions", ['manager']], ['PUT', "$p/workroom/decisions/$item", ['manager']], ['DELETE', "$p/workroom/decisions/$item", ['manager']],
            ['POST', "$p/workroom/decisions/$item/agree", ['manager']], ['POST', "$p/workroom/decisions/$item/decline", ['manager']],
        ];
    }

    public function test_every_route_of_a_project_is_closed_to_the_team_unless_the_matrix_opens_it(): void
    {
        [$design] = $this->active();
        $this->topUp($this->owner, 5_000_000);
        $offer = (string) FreelanceOffer::query()->value('uuid');
        $item = '00000000-0000-4000-8000-000000000001';
        $change = '00000000-0000-4000-8000-000000000002';
        $people = $this->fullTeam();
        $cases = $this->oracle($design, $offer, $item, $change);

        // 1. every call the matrix names: refused for a role outside it (with the team code), never refused by the team gate for a role inside it.
        foreach ($people as $role => $user) {
            $this->as($user);
            foreach ($cases as [$method, $url, $roles]) {
                $r = $this->json($method, $url, ['reason' => 'x']);
                if (in_array($role, $roles, true)) {
                    $this->assertNotSame('freelance_team_forbidden', $r->json('error_code'), "$role must pass the gate on $method $url");
                } else {
                    $this->assertSame(403, $r->getStatusCode(), "$role must be stopped on $method $url");
                    $this->assertSame('freelance_team_forbidden', $r->json('error_code'), "$role must be stopped on $method $url");
                }
            }
        }

        // 2. everything else under the project (every route the router knows, with any action word): the owner's alone.
        $named = collect($cases)->map(fn ($c) => $c[0].' '.$c[1])->all();
        $sweep = 0;
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/mobile/v1/freelance/projects/{uuid}')) {
                continue;
            }
            $action = class_basename($route->getActionName());
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                foreach (['cancel', 'cancel-accept', 'cancel-reject', 'dispute', 'archive', 'choose', 'decline', 'close', 'pay', 'x', 'check', 'uncheck'] as $word) {
                    // A word the route itself does not take is a 404 of the router, not of the team gate.
                    foreach (['action', 'state'] as $param) {
                        if (str_contains($route->uri(), '{'.$param.'}') && isset($route->wheres[$param]) && ! preg_match('/^(?:'.$route->wheres[$param].')$/', $word)) {
                            continue 2;
                        }
                    }
                    $url = '/'.preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => match ($m[1]) {
                        'uuid' => $this->project->uuid, 'milestone' => $design, 'offer' => $offer, 'item' => $item, 'change' => $change,
                        'action', 'state' => $word, 'media', 'delivery', 'number' => '1', default => '00000000-0000-4000-8000-0000000000aa',
                    }, $route->uri());
                    if (in_array("$method $url", $named, true) || $this->isMatrixRoute($action, $word)) {
                        continue;
                    }
                    foreach ($people as $role => $user) {
                        $this->as($user);
                        $r = $this->json($method, $url, ['reason' => 'x']);
                        $this->assertSame(403, $r->getStatusCode(), "$role got through on $method $url ($action)");
                        $this->assertSame('freelance_team_forbidden', $r->json('error_code'), "$method $url ($action)");
                        $sweep++;
                    }
                }
            }
        }
        $this->assertGreaterThan(300, $sweep, 'the sweep must really have covered the routes');
        // Nothing moved while all that was refused.
        $this->assertSame(5_000_000, $this->balance($this->owner));
        $this->assertSame(0, FreelanceMilestone::query()->where('status', 'funded')->count());
    }

    /** The routes (and action words) the matrix opens for somebody: tested in the first loop, not as "closed". */
    private function isMatrixRoute(string $action, string $word): bool
    {
        $open = [
            'ClientProjectController@show', 'ClientProjectController@preview', 'ClientProjectController@attachment', 'ClientProjectController@offers',
            'ClientProjectController@compareOffers', 'ClientProjectController@showOffer', 'ClientProjectController@offerFreelancer', 'ClientProjectController@offerAttachment',
            'ClientAgreementController@show', 'MilestoneDeliveryController@index', 'MilestoneDeliveryController@file', 'MilestoneDeliveryController@setChecklist',
            'MilestoneDeliveryController@tick', 'DisputeController@show', 'DisputeController@file', 'ReviewController@show', 'ApprovalTrailController@index',
            'ChangeRequestController@index', 'ChangeRequestController@file', 'ChangeRequestController@store', 'ProgressReportController@index',
            'WorkroomTaskController@index', 'WorkroomTaskController@store', 'WorkroomTaskController@update', 'WorkroomTaskController@status', 'WorkroomTaskController@destroy',
            'WorkroomFileController@index', 'WorkroomFileController@show', 'WorkroomFileController@download', 'WorkroomFileController@store', 'WorkroomFileController@update',
            'WorkroomFileController@addVersion', 'WorkroomFileController@destroy',
            'WorkroomCommentController@index', 'WorkroomCommentController@store', 'WorkroomCommentController@update', 'WorkroomCommentController@destroy',
            'WorkroomDecisionController@index', 'WorkroomDecisionController@show', 'WorkroomDecisionController@store', 'WorkroomDecisionController@update',
            'WorkroomDecisionController@agree', 'WorkroomDecisionController@decline', 'WorkroomDecisionController@destroy', 'WorkroomTimelineController@index',
        ];
        if (in_array($action, $open, true)) {
            return true;
        }

        // One method, several actions: only the words the matrix names are open.
        return match ($action) {
            'ClientAgreementController@milestoneAction' => in_array($word, ['fund', 'approve', 'revision'], true),
            'ClientProjectController@decideOffer' => in_array($word, ['shortlist', 'unshortlist'], true),
            'ChangeRequestController@answer' => in_array($word, ['accept', 'reject', 'withdraw'], true),
            default => false,
        };
    }

    public function test_no_amount_leaks_to_a_role_without_money_anywhere_in_what_it_can_read(): void
    {
        [$design, $build] = $this->active();
        $this->topUp($this->owner, 1_234_500);
        $people = $this->fullTeam();
        $this->as($this->owner);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$design}/fund")->assertOk();
        $this->as($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/agreement/changes", ['type' => 'milestone_price', 'milestone' => $build, 'amount_minor' => 300000, 'reason' => 'More pages than planned.'])->assertCreated();
        $this->delivered($design);
        $offer = (string) FreelanceOffer::query()->value('uuid');
        $p = self::CLIENT.'/'.$this->project->uuid;
        $money = ['350000', '100000', '250000', '400000', '300000', '1234500', '1,000', '3,500', '2,500', '4,000', '3,000', '1,234', '3500', '2500'];

        foreach (['manager', 'reviewer', 'collaborator'] as $role) {
            $this->as($people[$role]);
            foreach (['', '/preview', '/agreement', '/agreement/trail', '/agreement/changes', "/milestones/{$design}/deliveries", '/reports', '/review'] as $tail) {
                $json = (string) $this->getJson($p.$tail)->getContent();
                foreach ($money as $needle) {
                    $this->assertStringNotContainsString($needle, $json, "$role saw $needle in GET $tail");
                }
            }
        }
        $this->as($people['manager']);
        $json = (string) $this->getJson("$p/offers")->getContent().(string) $this->getJson("$p/offers/$offer")->getContent().(string) $this->getJson("$p/offers/compare")->getContent();
        foreach (['350000', '3,500', '3500'] as $needle) {
            $this->assertStringNotContainsString($needle, $json, "the manager saw the offer price $needle");
        }
        // The role that may see money sees it; the owner's balance stays hidden even from the delegate.
        $this->as($people['delegate']);
        $json = (string) $this->getJson("$p/agreement")->getContent();
        $this->assertStringContainsString('350000', $json);
        foreach (['1234500', '1,234', '1,134,500', '1134500', '1,134'] as $balance) {
            $this->assertStringNotContainsString($balance, $json);
        }
        // Their notices carry no amounts either.
        foreach (['manager', 'reviewer', 'collaborator'] as $role) {
            foreach ($people[$role]->notifications()->get() as $n) {
                foreach (['1,000', '3,500', '100000', '350000'] as $needle) {
                    $this->assertStringNotContainsString($needle, json_encode($n->data), "$role was told $needle");
                }
            }
        }
        // Everyone of the team heard of the delivery (no amount in it); the delivery came before this scan.
        foreach ($people as $role => $user) {
            $this->assertSame(1, $user->notifications()->where('data->event', 'freelance.milestone.delivered')->count(), "$role heard of the delivery");
        }
    }

    public function test_the_owner_is_never_confused_with_a_member_after_a_delegated_request(): void
    {
        [$design] = $this->active();
        $this->topUp($this->owner, 1_000_000);
        $this->join($this->omar, 'delegate', ['max_amount_minor' => 500000, 'days' => 5]);
        $this->as($this->omar);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$design}/fund")->assertOk();
        $this->assertSame($this->omar->id, (int) DB::table('freelance_project_approvals')->where('action', 'milestone.funded')->value('acted_by_user_id'));
        // The same process, the owner now: his role, his wallet, no member's name on the next step.
        $this->as($this->owner);
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertJsonPath('data.access.role', 'owner')
            ->assertJsonPath('data.milestones.1.amount_minor', 250000);
        $this->delivered($design);
        $this->as($this->owner);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$design}/approve")->assertOk();
        $this->assertNull(DB::table('freelance_project_approvals')->where('action', 'milestone.approved')->value('acted_by_user_id'));
        $this->assertSame(0, $this->owner->notifications()->where('data->event', 'freelance.team.acted_release')->count());
    }

    public function test_a_role_change_or_new_limit_works_from_the_next_request(): void
    {
        [$design, $build] = $this->active();
        $this->topUp($this->owner, 2_000_000);
        $member = $this->join($this->omar, 'delegate', ['max_amount_minor' => 99999, 'days' => 5]);
        $mine = self::CLIENT."/{$this->project->uuid}";
        $this->as($this->omar);
        $this->postJson("$mine/milestones/{$design}/fund")->assertForbidden()->assertJsonPath('error_code', 'freelance_team_over_limit');

        $this->as($this->owner);
        $this->putJson("$mine/team/{$member->uuid}", ['role' => 'delegate', 'max_amount_minor' => 1_000_000, 'days' => 5])->assertOk();
        $this->as($this->omar);
        $this->postJson("$mine/milestones/{$design}/fund")->assertOk();

        $this->as($this->owner);
        $this->putJson("$mine/team/{$member->uuid}", ['role' => 'collaborator'])->assertOk();
        $this->as($this->omar);
        $this->postJson("$mine/milestones/{$build}/fund")->assertForbidden()->assertJsonPath('error_code', 'freelance_team_forbidden');
        $this->getJson("$mine/agreement")->assertOk()->assertJsonPath('data.milestones.0.amount_minor', null);
        $this->assertNull(FreelanceProjectMember::query()->find($member->id)->max_amount_minor);
    }

    public function test_the_limit_is_exact_and_covers_the_clients_fee_when_funding(): void
    {
        [$design] = $this->active();
        $this->topUp($this->owner, 2_000_000);
        $part = FreelanceMilestone::query()->where('uuid', $design)->firstOrFail();
        $need = (int) $part->amount_minor + (int) $part->client_fee_minor;
        $member = $this->join($this->omar, 'delegate', ['max_amount_minor' => $need - 1, 'days' => 5]);
        $mine = self::CLIENT."/{$this->project->uuid}";
        $this->as($this->omar);
        $this->postJson("$mine/milestones/{$design}/fund")->assertForbidden()->assertJsonPath('error_code', 'freelance_team_over_limit');
        $this->as($this->owner);
        $this->putJson("$mine/team/{$member->uuid}", ['role' => 'delegate', 'max_amount_minor' => $need, 'days' => 5])->assertOk();
        $this->as($this->omar);
        $this->postJson("$mine/milestones/{$design}/fund")->assertOk();
        $this->assertSame(2_000_000 - $need, $this->balance($this->owner));
        // A second go at the same part is not a second charge.
        $this->postJson("$mine/milestones/{$design}/fund");
        $this->assertSame(2_000_000 - $need, $this->balance($this->owner));
    }

    public function test_a_delegate_cannot_fund_when_the_owners_wallet_is_short_and_learns_nothing_about_it(): void
    {
        [$design] = $this->active();
        $this->topUp($this->owner, 7777);
        $this->join($this->omar, 'delegate', ['max_amount_minor' => 900_000, 'days' => 5]);
        $this->as($this->omar);
        $r = $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$design}/fund");
        $this->assertGreaterThanOrEqual(400, $r->getStatusCode());
        $this->assertSame('freelance_team_owner_wallet', $r->json('error_code'));
        foreach (['7777', '77.77', 'SAR', '1,000.00', 'needed_minor', 'available_minor', 'shortfall'] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $r->getContent());
        }
        $this->assertSame(7777, $this->balance($this->owner));
        $this->assertSame(0, $this->owner->notifications()->where('data->event', 'freelance.team.acted_fund')->count());
        $this->assertSame(0, DB::table('freelance_project_approvals')->whereNotNull('acted_by_user_id')->count());
    }

    public function test_a_member_has_no_reach_into_another_project_or_the_owners_other_things(): void
    {
        [$design] = $this->active();
        $other = FreelanceProject::query()->create([
            'client_id' => $this->owner->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $this->project->freelance_specialty_id, 'title' => 'Another job',
            'description' => 'Something else entirely, a second project of the same client.', 'budget_type' => 'fixed', 'budget_min_minor' => 100000, 'currency_code' => 'SAR',
            'duration_days' => 10, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(5),
        ]);
        $this->topUp($this->owner, 1_000_000);
        $this->join($this->omar, 'delegate', ['max_amount_minor' => 900_000, 'days' => 5]);
        $this->as($this->omar);
        $this->getJson(self::CLIENT."/{$other->uuid}")->assertNotFound();
        $this->getJson(self::CLIENT."/{$other->uuid}/agreement")->assertNotFound();
        $this->getJson(self::CLIENT)->assertOk()->assertJsonMissing(['uuid' => $this->project->uuid])->assertJsonMissing(['uuid' => $other->uuid]);
        // A part of this project, through the URL of the other one.
        $this->postJson(self::CLIENT."/{$other->uuid}/milestones/{$design}/fund")->assertNotFound();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/00000000-0000-4000-8000-000000000009/fund")->assertNotFound();
        $this->assertSame(1_000_000, $this->balance($this->owner));
        $this->assertSame('unfunded', FreelanceMilestone::query()->where('uuid', $design)->firstOrFail()->status->value);
        $this->getJson(self::CLIENT."/{$other->uuid}/team")->assertNotFound();
    }

    public function test_invitations_belong_to_the_person_asked_only(): void
    {
        $this->as($this->owner);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/team", ['phone' => $this->omar->phone, 'role' => 'collaborator'])->assertOk();
        $uuid = FreelanceProjectMember::query()->value('uuid');
        $this->as($this->huda);
        $this->getJson(self::TEAM.'/invitations')->assertJsonCount(0, 'data.invitations');
        $this->postJson(self::TEAM."/invitations/{$uuid}/accept")->assertNotFound();
        $this->postJson(self::TEAM."/invitations/{$uuid}/decline")->assertNotFound();
        $this->assertSame('invited', FreelanceProjectMember::query()->value('status'));
        // The owner cannot answer for him either.
        $this->as($this->owner);
        $this->postJson(self::TEAM."/invitations/{$uuid}/accept")->assertNotFound();
        // Declined, then asked again: a clean invitation, and it can be taken.
        $this->as($this->omar);
        $this->postJson(self::TEAM."/invitations/{$uuid}/decline")->assertOk();
        $this->assertSame(1, $this->owner->notifications()->where('data->event', 'freelance.team.declined')->count());
        $this->getJson(self::CLIENT."/{$this->project->uuid}")->assertNotFound();
        $this->as($this->owner);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/team", ['phone' => $this->omar->phone, 'role' => 'reviewer'])->assertOk()->assertJsonPath('data.members.0.status', 'invited');
        $this->as($this->omar);
        $this->postJson(self::TEAM."/invitations/{$uuid}/accept")->assertOk();
        $this->getJson(self::CLIENT."/{$this->project->uuid}")->assertOk()->assertJsonPath('data.access.role', 'reviewer');
        // Removed, then asked again.
        $this->as($this->owner);
        $this->deleteJson(self::CLIENT."/{$this->project->uuid}/team/{$uuid}")->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/team", ['phone' => $this->omar->phone, 'role' => 'collaborator'])->assertOk();
        $this->assertSame(1, FreelanceProjectMember::query()->count());
    }

    public function test_the_delegate_limits_are_checked_hard(): void
    {
        $team = self::CLIENT."/{$this->project->uuid}/team";
        $this->as($this->owner);
        foreach ([
            ['max_amount_minor' => 0, 'days' => 5], ['max_amount_minor' => -10, 'days' => 5], ['max_amount_minor' => 1000, 'days' => 0],
            ['max_amount_minor' => 1000, 'days' => 91], ['max_amount_minor' => 1000, 'days' => -1], ['max_amount_minor' => 'abc', 'days' => 5],
            ['max_amount_minor' => 1000], ['days' => 5], ['max_amount_minor' => 1.5, 'days' => 5],
        ] as $bad) {
            $this->postJson($team, ['phone' => $this->omar->phone, 'role' => 'delegate'] + $bad)->assertUnprocessable();
        }
        $this->assertSame(0, FreelanceProjectMember::query()->count());
        $row = $this->postJson($team, ['phone' => $this->omar->phone, 'role' => 'delegate', 'max_amount_minor' => 1000, 'days' => 90])->assertOk()->json('data.members.0');
        $this->assertSame(1000, $row['max_amount_minor']);
        $this->assertSame(now()->addDays(90)->toDateString(), substr((string) $row['ends_at'], 0, 10));
        // Limits do not stick to a role that has none.
        $this->putJson("$team/{$row['uuid']}", ['role' => 'finance', 'max_amount_minor' => 5, 'days' => 5])->assertOk()->assertJsonPath('data.members.0.max_amount_minor', null);
        $this->putJson("$team/{$row['uuid']}", ['role' => 'delegate'])->assertUnprocessable();
    }

    public function test_the_team_cap_ignores_expired_invitations_and_an_ended_project_takes_no_team(): void
    {
        config(['freelance.client_team.max_members' => 2]);
        $team = self::CLIENT."/{$this->project->uuid}/team";
        $this->as($this->owner);
        $this->postJson($team, ['phone' => $this->omar->phone, 'role' => 'collaborator'])->assertOk();
        $this->postJson($team, ['phone' => $this->huda->phone, 'role' => 'collaborator'])->assertOk();
        $third = $this->user('Third', '+966500000077');
        $this->postJson($team, ['phone' => $third->phone, 'role' => 'collaborator'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_team_full');
        Carbon::setTestNow(now()->addDays(8));
        $this->postJson($team, ['phone' => $third->phone, 'role' => 'collaborator'])->assertOk();
        Carbon::setTestNow();
        $this->project->update(['status' => FreelanceProjectStatus::Cancelled]);
        $this->postJson($team, ['phone' => $this->user('Fourth', '+966500000078')->phone, 'role' => 'collaborator'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_team_project_ended');
    }

    public function test_a_blocked_or_unverified_account_cannot_be_invited(): void
    {
        $this->as($this->owner);
        $team = self::CLIENT."/{$this->project->uuid}/team";
        $this->omar->forceFill(['status' => 'blocked'])->save();
        $this->postJson($team, ['phone' => $this->omar->phone, 'role' => 'collaborator'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_team_user_not_found');
        $this->huda->forceFill(['phone_verified_at' => null])->save();
        $this->postJson($team, ['phone' => $this->huda->phone, 'role' => 'collaborator'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_team_user_not_found');
        $this->postJson($team, ['phone' => '12', 'role' => 'collaborator'])->assertStatus(422);
        $this->postJson($team, ['role' => 'collaborator'])->assertUnprocessable();
    }

    public function test_someone_who_bids_after_being_invited_is_a_conflict_too(): void
    {
        $this->join($this->omar, 'collaborator');
        $bidder = Freelancer::query()->create(['name' => 'Omar', 'phone' => $this->omar->phone, 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->as($bidder);
        $r = $this->postJson(self::FREELANCER."/{$this->project->uuid}/offers", [
            'price_minor' => 300000, 'duration_days' => 20, 'details' => 'I can do this site in three steps and show a demo every week to the client.',
        ]);
        $this->assertSame(403, $r->getStatusCode(), 'a team member must not be able to bid on the project he sits on');
        $this->assertSame(1, FreelanceOffer::query()->count());
    }

    public function test_a_delegates_step_is_told_to_the_owner_once_and_the_trail_shows_only_a_name(): void
    {
        [$design] = $this->active();
        $this->topUp($this->owner, 1_000_000);
        $this->join($this->omar, 'delegate', ['max_amount_minor' => 900_000, 'days' => 5]);
        $this->as($this->omar);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$design}/fund")->assertOk();
        $this->assertSame(1, $this->owner->notifications()->where('data->event', 'freelance.team.acted_fund')->count());
        $this->assertSame(0, $this->omar->notifications()->where('data->event', 'freelance.team.acted_fund')->count());
        $this->as($this->owner);
        $rows = collect($this->getJson(self::CLIENT."/{$this->project->uuid}/agreement/trail")->json('data'));
        $funded = $rows->firstWhere('action', 'milestone.funded');
        $this->assertSame(['name' => 'Omar', 'team' => true], $funded['by']);
        $this->assertArrayNotHasKey('acted_by_user_id', $funded);
    }
}
