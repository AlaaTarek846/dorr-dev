<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceAgreementTeamMember;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\FreelancerTeam\FreelancerTeams;
use Modules\Freelance\Services\FreelancerTeam\TeamAbilities as A;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Projects\FreelanceReviews;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FS-205: an Owner sends an offer in a freelancers' team's name - with the people who will work on it (at most one PM) - and it counts against
 * the team's own monthly quota, not his. The agreement stays his to sign (D2) and carries the team and the people; the client sees the team's
 * name and each person's first name, role and what they take on - nothing more (D10); a rating of a team's work is the team's, not the
 * Owner's own (D3); and only the Owner delivers, whatever a PM or member is switched on for (D11).
 */
class FreelanceTeamOffersTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1';

    private const CLIENT = '/api/mobile/v1/freelance/projects';

    private Country $saudi;

    private FreelanceSpecialty $web;

    private User $client;

    private FreelanceTeam $team;

    private Freelancer $sara;

    private Freelancer $lina;

    private Freelancer $noor;

    private Freelancer $huda;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat', 'wallet_payment', 'free_offer_quota', 'commission', 'teams'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $this->web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $this->web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);

        $this->sara = $this->freelancer('+966500000091', 'Sara Ali');
        $this->lina = $this->freelancer('+966500000092', 'Lina Hassan');
        $this->noor = $this->freelancer('+966500000093', 'Noor Said');
        $this->huda = $this->freelancer('+966500000094', 'Huda Omar');
        $teams = app(FreelancerTeams::class);
        $this->team = $teams->create($this->sara, 'Dorr Studio');
        $teams->join($this->team, $this->lina, Member::PM);
        $teams->join($this->team, $this->noor);
    }

    private function freelancer(string $phone, string $name): Freelancer
    {
        return Freelancer::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
    }

    private function as(Freelancer $who): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($who->refresh(), [], 'freelance_api');
    }

    private function asClient(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->client->refresh(), [], 'user_api');
    }

    private function project(string $title = 'A booking website'): FreelanceProject
    {
        return FreelanceProject::query()->create([
            'client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $this->web->id, 'title' => $title,
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
    }

    /** @return list<array<string, string>> */
    private function staff(): array
    {
        return [
            ['freelancer' => $this->lina->uuid, 'role' => 'pm', 'responsibility' => 'Runs the project and talks to the client'],
            ['freelancer' => $this->noor->uuid, 'role' => 'executor', 'responsibility' => 'Builds the screens'],
        ];
    }

    /** @param  array<string, mixed>  $over */
    private function body(array $over = []): array
    {
        return $over + ['price_minor' => 350000, 'duration_days' => 21, 'details' => 'We built three booking sites like this; we will deliver in three steps with a demo each week.'];
    }

    /** The Owner offers in the team's name. */
    private function sendTeam(FreelanceProject $p, array $over = [])
    {
        return $this->postJson(self::API."/projects/{$p->uuid}/offers", $this->body($over + ['team' => $this->team->uuid, 'staff' => $this->staff()]));
    }

    private function told(Freelancer|User $who, string $event): int
    {
        return $who->notifications()->where('data->event', $event)->count();
    }

    /** The client chooses the team's offer, sends the agreement and the Owner approves it. */
    private function active(FreelanceProject $p): void
    {
        $this->as($this->sara);
        $uuid = $this->sendTeam($p)->assertCreated()->json('data.uuid');
        $this->asClient();
        $this->getJson(self::CLIENT."/{$p->uuid}/offers")->assertOk();
        $this->postJson(self::CLIENT."/{$p->uuid}/offers/{$uuid}/choose")->assertOk();
        $this->postJson(self::CLIENT."/{$p->uuid}/agreement/send")->assertOk();
        $this->as($this->sara);
        $this->postJson(self::API."/projects/{$p->uuid}/agreement/approve")->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_the_owner_sends_an_offer_in_the_teams_name(): void
    {
        $p = $this->project();
        $this->as($this->sara);
        $this->getJson(self::API."/projects/{$p->uuid}/offer-preview?team={$this->team->uuid}")->assertOk()
            ->assertJsonPath('data.blocked', null)->assertJsonPath('data.team_quota.limit', null)->assertJsonPath('data.team_quota.used', 0);

        $res = $this->sendTeam($p)->assertCreated()
            ->assertJsonPath('data.team.uuid', $this->team->uuid)->assertJsonPath('data.team.name', 'Dorr Studio')->assertJsonCount(2, 'data.team.members')
            ->assertJsonPath('data.team.members.0.name', 'Lina Hassan')->assertJsonPath('data.team.members.0.role', 'pm')
            ->assertJsonPath('data.team.members.1.role', 'executor')->assertJsonPath('data.team.members.1.responsibility', 'Builds the screens');

        $offer = FreelanceOffer::query()->where('uuid', $res->json('data.uuid'))->firstOrFail();
        $this->assertSame($this->team->id, $offer->team_id);
        $this->assertSame($this->sara->id, $offer->freelancer_id, 'the Owner who sends is the one who signs');
        $this->assertFalse((bool) $offer->free, 'a team offer never spends the Owner\'s own free offers');
        $this->assertNull($offer->credit_id);
        $this->assertSame(['pm', 'executor'], $offer->staff()->pluck('role')->all());

        // The people on it are told; the Owner who put them there is not.
        $this->assertSame(1, $this->told($this->lina, 'freelance.team_offer_staffed'));
        $this->assertSame(1, $this->told($this->noor, 'freelance.team_offer_staffed'));
        $this->assertSame(0, $this->told($this->sara, 'freelance.team_offer_staffed'));
        $this->assertSame(0, $this->told($this->huda, 'freelance.team_offer_staffed'));

        // The Owner's own quota is untouched, and an ordinary offer says nothing of a team.
        $p2 = $this->project('Another job');
        $this->getJson(self::API."/projects/{$p2->uuid}/offer-preview")->assertJsonPath('data.quota.used', 0)->assertJsonPath('data.team_quota', null);
        $this->postJson(self::API."/projects/{$p2->uuid}/offers", $this->body())->assertCreated()->assertJsonPath('data.team', null);
    }

    public function test_only_an_owner_sends_and_only_for_an_active_team_where_teams_are_on(): void
    {
        $p = $this->project();

        $this->as($this->noor);
        $this->sendTeam($p)->assertForbidden()->assertJsonPath('error_code', 'freelance_team_offer_forbidden'); // a plain member
        $this->as($this->huda);
        $this->sendTeam($p)->assertNotFound();

        $this->as($this->sara);
        $this->team->update(['status' => FreelanceTeam::SUSPENDED]);
        $this->sendTeam($p)->assertStatus(409)->assertJsonPath('error_code', 'freelance_freelancer_team_inactive');
        $this->team->update(['status' => FreelanceTeam::ACTIVE]);

        FreelanceFeatureRule::query()->where('feature', 'teams')->update(['enabled' => false]);
        FreelanceFeatures::forget();
        $this->as($this->sara);
        $this->sendTeam($p)->assertForbidden()->assertJsonPath('error_code', 'freelance_feature_off');
        // An ordinary offer is still fine where teams are off.
        $this->postJson(self::API."/projects/{$p->uuid}/offers", $this->body())->assertCreated();
        $this->assertSame(0, FreelanceOffer::query()->whereNotNull('team_id')->count());
    }

    public function test_a_pm_sends_the_team_offer_naming_the_owner_who_signs(): void
    {
        $p = $this->project();
        $this->as($this->lina);
        $this->sendTeam($p)->assertStatus(422)->assertJsonPath('error_code', 'freelance_team_offer_signer_required');
        $this->sendTeam($p, ['signer' => $this->noor->uuid])->assertStatus(422)->assertJsonPath('error_code', 'freelance_team_offer_signer_invalid'); // not an Owner
        $this->getJson(self::API."/projects/{$p->uuid}/offer-preview?team={$this->team->uuid}&signer={$this->sara->uuid}")->assertOk()->assertJsonPath('data.blocked', null);

        $uuid = $this->sendTeam($p, ['signer' => $this->sara->uuid])->assertCreated()
            ->assertJsonPath('data.submitted_by.name', 'Lina Hassan')->json('data.uuid');
        $offer = FreelanceOffer::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame($this->sara->id, (int) $offer->freelancer_id, 'the offer is the signing Owner\'s (D2)');
        $this->assertSame($this->lina->id, (int) $offer->submitted_by_freelancer_id);
        $this->assertSame($this->lina->id, (int) DB::table('freelance_offer_events')->where('type', 'offer.submitted')->value('acted_by_freelancer_id'));
        $this->assertSame(1, $this->told($this->sara, 'freelance.team_offer_submitted'));

        // She follows it, changes it and can take it back; one offer per project still holds (the signer's).
        $this->getJson(self::API."/projects/{$p->uuid}/my-offer")->assertOk()->assertJsonPath('data.offer.uuid', $uuid);
        $this->getJson(self::API.'/offers')->assertOk()->assertJsonPath('data.0.uuid', $uuid);
        $this->putJson(self::API."/offers/{$uuid}", ['price_minor' => 360000])->assertOk()->assertJsonPath('data.price_minor', 360000);
        $this->sendTeam($p, ['signer' => $this->sara->uuid])->assertStatus(409);
        $this->as($this->sara);
        $this->getJson(self::API."/projects/{$p->uuid}/my-offer")->assertOk()->assertJsonPath('data.offer.uuid', $uuid)->assertJsonPath('data.offer.submitted_by.name', 'Lina Hassan');
        $this->asClient();
        $this->getJson(self::CLIENT."/{$p->uuid}/offers")->assertOk()->assertJsonPath('data.offers.0.submitted_by', null);

        $this->as($this->lina);
        $this->postJson(self::API."/offers/{$uuid}/withdraw")->assertOk();
        $this->assertSame('withdrawn', $offer->refresh()->status->value);

        // An ordinary member of the team never sends.
        $this->as($this->noor);
        $this->sendTeam($this->project('Another'), ['signer' => $this->sara->uuid])->assertForbidden()->assertJsonPath('error_code', 'freelance_team_offer_forbidden');
    }

    public function test_the_people_on_an_offer_must_be_active_members_each_once_with_one_pm_at_most(): void
    {
        $p = $this->project();
        $this->as($this->sara);
        $bad = [
            'somebody outside the team' => [['freelancer' => $this->huda->uuid, 'role' => 'executor']],
            'nobody at all' => [['freelancer' => (string) Str::uuid(), 'role' => 'executor']],
            'the same person twice' => [['freelancer' => $this->lina->uuid, 'role' => 'pm'], ['freelancer' => $this->lina->uuid, 'role' => 'executor']],
            'two PMs' => [['freelancer' => $this->lina->uuid, 'role' => 'pm'], ['freelancer' => $this->noor->uuid, 'role' => 'pm']],
        ];
        foreach ($bad as $why => $staff) {
            $this->sendTeam($p, ['staff' => $staff])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_team_offer_staff_invalid', $why);
        }

        // Somebody who left the team is not one of its people any more.
        app(FreelancerTeams::class)->leave($this->team, $this->noor);
        $this->sendTeam($p)->assertUnprocessable()->assertJsonPath('error_code', 'freelance_team_offer_staff_invalid');

        // The shape is checked too: a role of the list, a team when there are people, not more than twenty.
        $this->sendTeam($p, ['staff' => [['freelancer' => $this->lina->uuid, 'role' => 'boss']]])->assertUnprocessable();
        $this->postJson(self::API."/projects/{$p->uuid}/offers", $this->body(['staff' => [['freelancer' => $this->lina->uuid, 'role' => 'pm']]]))->assertUnprocessable();
        $this->sendTeam($p, ['staff' => array_fill(0, 21, ['freelancer' => $this->lina->uuid, 'role' => 'executor'])])->assertUnprocessable();
        $this->assertSame(0, FreelanceOffer::query()->count(), 'nothing was written');

        // A team's offer with nobody picked is fine: it is the team's name alone.
        $this->sendTeam($p, ['staff' => []])->assertCreated()->assertJsonPath('data.team.name', 'Dorr Studio')->assertJsonCount(0, 'data.team.members');
    }

    public function test_a_team_offer_counts_against_the_teams_quota_not_the_owners_and_taking_it_back_gives_the_place_back(): void
    {
        app(FreelancePolicies::class)->save($this->saudi->id, null, ['teams.offers_monthly' => 1]);
        [$p1, $p2, $p3] = [$this->project('One'), $this->project('Two'), $this->project('Three')];
        $this->as($this->sara);

        $first = $this->sendTeam($p1)->assertCreated()->json('data.uuid');
        $this->getJson(self::API."/projects/{$p2->uuid}/offer-preview?team={$this->team->uuid}")->assertOk()
            ->assertJsonPath('data.team_quota.limit', 1)->assertJsonPath('data.team_quota.used', 1)->assertJsonPath('data.team_quota.left', 0)
            ->assertJsonPath('data.blocked.code', 'freelance_team_offer_quota_exhausted');
        $this->sendTeam($p2)->assertStatus(422)->assertJsonPath('error_code', 'freelance_team_offer_quota_exhausted');

        // The Owner's own quota is another one: untouched by the team's offer, and his own offer is not stopped by the team's.
        $this->getJson(self::API."/projects/{$p2->uuid}/offer-preview")->assertJsonPath('data.quota.used', 0)->assertJsonPath('data.blocked', null);
        $this->postJson(self::API."/projects/{$p2->uuid}/offers", $this->body())->assertCreated();
        $this->getJson(self::API."/projects/{$p3->uuid}/offer-preview")->assertJsonPath('data.quota.used', 1);

        // Taken back before the client saw it, the team's place is free again.
        $this->postJson(self::API."/offers/{$first}/withdraw")->assertOk();
        $event = DB::table('freelance_offer_events')->where('type', 'offer.withdrawn')->value('payload');
        $this->assertTrue(json_decode((string) $event, true)['quota_returned']);
        $this->getJson(self::API."/projects/{$p3->uuid}/offer-preview?team={$this->team->uuid}")->assertJsonPath('data.team_quota.used', 0)->assertJsonPath('data.blocked', null);

        // One offer per project still holds when it is the Owner's own offer already there.
        $this->sendTeam($p2)->assertStatus(409)->assertJsonPath('error_code', 'freelance_offer_already_sent');
        $this->sendTeam($p3)->assertCreated();
    }

    public function test_the_owner_changes_the_people_while_the_offer_is_open(): void
    {
        $p = $this->project();
        $this->as($this->sara);
        $pm = ['freelancer' => $this->lina->uuid, 'role' => 'pm', 'responsibility' => 'Runs the project'];
        $uuid = $this->sendTeam($p, ['staff' => [$pm]])->assertCreated()->json('data.uuid');

        // Huda joins the team and is put on the offer; Lina is not told twice.
        app(FreelancerTeams::class)->join($this->team, $this->huda);
        $qa = ['freelancer' => $this->huda->uuid, 'role' => 'executor', 'responsibility' => 'Tests it'];
        $this->putJson(self::API."/offers/{$uuid}", ['staff' => [$pm, $qa]])->assertOk()->assertJsonCount(2, 'data.team.members')->assertJsonPath('data.edited', true);
        $this->assertSame(1, $this->told($this->huda, 'freelance.team_offer_staffed'));
        $this->assertSame(1, $this->told($this->lina, 'freelance.team_offer_staffed'));
        $this->assertSame(1, $this->told($this->client, 'freelance.offer.updated'), 'the client is told the offer changed');

        // The same people again is no change.
        $this->putJson(self::API."/offers/{$uuid}", ['staff' => [$pm, $qa]])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_offer_unchanged');
        // Somebody taken off.
        $this->putJson(self::API."/offers/{$uuid}", ['staff' => [$qa]])->assertOk()->assertJsonCount(1, 'data.team.members')->assertJsonPath('data.team.members.0.name', 'Huda Omar');
        // The team of an offer can not be swapped, and a personal offer has no people.
        $this->putJson(self::API."/offers/{$uuid}", ['team' => $this->team->uuid, 'details' => str_repeat('More words about the plan. ', 4)])->assertUnprocessable();
        $p2 = $this->project('Another job');
        $mine = $this->postJson(self::API."/projects/{$p2->uuid}/offers", $this->body())->assertCreated()->json('data.uuid');
        $this->putJson(self::API."/offers/{$mine}", ['staff' => [$pm]])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_team_offer_staff_invalid');

        // Only the Owner of the team changes them.
        $this->as($this->lina);
        $this->putJson(self::API."/offers/{$uuid}", ['staff' => [$pm]])->assertNotFound();
    }

    public function test_the_client_sees_the_team_and_first_names_only_and_the_owner_sees_everything_of_his_own(): void
    {
        $p = $this->project();
        $this->as($this->sara);
        $this->sendTeam($p)->assertCreated();

        $this->asClient();
        $res = $this->getJson(self::CLIENT."/{$p->uuid}/offers")->assertOk()
            ->assertJsonPath('data.0.team.name', 'Dorr Studio')->assertJsonCount(2, 'data.0.team.members')
            ->assertJsonPath('data.0.team.members.0.name', 'Lina')->assertJsonPath('data.0.team.members.0.role', 'pm')
            ->assertJsonPath('data.0.team.members.0.responsibility', 'Runs the project and talks to the client')
            ->assertJsonPath('data.0.team.members.1.name', 'Noor')
            ->assertJsonMissingPath('data.0.team.members.0.freelancer')->assertJsonMissingPath('data.0.team.members.0.uuid');
        $body = $res->getContent();
        foreach (['Hassan', 'Said', '+966500000092', '+966500000093', $this->lina->uuid, $this->noor->uuid] as $secret) {
            $this->assertStringNotContainsString($secret, $body, "the client must not see {$secret}");
        }

        $this->as($this->sara);
        $this->getJson(self::API."/projects/{$p->uuid}/my-offer")->assertOk()
            ->assertJsonPath('data.offer.team.members.0.name', 'Lina Hassan')->assertJsonPath('data.offer.team.members.0.freelancer', $this->lina->uuid);
    }

    public function test_the_agreement_is_in_the_teams_name_signed_by_the_owner_and_keeps_the_people_it_started_with(): void
    {
        $p = $this->project();
        $this->as($this->sara);
        $uuid = $this->sendTeam($p)->assertCreated()->json('data.uuid');

        $this->asClient();
        $this->getJson(self::CLIENT."/{$p->uuid}/offers")->assertOk();
        $this->postJson(self::CLIENT."/{$p->uuid}/offers/{$uuid}/choose")->assertOk();
        $agreement = FreelanceAgreement::query()->firstOrFail();
        $this->assertSame($this->team->id, $agreement->team_id);
        $this->assertSame($this->sara->id, $agreement->freelancer_id, 'the Owner signs, and is paid (D2)');
        $this->assertSame(0, FreelanceAgreementTeamMember::query()->count(), 'the people are frozen when the agreement starts, not before');
        // Until then the client reads the offer's people.
        $this->getJson(self::CLIENT."/{$p->uuid}/agreement")->assertOk()->assertJsonPath('data.team.name', 'Dorr Studio')->assertJsonCount(2, 'data.team.members')
            ->assertJsonPath('data.team.members.0.name', 'Lina');

        $this->postJson(self::CLIENT."/{$p->uuid}/agreement/send")->assertOk();
        $this->as($this->sara);
        $this->postJson(self::API."/projects/{$p->uuid}/agreement/approve")->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame(['pm', 'executor'], FreelanceAgreementTeamMember::query()->orderBy('id')->pluck('role')->all());
        $this->assertSame($agreement->id, FreelanceAgreementTeamMember::query()->value('agreement_id'));

        // The team changing later does not change who the agreement was made with.
        app(FreelancerTeams::class)->leave($this->team, $this->lina);
        $this->getJson(self::API."/projects/{$p->uuid}/agreement")->assertOk()->assertJsonCount(2, 'data.team.members')->assertJsonPath('data.team.members.0.name', 'Lina Hassan');
        $this->asClient();
        $this->getJson(self::CLIENT."/{$p->uuid}/agreement")->assertOk()->assertJsonPath('data.team.members.0.name', 'Lina');
        $this->assertSame(2, FreelanceAgreementTeamMember::query()->count());
    }

    public function test_the_rating_of_a_teams_work_is_the_teams_and_never_goes_into_the_owners_own(): void
    {
        $p = $this->project();
        $this->as($this->sara);
        $uuid = $this->sendTeam($p)->assertCreated()->json('data.uuid');
        $this->asClient();
        $this->getJson(self::CLIENT."/{$p->uuid}/offers")->assertOk();
        $this->postJson(self::CLIENT."/{$p->uuid}/offers/{$uuid}/choose")->assertOk();
        FreelanceAgreement::query()->update(['status' => FreelanceAgreement::COMPLETED, 'completed_at' => now()]);
        $agreement = FreelanceAgreement::query()->firstOrFail();

        $reviews = app(FreelanceReviews::class);
        $reviews->rate($agreement, $this->client, 'client', ['quality' => 5, 'timeliness' => 5, 'communication' => 5, 'professionalism' => 5], 'A great team');
        $reviews->rate($agreement, $this->sara, 'freelancer', ['clarity' => 4, 'responsiveness' => 4, 'payment' => 4, 'conduct' => 4], null);

        $this->assertSame($this->team->id, (int) DB::table('freelance_reviews')->where('side', 'client')->value('team_id'));
        $this->assertNull(DB::table('freelance_reviews')->where('side', 'freelancer')->value('team_id'));
        $this->assertSame(0, $reviews->stats($this->sara)['reviews_count'], 'the Owner\'s own rating does not take the team\'s');
        $this->assertSame([], $reviews->latestAbout($this->sara));

        // D3 goes for the project record too: a completed team project is the team's, not the Owner's completed / completion figures.
        FreelanceAgreement::query()->update(['activated_at' => now()->subDays(10)]);
        $own = $reviews->stats($this->sara);
        $this->assertSame(0, $own['completed_projects']);
        $this->assertNull($own['completion_rate']);
        $team = $reviews->teamStats($this->team);
        $this->assertSame(1, $team['reviews_count']);
        $this->assertSame(1, $team['completed_projects']);
        $this->assertSame(100, $team['completion_rate']);
        $this->assertSame(0, $reviews->teamStats(app(FreelancerTeams::class)->create($this->huda, 'Another'))['completed_projects']);

        // The control: the same work without the team is the person's.
        DB::table('freelance_reviews')->where('side', 'client')->update(['team_id' => null]);
        FreelanceAgreement::query()->update(['team_id' => null]);
        $this->assertSame(1, $reviews->stats($this->sara)['reviews_count']);
        $this->assertSame(1, $reviews->stats($this->sara)['completed_projects']);
        $this->assertSame(100, $reviews->stats($this->sara)['completion_rate']);
        $this->assertCount(1, $reviews->latestAbout($this->sara));
    }

    public function test_only_the_owner_delivers_whatever_a_pm_or_member_is_switched_on_for(): void
    {
        $p = $this->project();
        $this->active($p);
        $milestone = FreelanceMilestone::query()->firstOrFail();
        $w = app(\Modules\Wallet\Services\WalletService::class);
        $w->credit($w->firstOrCreateWallet($this->client, $this->saudi), $milestone->amount_minor + $milestone->client_fee_minor, \Modules\Wallet\Enums\WalletBucket::Withdrawable, \Modules\Wallet\Enums\WalletTransactionType::Topup);
        app(\Modules\Freelance\Services\Projects\MilestonePayments::class)->fund($milestone->load('agreement'), $this->client);
        $this->as($this->sara);
        $this->postJson(self::API."/projects/{$p->uuid}/milestones/{$milestone->uuid}/start")->assertOk();

        // Every switch on - even the ones a team does not offer (D11 holds even when the data says otherwise).
        Member::query()->whereIn('freelancer_id', [$this->lina->id, $this->noor->id])->update(['permissions' => json_encode(array_fill_keys(A::ALL, true))]);
        foreach ([$this->lina, $this->noor] as $who) {
            $this->as($who);
            // FS-206a: they reach the project now (a PM, a member who works on it) - and are refused the delivery.
            $this->postJson(self::API."/projects/{$p->uuid}/milestones/{$milestone->uuid}/deliver", ['note' => 'Done.'])->assertForbidden()
                ->assertJsonPath('error_code', 'freelance_team_project_forbidden');
        }
        $this->assertSame('in_progress', (string) DB::table('freelance_milestones')->where('id', $milestone->id)->value('status'));
        $this->assertSame(0, DB::table('freelance_milestone_events')->where('type', 'milestone.delivered')->count());

        // The Owner who signed is the one who delivers.
        $this->as($this->sara);
        $this->postJson(self::API."/projects/{$p->uuid}/milestones/{$milestone->uuid}/deliver", ['note' => 'Done.'])->assertOk()->assertJsonPath('data.milestones.0.status', 'delivered');
    }

    public function test_the_texts_of_the_new_errors_and_notice_are_in_both_languages(): void
    {
        foreach (['team_offer_forbidden', 'team_offer_staff_invalid', 'team_offer_quota_exhausted'] as $code) {
            foreach (['ar', 'en'] as $locale) {
                $this->assertTrue(Lang::has("freelance.errors.{$code}", $locale, false), "{$code} is missing in {$locale}");
            }
        }
        foreach (['freelance_team_offer_staffed_title', 'freelance_team_offer_staffed_body'] as $key) {
            foreach (['ar', 'en'] as $locale) {
                $this->assertTrue(Lang::has("notifications.{$key}", $locale, false), "{$key} is missing in {$locale}");
            }
        }
    }
}
