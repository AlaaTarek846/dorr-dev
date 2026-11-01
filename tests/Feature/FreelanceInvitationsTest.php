<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelancePolicy;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceProjectInvitation;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FL-205: a private project — the client invites several freelancers of the country, each says yes or no (a verified account is needed to
 * answer), only they can open it and offer, the client chooses among the offers; when nobody accepted the project opens to everyone.
 */
class FreelanceInvitationsTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '/api/mobile/v1/freelance/projects';

    private const FREELANCER = '/api/freelance/v1';

    private Country $saudi;

    private User $client;

    private FreelanceProject $project;

    private FreelanceSpecialty $web;

    private Freelancer $sara;

    private Freelancer $lina;

    private Freelancer $noor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'invitations', 'chat'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $this->web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $this->web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->project = FreelanceProject::query()->create([
            'client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $this->web->id, 'title' => 'A booking website',
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'private', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
        $this->sara = $this->freelancer('+966500000091', 'Sara');
        $this->lina = $this->freelancer('+966500000092', 'Lina');
        $this->noor = $this->freelancer('+966500000093', 'Noor');
        $this->sara->specialties()->attach($this->web->id);
    }

    private function freelancer(string $phone, string $name, string $status = 'verified', ?Country $country = null): Freelancer
    {
        return Freelancer::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => ($country ?? $this->saudi)->id, 'phone_verified_at' => now(), 'status' => $status]);
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

    private function invite(array $freelancers)
    {
        $this->asClient();

        return $this->postJson(self::CLIENT."/{$this->project->uuid}/invitations", ['freelancers' => array_map(fn (Freelancer $f) => $f->uuid, $freelancers)]);
    }

    private function offer(Freelancer $f)
    {
        $this->asFreelancer($f);

        return $this->postJson(self::FREELANCER."/projects/{$this->project->uuid}/offers", [
            'price_minor' => 350000, 'duration_days' => 21, 'details' => 'I built three booking sites like this; I will deliver in three steps with a demo each week.',
        ]);
    }

    private function invitationOf(Freelancer $f): FreelanceProjectInvitation
    {
        return FreelanceProjectInvitation::query()->where('freelancer_id', $f->id)->firstOrFail();
    }

    public function test_only_the_invited_see_a_private_project_and_offer_on_it(): void
    {
        $this->invite([$this->sara, $this->lina])->assertCreated()->assertJsonPath('data.summary.sent', 2)->assertJsonPath('data.result.invited', 2);
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.invitation.received')->count());

        $this->asFreelancer($this->sara);
        // Not in the list, but hers by its uuid.
        $this->assertSame([], $this->getJson(self::FREELANCER.'/projects')->assertOk()->json('data'));
        $this->getJson(self::FREELANCER."/projects/{$this->project->uuid}")->assertOk()->assertJsonPath('data.invitation.status', 'sent');
        $mine = $this->getJson(self::FREELANCER.'/invitations')->assertOk()->json('data');
        $this->assertCount(1, $mine);
        $this->assertTrue($mine[0]['can']['offer']);
        $this->getJson(self::FREELANCER.'/invitations?per_page=1&page=1')->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('pagination.has_more_pages', false)->assertJsonPath('pagination.per_page', 1);
        $this->getJson(self::FREELANCER.'/invitations?per_page=1&page=2')->assertOk()->assertJsonCount(0, 'data');

        // Noor was not invited: the project doesn't exist for her.
        $this->asFreelancer($this->noor);
        $this->getJson(self::FREELANCER."/projects/{$this->project->uuid}")->assertNotFound();
        $this->offer($this->noor)->assertNotFound();

        // Sara's offer says yes to the invitation; the project stays private while someone has accepted.
        $this->offer($this->sara)->assertCreated();
        $this->assertSame('accepted', $this->invitationOf($this->sara)->status);
        $this->asClient();
        $this->getJson(self::CLIENT."/{$this->project->uuid}")->assertOk()->assertJsonPath('data.invitations.accepted', 1)->assertJsonPath('data.invitations.can_make_public', false);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/make-public")->assertStatus(409)->assertJsonPath('error_code', 'freelance_invitation_accepted_exists');
        $this->assertSame('private', $this->project->fresh()->visibility);

        // Even a freelancer who somehow holds no invitation can't offer on it (the rule is on the offer itself).
        $this->assertSame(1, \Modules\Freelance\Models\FreelanceOffer::query()->count());
    }

    public function test_everyone_says_no_and_the_client_opens_the_project(): void
    {
        $this->invite([$this->sara, $this->lina])->assertCreated();

        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER.'/invitations/'.$this->invitationOf($this->sara)->uuid.'/decline')->assertOk();
        $this->assertSame(0, $this->client->notifications()->where('data->event', 'freelance.invitation.none_left')->count());
        $this->asFreelancer($this->lina);
        $this->postJson(self::FREELANCER.'/invitations/'.$this->invitationOf($this->lina)->uuid.'/decline')->assertOk();
        $this->assertSame(2, $this->client->notifications()->where('data->event', 'freelance.invitation.declined')->count());
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.invitation.none_left')->count());

        // The time to offer ran out meanwhile: opening it starts a new window.
        $this->project->forceFill(['offers_deadline_at' => now()->subDay()])->save();
        $this->asClient();
        $this->getJson(self::CLIENT."/{$this->project->uuid}")->assertJsonPath('data.invitations.can_make_public', true);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/make-public")->assertOk()->assertJsonPath('data.visibility', 'public');
        $this->assertTrue($this->project->fresh()->offers_deadline_at->isFuture());

        // Anyone verified can see and offer now - Noor too.
        $this->asFreelancer($this->noor);
        $this->assertCount(1, $this->getJson(self::FREELANCER.'/projects')->assertOk()->json('data'));
        $this->offer($this->noor)->assertCreated();
        // And no invitation can be sent any more.
        $this->invite([$this->sara])->assertStatus(422)->assertJsonPath('error_code', 'freelance_invitation_project_public');
    }

    public function test_opening_the_project_ends_the_invitations_still_waiting(): void
    {
        $this->invite([$this->sara, $this->lina])->assertCreated();
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER.'/invitations/'.$this->invitationOf($this->sara)->uuid.'/decline')->assertOk();

        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/make-public")->assertOk();
        $this->assertSame('expired', $this->invitationOf($this->lina)->status);
        $this->assertSame('declined', $this->invitationOf($this->sara)->status);

        // The answer to an invitation that ended is refused.
        $this->asFreelancer($this->lina);
        $this->postJson(self::FREELANCER.'/invitations/'.$this->invitationOf($this->lina)->uuid.'/accept')->assertStatus(409)->assertJsonPath('error_code', 'freelance_invitation_not_open');
    }

    public function test_answering_needs_a_verified_account(): void
    {
        $this->invite([$this->sara])->assertCreated();
        $this->sara->forceFill(['status' => 'suspended'])->save();

        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER.'/invitations/'.$this->invitationOf($this->sara)->uuid.'/accept')->assertStatus(403)->assertJsonPath('error_code', 'freelance_verification_required');
        $this->assertSame('sent', $this->invitationOf($this->sara)->status);
    }

    public function test_the_limit_and_the_other_rules_of_inviting(): void
    {
        FreelancePolicy::query()->create(['country_id' => $this->saudi->id, 'data' => ['invitations.max_per_project' => 2], 'status' => true]);
        FreelancePolicies::forget();
        $khadija = $this->freelancer('+966500000094', 'Khadija');

        // Three over a limit of two: nothing is sent.
        $this->invite([$this->sara, $this->lina, $khadija])->assertStatus(422)->assertJsonPath('error_code', 'freelance_invitations_limit');
        $this->assertSame(0, FreelanceProjectInvitation::query()->count());

        $this->invite([$this->sara, $this->lina])->assertCreated();
        // The same again is skipped, not doubled.
        $again = $this->invite([$this->sara])->assertCreated()->json('data.result');
        $this->assertSame([0, 'already_invited'], [$again['invited'], $again['skipped'][0]['reason']]);

        // Taking one back frees the place; a freelancer who said no isn't asked again.
        $this->asClient();
        $this->deleteJson(self::CLIENT."/{$this->project->uuid}/invitations/".$this->invitationOf($this->sara)->uuid)->assertOk();
        $this->asFreelancer($this->sara);
        $this->getJson(self::FREELANCER."/projects/{$this->project->uuid}")->assertNotFound();
        $this->offer($this->sara)->assertNotFound();
        $this->asFreelancer($this->lina);
        $this->postJson(self::FREELANCER.'/invitations/'.$this->invitationOf($this->lina)->uuid.'/decline')->assertOk();
        $result = $this->invite([$this->lina, $khadija])->assertCreated()->json('data.result');
        $this->assertSame([1, 'declined'], [$result['invited'], $result['skipped'][0]['reason']]);

        // Taken back, a freelancer can be invited again - up to the limit.
        $this->invite([$this->sara])->assertCreated()->assertJsonPath('data.result.invited', 1);
        $this->invite([$this->noor])->assertStatus(422)->assertJsonPath('error_code', 'freelance_invitations_limit');
    }

    public function test_my_invitations_can_be_filtered_by_status(): void
    {
        $this->invite([$this->lina])->assertCreated();
        $this->asFreelancer($this->lina);
        $this->getJson(self::FREELANCER.'/invitations?status=sent')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::FREELANCER.'/invitations?status=accepted')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(self::FREELANCER.'/invitations/'.$this->invitationOf($this->lina)->uuid.'/decline')->assertOk();
        $this->getJson(self::FREELANCER.'/invitations?status=declined')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'declined');
        $this->getJson(self::FREELANCER.'/invitations?status=sent')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::FREELANCER.'/invitations')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::FREELANCER.'/invitations?status=bad')->assertStatus(422);
    }

    public function test_a_freelancer_who_already_offered_is_not_invited(): void
    {
        // She offered while the project was open to everyone; it is private again now (no invitation of hers exists).
        $this->project->update(['visibility' => 'public']);
        $this->offer($this->lina)->assertCreated();
        $this->project->update(['visibility' => 'private']);

        $this->asClient();
        $this->assertNotContains($this->lina->uuid, array_column($this->getJson(self::CLIENT."/{$this->project->uuid}/invitees?all=1")->json('data'), 'uuid'));
        $result = $this->invite([$this->lina, $this->noor])->assertCreated()->json('data.result');
        $this->assertSame(1, $result['invited']);
        $this->assertSame([['uuid' => $this->lina->uuid, 'reason' => 'already_offered']], $result['skipped']);
    }

    public function test_the_client_finds_freelancers_to_invite(): void
    {
        $abroad = $this->freelancer('+971500000001', 'Faisal', 'verified', Country::create(['code' => 'AE', 'dial_code' => '+971', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'ae'])->id, 'currency_id' => Currency::create(['code' => 'AED', 'symbol' => 'AED'])->id]));
        $unverified = $this->freelancer('+966500000095', 'Omar', 'profile_ready');
        $this->asClient();

        // By default: those of the project's specialty (only Sara has it).
        $names = fn (array $rows) => array_column($rows, 'name');
        $this->assertSame(['Sara'], $names($this->getJson(self::CLIENT."/{$this->project->uuid}/invitees")->assertOk()->json('data')));
        // Everyone verified in the country, with a search.
        $this->assertSame(['Lina', 'Noor', 'Sara'], $names($this->getJson(self::CLIENT."/{$this->project->uuid}/invitees?all=1")->json('data')));
        $this->assertSame(['Lina'], $names($this->getJson(self::CLIENT."/{$this->project->uuid}/invitees?q=lin")->json('data')));

        // Once invited she isn't offered again.
        $this->invite([$this->sara])->assertCreated();
        $this->assertSame(['Lina', 'Noor'], $names($this->getJson(self::CLIENT."/{$this->project->uuid}/invitees?all=1")->json('data')));
        $this->assertNotContains($abroad->uuid, array_column($this->getJson(self::CLIENT."/{$this->project->uuid}/invitees?all=1")->json('data'), 'uuid'));
        $this->assertNotContains($unverified->uuid, array_column($this->getJson(self::CLIENT."/{$this->project->uuid}/invitees?all=1")->json('data'), 'uuid'));

        // Another client's project isn't theirs to invite on.
        $other = User::query()->create(['name' => 'Ali', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($other, [], 'user_api');
        $this->postJson(self::CLIENT."/{$this->project->uuid}/invitations", ['freelancers' => [$this->lina->uuid]])->assertNotFound();
    }
}
