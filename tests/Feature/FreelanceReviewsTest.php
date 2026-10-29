<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelancePolicy;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FL-701 / FL-703: after a project is completed both sides rate each other by criteria; nobody sees the other's rating before rating
 * too (or the days being over); the freelancer's numbers — average from three ratings, finished projects, on time — reach the offers.
 */
class FreelanceReviewsTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '/api/mobile/v1/freelance/projects';

    private const FREELANCER = '/api/freelance/v1/projects';

    private Country $saudi;

    private User $client;

    private FreelanceProject $project;

    private Freelancer $sara;

    private Freelancer $lina;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat', 'wallet_payment', 'ratings'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->project = FreelanceProject::query()->create([
            'client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $web->id, 'title' => 'A booking website',
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
        $this->sara = $this->freelancer('+966500000091', 'Sara');
        $this->lina = $this->freelancer('+966500000092', 'Lina');
    }

    private function freelancer(string $phone, string $name): Freelancer
    {
        $f = Freelancer::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->asFreelancer($f);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/offers", [
            'price_minor' => 350000, 'duration_days' => 21, 'details' => 'I built three booking sites like this; I will deliver in three steps with a demo each week.',
        ])->assertCreated();

        return $f;
    }

    private function asFreelancer(Freelancer $f): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($f->refresh(), [], 'freelance_api');
    }

    private function asClient(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->client->refresh(), [], 'user_api');
    }

    private function offer(Freelancer $f): FreelanceOffer
    {
        return FreelanceOffer::query()->where('freelancer_id', $f->id)->firstOrFail();
    }

    /** The client looks at the offers and chooses Sara. */
    private function chooseSara(): void
    {
        $this->asClient();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers")->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$this->offer($this->sara)->uuid}/choose")->assertOk();
    }

    /** @return list<string> */
    private function approvals(): array
    {
        return DB::table('freelance_project_approvals')->where('project_id', $this->project->id)->orderBy('id')->pluck('action')->all();
    }

    private function split(array $amounts)
    {
        return $this->putJson(self::CLIENT."/{$this->project->uuid}/agreement/milestones", ['milestones' => array_map(fn ($a, $i) => ['title' => 'Part '.($i + 1), 'amount_minor' => $a], $amounts, array_keys($amounts))]);
    }

    /** Sara's agreement split in two and active; returns the parts' uuids. */
    private function activeInTwo(): array
    {
        $this->chooseSara();
        $this->split([100000, 250000])->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/send")->assertOk();
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/agreement/approve")->assertOk();

        return \Modules\Freelance\Models\FreelanceMilestone::query()->orderBy('position')->pluck('uuid')->all();
    }

    private function topUp(int $withdrawable, int $spendOnly = 0): void
    {
        $w = app(\Modules\Wallet\Services\WalletService::class);
        $wallet = $w->firstOrCreateWallet($this->client, $this->saudi);
        if ($withdrawable > 0) {
            $w->credit($wallet, $withdrawable, \Modules\Wallet\Enums\WalletBucket::Withdrawable, \Modules\Wallet\Enums\WalletTransactionType::Topup);
        }
        if ($spendOnly > 0) {
            $w->credit($wallet, $spendOnly, \Modules\Wallet\Enums\WalletBucket::SpendOnly, \Modules\Wallet\Enums\WalletTransactionType::Topup);
        }
    }

    private function fundCall(string $uuid)
    {
        return $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$uuid}/fund");
    }

    private function holds(): \Illuminate\Support\Collection
    {
        return \Modules\Wallet\Models\WalletHold::query()->where('reference_type', 'freelance_milestone')->get();
    }

    /** Funds the part, starts and delivers it. */
    private function delivered(string $uuid): void
    {
        $this->asClient();
        $this->fundCall($uuid)->assertOk();
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$uuid}/start")->assertOk();
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$uuid}/deliver", ['note' => 'Done, see the demo.'])->assertOk();
    }

    private function wallet($owner): \Modules\Wallet\Models\Wallet
    {
        return app(\Modules\Wallet\Services\WalletService::class)->firstOrCreateWallet($owner, $this->saudi)->fresh();
    }

    private function income(string $slug): int
    {
        return (int) \Modules\Wallet\Models\FinancialEntry::query()->whereHas('category', fn ($q) => $q->where('slug', $slug))->sum('amount_minor');
    }

    private function feesOn(): void
    {
        foreach (['client_fee', 'commission'] as $f) {
            FreelanceFeatureRule::query()->create(['feature' => $f, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
    }

    private function started(string $uuid): void
    {
        $this->asClient();
        $this->fundCall($uuid)->assertOk();
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$uuid}/start")->assertOk();
    }

    private function c(string $uuid, string $action, array $body = [])
    {
        return $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$uuid}/{$action}", $body);
    }

    private function f(string $uuid, string $action, array $body = [])
    {
        return $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$uuid}/{$action}", $body);
    }

    private const CLIENT_SCORES = ['quality' => 5, 'timeliness' => 4, 'communication' => 5, 'professionalism' => 4];

    private const FREELANCER_SCORES = ['clarity' => 4, 'responsiveness' => 4, 'payment' => 5, 'conduct' => 5];

    /** Sara's first part approved and the second dropped: the agreement is completed. */
    private function completed(): void
    {
        [$one, $two] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(500000);
        $this->started($one);
        $this->f($one, 'deliver', ['note' => 'Here it is'])->assertOk();
        $this->asClient();
        $this->c($one, 'approve')->assertOk();
        $this->c($two, 'cancel')->assertOk()->assertJsonPath('data.status', 'completed');
    }

    private function review(string $as, array $body)
    {
        return $this->postJson(($as === 'client' ? self::CLIENT : self::FREELANCER)."/{$this->project->uuid}/review", $body);
    }

    public function test_both_rate_by_criteria_and_see_each_other_only_once_both_did(): void
    {
        $this->completed();
        $this->asClient();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/review")->assertOk()->assertJsonPath('data.can_rate', true)->assertJsonPath('data.criteria.0', 'quality');
        $this->review('client', ['scores' => ['quality' => 5]])->assertStatus(422)->assertJsonPath('error_code', 'freelance_review_scores');
        $this->review('client', ['scores' => self::CLIENT_SCORES, 'comment' => 'Great work, on time.'])->assertCreated()->assertJsonPath('data.mine.stars', 4.5)->assertJsonPath('data.can_rate', false);
        $this->review('client', ['scores' => self::CLIENT_SCORES])->assertStatus(409);
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.review.received')->count());

        // The freelancer doesn't see it before rating too.
        $this->asFreelancer($this->sara);
        $this->getJson(self::FREELANCER."/{$this->project->uuid}/review")->assertJsonPath('data.theirs', null)->assertJsonPath('data.theirs_waiting', true);
        $this->review('freelancer', ['scores' => self::FREELANCER_SCORES])->assertCreated()->assertJsonPath('data.theirs.stars', 4.5)->assertJsonPath('data.theirs.comment', 'Great work, on time.');
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.review.published')->count());
        $this->asClient();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/review")->assertJsonPath('data.theirs.scores.payment', 5);
    }

    public function test_a_lone_rating_shows_when_the_days_are_over_and_rating_closes(): void
    {
        $this->completed();
        $this->asClient();
        $this->review('client', ['scores' => self::CLIENT_SCORES])->assertCreated();
        $this->travel(15)->days();
        $this->artisan('freelance:publish-reviews')->assertSuccessful();
        $this->asFreelancer($this->sara);
        $this->getJson(self::FREELANCER."/{$this->project->uuid}/review")->assertJsonPath('data.theirs.stars', 4.5)->assertJsonPath('data.can_rate', false);
        $this->review('freelancer', ['scores' => self::FREELANCER_SCORES])->assertStatus(409)->assertJsonPath('error_code', 'freelance_review_closed');
    }

    public function test_no_rating_before_the_project_is_completed_and_outsiders_see_nothing(): void
    {
        $this->activeInTwo();
        $this->asClient();
        $this->review('client', ['scores' => self::CLIENT_SCORES])->assertStatus(409)->assertJsonPath('error_code', 'freelance_review_closed');
        $this->asFreelancer($this->lina);
        $this->getJson(self::FREELANCER."/{$this->project->uuid}/review")->assertNotFound();
    }

    public function test_the_freelancers_numbers_show_in_the_offers_and_their_own_page(): void
    {
        $this->completed();
        $this->asClient();
        $this->review('client', ['scores' => self::CLIENT_SCORES, 'comment' => 'Great work, on time.'])->assertCreated();
        $this->asFreelancer($this->sara);
        $this->review('freelancer', ['scores' => self::FREELANCER_SCORES])->assertCreated();

        // One rating: the count shows, the average waits for three.
        $this->getJson('/api/freelance/v1/reviews')->assertOk()->assertJsonPath('data.reviews_count', 1)->assertJsonPath('data.rating', null)
            ->assertJsonPath('data.completed_projects', 1)->assertJsonPath('data.completion_rate', 100)->assertJsonPath('data.latest.0.comment', 'Great work, on time.');

        // Two more ratings from other projects (made directly): the average is out.
        $stats = app(\Modules\Freelance\Services\Projects\FreelanceReviews::class);
        foreach ([4.0, 5.0] as $i => $stars) {
            $r = \App\Models\Rating::query()->create(['author_type' => 'user', 'author_id' => $this->client->id, 'rateable_type' => 'x', 'rateable_id' => 100 + $i, 'unique_key' => hash('sha256', "t{$i}"), 'stars' => $stars, 'type' => 'review']);
            // Each rating is on an agreement of its own (one rating per side per agreement).
            $other = \Modules\Freelance\Models\FreelanceAgreement::query()->firstOrFail()->replicate();
            $other->uuid = (string) \Illuminate\Support\Str::uuid();
            $other->save();
            \Illuminate\Support\Facades\DB::table('freelance_reviews')->insert(['rating_id' => $r->id, 'agreement_id' => $other->id, 'project_id' => $this->project->id, 'side' => 'client', 'freelancer_id' => $this->sara->id, 'client_id' => $this->client->id, 'published_at' => now()]);
        }
        $this->assertSame(3, $stats->stats($this->sara)['reviews_count']);
        $this->assertSame(4.5, $stats->stats($this->sara)['rating']);
    }

    private function asStaff(string ...$permissions): \Modules\Admin\Models\Admin
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = \Modules\Admin\Models\Admin::create(['name' => 'Staff', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $p) {
            \Spatie\Permission\Models\Permission::findOrCreate($p, 'admin_api');
            $admin->givePermissionTo($p);
        }
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    /** FL-706: the freelancer objects to the client's rating; staff hide its comment; the stars stay. */
    public function test_the_rated_person_objects_once_and_staff_can_hide_the_comment(): void
    {
        $this->completed();
        $this->asClient();
        $this->review('client', ['scores' => self::CLIENT_SCORES, 'comment' => 'Terrible person, a thief'])->assertCreated();
        $this->asFreelancer($this->sara);
        // Not out yet: nothing to object to.
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/review/objection", ['reason' => 'abusive', 'note' => 'Insulting words'])->assertStatus(409);
        $this->review('freelancer', ['scores' => self::FREELANCER_SCORES])->assertCreated();

        $this->getJson(self::FREELANCER."/{$this->project->uuid}/review")->assertJsonPath('data.can_object', true)->assertJsonPath('data.objection', null);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/review/objection", ['reason' => 'abusive', 'note' => 'ab'])->assertStatus(422);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/review/objection", ['reason' => 'abusive', 'note' => 'Insulting words, I did nothing wrong'])->assertCreated()
            ->assertJsonPath('data.objection.status', 'open')->assertJsonPath('data.can_object', false);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/review/objection", ['reason' => 'false', 'note' => 'Again please'])->assertStatus(409);
        // Nothing changed while it waits; the client can object to the freelancer's rating of them too.
        $reviews = app(\Modules\Freelance\Services\Projects\FreelanceReviews::class);
        $this->assertSame('Terrible person, a thief', $reviews->latestAbout($this->sara)[0]['comment']);
        $this->asClient();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/review")->assertJsonPath('data.can_object', true);

        $this->asStaff('freelance-reviews.view', 'freelance-reviews.update');
        $row = $this->getJson('/api/admin/v1/freelance-reviews/objections')->assertOk()->assertJsonPath('pagination.counts.open', 1)->assertJsonPath('data.0.rating.comment', 'Terrible person, a thief')->json('data.0');
        $this->postJson("/api/admin/v1/freelance-reviews/objections/{$row['uuid']}/decide", ['decision' => 'hide_comment', 'note' => 'Insults'])->assertOk()->assertJsonPath('data.status', 'comment_hidden');
        $this->postJson("/api/admin/v1/freelance-reviews/objections/{$row['uuid']}/decide", ['decision' => 'reject'])->assertStatus(409);

        $latest = $reviews->latestAbout($this->sara)[0];
        $this->assertNull($latest['comment']);
        $this->assertSame(4.5, $latest['stars'], 'the stars stay');
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.review.objection_decided')->count());
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.review.moderated')->count());
    }

    public function test_a_removed_rating_leaves_the_numbers_and_a_viewer_cannot_decide(): void
    {
        $this->completed();
        $this->asClient();
        $this->review('client', ['scores' => self::CLIENT_SCORES])->assertCreated();
        $this->asFreelancer($this->sara);
        $this->review('freelancer', ['scores' => self::FREELANCER_SCORES])->assertCreated();
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/review/objection", ['reason' => 'false', 'note' => 'This never happened'])->assertCreated();

        $uuid = DB::table('freelance_review_objections')->value('uuid');
        $this->asStaff('freelance-reviews.view');
        $this->postJson("/api/admin/v1/freelance-reviews/objections/{$uuid}/decide", ['decision' => 'remove_rating'])->assertForbidden();
        $this->asStaff('freelance-reviews.view', 'freelance-reviews.update');
        $this->postJson("/api/admin/v1/freelance-reviews/objections/{$uuid}/decide", ['decision' => 'remove_rating'])->assertOk();

        $reviews = app(\Modules\Freelance\Services\Projects\FreelanceReviews::class);
        $this->assertSame([], $reviews->latestAbout($this->sara));
        $this->assertSame(0, $reviews->stats($this->sara)['reviews_count']);
        $this->asFreelancer($this->sara);
        $this->getJson(self::FREELANCER."/{$this->project->uuid}/review")->assertJsonPath('data.theirs', null)->assertJsonPath('data.can_object', false);
    }

    private function survey(string $as): string
    {
        return ($as === 'client' ? self::CLIENT : self::FREELANCER)."/{$this->project->uuid}/survey";
    }

    /** FL-702: a short survey from each side after the project; the reasons are needed when not satisfied. */
    public function test_each_side_answers_the_survey_once_and_staff_see_why_people_are_not_satisfied(): void
    {
        $this->completed();
        $this->asClient();
        $this->getJson($this->survey('client'))->assertOk()->assertJsonPath('data.can_answer', true)->assertJsonPath('data.mine', null);
        $this->postJson($this->survey('client'), ['score' => 2])->assertStatus(422)->assertJsonPath('error_code', 'freelance_survey_reasons_required');
        $this->postJson($this->survey('client'), ['score' => 2, 'reasons' => ['late', 'communication', 'nonsense']])->assertStatus(422);
        $this->postJson($this->survey('client'), ['score' => 2, 'reasons' => ['late', 'communication'], 'comment' => 'Slow replies'])->assertCreated()
            ->assertJsonPath('data.can_answer', false)->assertJsonPath('data.mine.score', 2)->assertJsonPath('data.mine.reasons.0', 'late');
        $this->postJson($this->survey('client'), ['score' => 5])->assertStatus(409);

        $this->asFreelancer($this->sara);
        $this->postJson($this->survey('freelancer'), ['score' => 5, 'reasons' => ['late']])->assertCreated()->assertJsonPath('data.mine.reasons', []);

        $this->asStaff('freelance-reviews.view');
        $this->getJson('/api/admin/v1/freelance-reviews/satisfaction')->assertOk()
            ->assertJsonPath('data.total', 2)->assertJsonPath('data.average', 3.5)->assertJsonPath('data.not_satisfied', 1)
            ->assertJsonPath('data.reasons.0.key', 'quality')->assertJsonPath('data.latest.0.comment', 'Slow replies');
    }

    public function test_the_survey_is_closed_before_the_project_ends(): void
    {
        $this->activeInTwo();
        $this->asClient();
        $this->getJson($this->survey('client'))->assertOk()->assertJsonPath('data.can_answer', false);
        $this->postJson($this->survey('client'), ['score' => 5])->assertStatus(409);
    }
}
