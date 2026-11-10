<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Events\FreelanceWorkroomChanged;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceProjectEvent;
use Modules\Freelance\Models\FreelanceProjectMember;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\FreelanceWorkroomDecision;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FS-150c: the Workroom's decision log and timeline - a side proposes a decision (the proposer has agreed by proposing), the other side agrees or
 * declines it; a waiting proposal is changed or withdrawn by its writer only; an answered decision is the record and never changes, a new one
 * supersedes it; the timeline lists what happened (the Workroom's steps and the agreement's approvals) newest first, each side seeing only the
 * events meant for it, and never the text of a note or a decision; after the agreement ends everything is only read; a member of the client's
 * team reads and a manager works.
 */
class FreelanceWorkroomDecisionsTest extends TestCase
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

    private Freelancer $lina;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat', 'client_team', 'workroom'] as $feature) {
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
        $this->sara = $this->freelancer('+966500000091', 'Sara');
        $this->lina = $this->freelancer('+966500000092', 'Lina');
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

    private function freelancer(string $phone, string $name): Freelancer
    {
        return Freelancer::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
    }

    private function as(User|Freelancer $who): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($who->refresh(), [], $who instanceof Freelancer ? 'freelance_api' : 'user_api');
    }

    private function join(User $who, string $role): FreelanceProjectMember
    {
        $this->as($this->owner);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/team", ['phone' => $who->phone, 'role' => $role])->assertOk();
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

    private function decisions(string $prefix, string $suffix = ''): string
    {
        return $prefix."/{$this->project->uuid}/workroom/decisions".$suffix;
    }

    private function timeline(string $prefix, string $query = ''): string
    {
        return $prefix."/{$this->project->uuid}/workroom/timeline".$query;
    }

    /** The client proposes a decision; returns its uuid. */
    private function clientDecision(array $extra = []): string
    {
        $this->as($this->owner);

        return (string) $this->postJson($this->decisions(self::CLIENT), $extra + ['title' => 'Use the brand blue', 'body' => 'The logo and the buttons use the blue of the brand guide.'])
            ->assertCreated()->json('data.decision.uuid');
    }

    private function freelancerDecision(array $extra = []): string
    {
        $this->as($this->sara);

        return (string) $this->postJson($this->decisions(self::FREELANCER), $extra + ['title' => 'Two languages', 'body' => 'The site comes in Arabic and English, Arabic first.'])
            ->assertCreated()->json('data.decision.uuid');
    }

    private function told(User|Freelancer $who, string $event): int
    {
        return $who->notifications()->where('data->event', $event)->count();
    }

    public function test_the_decision_log_and_the_timeline_are_off_until_the_admin_opens_the_workroom_for_the_country(): void
    {
        $this->active();
        $decision = $this->clientDecision();
        FreelanceFeatureRule::query()->where('feature', 'workroom')->delete();
        FreelanceFeatures::forget();

        $this->as($this->owner);
        $this->getJson($this->decisions(self::CLIENT))->assertForbidden()->assertJsonPath('error_code', 'freelance_feature_off');
        $this->getJson($this->timeline(self::CLIENT))->assertForbidden()->assertJsonPath('error_code', 'freelance_feature_off');
        $this->postJson($this->decisions(self::CLIENT), ['title' => 'Another one', 'body' => 'Text'])->assertForbidden();
        $this->as($this->sara);
        $this->getJson($this->decisions(self::FREELANCER))->assertForbidden()->assertJsonPath('error_code', 'freelance_feature_off');
        $this->getJson($this->timeline(self::FREELANCER))->assertForbidden()->assertJsonPath('error_code', 'freelance_feature_off');
        $this->postJson($this->decisions(self::FREELANCER, "/{$decision}/agree"))->assertForbidden();
        // What was written stays; it is only not shown.
        $this->assertSame(1, FreelanceWorkroomDecision::query()->count());
        $this->assertSame(FreelanceWorkroomDecision::PROPOSED, FreelanceWorkroomDecision::query()->firstOrFail()->status);
    }

    public function test_a_side_proposes_a_decision_and_the_other_side_agrees_it(): void
    {
        $parts = $this->active();
        Event::fake([FreelanceWorkroomChanged::class]);

        $this->as($this->sara);
        $created = $this->postJson($this->decisions(self::FREELANCER), ['title' => 'The logo is blue', 'body' => 'We use the blue of the brand guide.', 'milestone' => $parts[0]])
            ->assertCreated()
            ->assertJsonPath('data.decision.title', 'The logo is blue')->assertJsonPath('data.decision.body', 'We use the blue of the brand guide.')
            ->assertJsonPath('data.decision.status', 'proposed')->assertJsonPath('data.decision.proposed_by_side', 'freelancer')->assertJsonPath('data.decision.mine', true)
            ->assertJsonPath('data.decision.freelancer_agreed', true)->assertJsonPath('data.decision.client_agreed', false)->assertJsonPath('data.decision.milestone.title', 'Design')
            ->assertJsonPath('data.decision.supersedes', null)->assertJsonPath('data.decision.superseded_by', null)->assertJsonPath('data.decision.answered_at', null)
            ->assertJsonPath('data.decision.can.decision_edit', true)->assertJsonPath('data.decision.can.decision_withdraw', true)
            ->assertJsonPath('data.decision.can.decision_agree', false)->assertJsonPath('data.decision.can.decision_decline', false)->assertJsonPath('data.decision.can.decision_supersede', false)
            ->assertJsonPath('data.summary.total', 1)->assertJsonPath('data.summary.proposed', 1)->assertJsonPath('data.summary.waiting_for_me', 0);
        $uuid = (string) $created->json('data.decision.uuid');

        // The client is told once, and sees a decision that waits for its answer.
        $this->assertSame(1, $this->told($this->owner, 'freelance.workroom.decision_proposed'));
        $this->assertSame(0, $this->told($this->sara, 'freelance.workroom.decision_proposed'));
        $this->as($this->owner);
        $this->getJson($this->decisions(self::CLIENT))->assertOk()->assertJsonCount(1, 'data.decisions')->assertJsonPath('data.decisions.0.title', 'The logo is blue')
            ->assertJsonPath('data.decisions.0.mine', false)->assertJsonPath('data.decisions.0.can.decision_agree', true)->assertJsonPath('data.decisions.0.can.decision_decline', true)
            ->assertJsonPath('data.decisions.0.can.decision_edit', false)->assertJsonPath('data.decisions.0.can.decision_withdraw', false)
            ->assertJsonPath('data.summary.waiting_for_me', 1)->assertJsonPath('data.can.decision_create', true)->assertJsonPath('data.read_only', false);
        $this->getJson($this->decisions(self::CLIENT, "/{$uuid}"))->assertOk()->assertJsonPath('data.decision.uuid', $uuid);

        $this->postJson($this->decisions(self::CLIENT, "/{$uuid}/agree"), ['note' => 'Looks good'])->assertOk()
            ->assertJsonPath('data.decision.status', 'agreed')->assertJsonPath('data.decision.client_agreed', true)->assertJsonPath('data.decision.freelancer_agreed', true)
            ->assertJsonPath('data.decision.answer_note', 'Looks good')->assertJsonPath('data.decision.can.decision_agree', false)->assertJsonPath('data.decision.can.decision_decline', false)
            ->assertJsonPath('data.decision.can.decision_edit', false)->assertJsonPath('data.decision.can.decision_supersede', true)
            ->assertJsonPath('data.summary.agreed', 1)->assertJsonPath('data.summary.proposed', 0)->assertJsonPath('data.summary.waiting_for_me', 0);
        $this->assertNotNull($this->getJson($this->decisions(self::CLIENT, "/{$uuid}"))->json('data.decision.answered_at'));

        // The proposer is told of the answer, and the live screens reloaded once per step.
        $this->assertSame(1, $this->told($this->sara, 'freelance.workroom.decision_agreed'));
        $this->assertSame(0, $this->told($this->owner, 'freelance.workroom.decision_agreed'));
        Event::assertDispatchedTimes(FreelanceWorkroomChanged::class, 2);
        Event::assertDispatched(FreelanceWorkroomChanged::class, fn (FreelanceWorkroomChanged $e) => $e->action === 'created' && $e->by === 'freelancer' && $e->uuid === $uuid
            && $e->projectUuid === $this->project->uuid && $e->broadcastWith()['kind'] === 'decision');
        Event::assertDispatched(FreelanceWorkroomChanged::class, fn (FreelanceWorkroomChanged $e) => $e->action === 'agreed' && $e->by === 'client' && $e->uuid === $uuid);

        // Sara sees the same record, the other way round.
        $this->as($this->sara);
        $this->getJson($this->decisions(self::FREELANCER, "/{$uuid}"))->assertOk()->assertJsonPath('data.decision.status', 'agreed')->assertJsonPath('data.decision.mine', true);
    }

    public function test_a_declined_decision_keeps_its_answer_and_is_locked(): void
    {
        $this->active();
        $uuid = $this->freelancerDecision();

        $this->as($this->owner);
        $this->postJson($this->decisions(self::CLIENT, "/{$uuid}/decline"), ['note' => 'Not this blue'])->assertOk()
            ->assertJsonPath('data.decision.status', 'declined')->assertJsonPath('data.decision.answer_note', 'Not this blue')->assertJsonPath('data.decision.client_agreed', false)
            ->assertJsonPath('data.decision.can.decision_agree', false)->assertJsonPath('data.decision.can.decision_supersede', false)
            ->assertJsonPath('data.summary.declined', 1)->assertJsonPath('data.summary.waiting_for_me', 0);
        $this->assertSame(1, $this->told($this->sara, 'freelance.workroom.decision_declined'));

        // An answered decision is the record: no one answers it again, changes it or takes it back.
        $this->postJson($this->decisions(self::CLIENT, "/{$uuid}/agree"))->assertStatus(409)->assertJsonPath('error_code', 'freelance_workroom_decision_locked');
        $this->postJson($this->decisions(self::CLIENT, "/{$uuid}/decline"))->assertStatus(409);
        $this->as($this->sara);
        $this->putJson($this->decisions(self::FREELANCER, "/{$uuid}"), ['title' => 'Changed my mind'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_workroom_decision_locked');
        $this->deleteJson($this->decisions(self::FREELANCER, "/{$uuid}"))->assertStatus(409);
        $row = FreelanceWorkroomDecision::query()->firstOrFail();
        $this->assertSame('declined', $row->status);
        $this->assertSame('Two languages', $row->title);
    }

    public function test_only_the_writer_changes_or_withdraws_a_waiting_proposal_and_only_the_other_side_answers_it(): void
    {
        $this->active();
        $uuid = $this->freelancerDecision();

        // The writer can not answer its own proposal.
        $this->postJson($this->decisions(self::FREELANCER, "/{$uuid}/agree"))->assertForbidden()->assertJsonPath('error_code', 'freelance_workroom_decision_forbidden');
        $this->postJson($this->decisions(self::FREELANCER, "/{$uuid}/decline"))->assertForbidden();
        // The other side can not change or take it back.
        $this->as($this->owner);
        $this->putJson($this->decisions(self::CLIENT, "/{$uuid}"), ['title' => 'Mine now'])->assertForbidden()->assertJsonPath('error_code', 'freelance_workroom_decision_forbidden');
        $this->deleteJson($this->decisions(self::CLIENT, "/{$uuid}"))->assertForbidden();
        $this->assertSame('Two languages', FreelanceWorkroomDecision::query()->firstOrFail()->title);

        // The writer changes it while it waits; nobody is told again.
        $this->as($this->sara);
        $this->putJson($this->decisions(self::FREELANCER, "/{$uuid}"), ['title' => 'Arabic and English', 'body' => 'Arabic first, English second.'])->assertOk()
            ->assertJsonPath('data.decision.title', 'Arabic and English')->assertJsonPath('data.decision.body', 'Arabic first, English second.')->assertJsonPath('data.decision.status', 'proposed');
        $this->assertSame(1, $this->told($this->owner, 'freelance.workroom.decision_proposed'));

        // ...and takes it back: it is gone for both sides, and kept soft-deleted.
        $this->deleteJson($this->decisions(self::FREELANCER, "/{$uuid}"))->assertOk()->assertJsonPath('data.summary.total', 0);
        $this->getJson($this->decisions(self::FREELANCER, "/{$uuid}"))->assertNotFound();
        $this->as($this->owner);
        $this->getJson($this->decisions(self::CLIENT))->assertOk()->assertJsonCount(0, 'data.decisions')->assertJsonPath('data.summary.waiting_for_me', 0);
        $this->postJson($this->decisions(self::CLIENT, "/{$uuid}/agree"))->assertNotFound();
        $this->assertSame(1, FreelanceWorkroomDecision::withTrashed()->count());
        $this->assertSame(0, FreelanceWorkroomDecision::query()->count());
    }

    public function test_an_agreed_decision_is_replaced_by_a_new_one_that_supersedes_it(): void
    {
        $this->active();
        $old = $this->clientDecision(['title' => 'Use the brand blue']);
        $this->as($this->sara);
        $this->postJson($this->decisions(self::FREELANCER, "/{$old}/agree"))->assertOk();
        $declined = $this->freelancerDecision(['title' => 'Use green']);
        $this->as($this->owner);
        $this->postJson($this->decisions(self::CLIENT, "/{$declined}/decline"))->assertOk();
        $waiting = $this->clientDecision(['title' => 'Use dark blue']);

        // Only an agreed decision of this project can be replaced.
        $this->as($this->sara);
        $bad = ['title' => 'Use teal', 'body' => 'Teal it is.'];
        $this->postJson($this->decisions(self::FREELANCER), $bad + ['supersedes' => (string) Str::uuid()])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_workroom_decision_supersede_invalid');
        $this->postJson($this->decisions(self::FREELANCER), $bad + ['supersedes' => $waiting])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_workroom_decision_supersede_invalid');
        $this->postJson($this->decisions(self::FREELANCER), $bad + ['supersedes' => $declined])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_workroom_decision_supersede_invalid');
        $this->postJson($this->decisions(self::FREELANCER), $bad + ['supersedes' => 'not-a-uuid'])->assertUnprocessable();
        $other = FreelanceProject::query()->create([
            'client_id' => $this->owner->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $this->project->freelance_specialty_id, 'title' => 'A second site',
            'description' => 'Another small site for a clinic.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
        $foreign = FreelanceWorkroomDecision::query()->create([
            'project_id' => $other->id, 'agreement_id' => FreelanceAgreement::query()->firstOrFail()->id, 'title' => 'Not ours', 'body' => 'Another project.', 'status' => 'agreed',
            'proposed_by_side' => 'client', 'proposed_by_id' => $this->owner->id,
        ]);
        $this->postJson($this->decisions(self::FREELANCER), $bad + ['supersedes' => $foreign->uuid])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_workroom_decision_supersede_invalid');
        $this->assertSame(4, FreelanceWorkroomDecision::query()->count());

        // The new one waits, and the old one stays as it was until the new one is agreed.
        $new = (string) $this->postJson($this->decisions(self::FREELANCER), array_merge($bad, ['title' => 'Use dark navy', 'supersedes' => $old]))->assertCreated()
            ->assertJsonPath('data.decision.supersedes.uuid', $old)->assertJsonPath('data.decision.supersedes.title', 'Use the brand blue')->json('data.decision.uuid');
        $this->getJson($this->decisions(self::FREELANCER, "/{$old}"))->assertOk()->assertJsonPath('data.decision.status', 'agreed')->assertJsonPath('data.decision.superseded_by', null)
            ->assertJsonPath('data.decision.can.decision_supersede', true);

        $this->as($this->owner);
        $this->postJson($this->decisions(self::CLIENT, "/{$new}/agree"))->assertOk()->assertJsonPath('data.decision.status', 'agreed')
            ->assertJsonPath('data.summary.agreed', 1)->assertJsonPath('data.summary.superseded', 1);
        $this->getJson($this->decisions(self::CLIENT, "/{$old}"))->assertOk()->assertJsonPath('data.decision.status', 'superseded')->assertJsonPath('data.decision.superseded_by.uuid', $new)
            ->assertJsonPath('data.decision.superseded_by.title', 'Use dark navy')->assertJsonPath('data.decision.can.decision_supersede', false)->assertJsonPath('data.decision.can.decision_agree', false);
        $record = FreelanceProjectEvent::query()->where('type', 'workroom.decision.superseded')->firstOrFail();
        $this->assertSame($old, $record->payload['decision']);
        $this->assertSame($new, $record->payload['by']);
        // An answered decision that was replaced can not be replaced twice.
        $this->as($this->sara);
        $this->postJson($this->decisions(self::FREELANCER), $bad + ['supersedes' => $old])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_workroom_decision_supersede_invalid');
    }

    public function test_the_text_of_a_decision_is_cleaned_and_checked(): void
    {
        $parts = $this->active();
        $this->as($this->owner);
        $url = $this->decisions(self::CLIENT);

        $this->postJson($url, ['title' => 'ab', 'body' => 'Some text'])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_workroom_title_required');
        $this->postJson($url, ['title' => 'A good title', 'body' => '<i></i>'])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_workroom_decision_body_required');
        $this->postJson($url, ['title' => 'A good title'])->assertUnprocessable();
        $this->postJson($url, ['body' => 'Some text'])->assertUnprocessable();
        $this->postJson($url, ['title' => str_repeat('a', 161), 'body' => 'Some text'])->assertUnprocessable();
        $this->postJson($url, ['title' => 'A good title', 'body' => str_repeat('a', 2001)])->assertUnprocessable();
        $this->postJson($url, ['title' => 'A good title', 'body' => 'Some text', 'milestone' => 'not-a-uuid'])->assertUnprocessable();
        $this->postJson($url, ['title' => 'A good title', 'body' => 'Some text', 'milestone' => (string) Str::uuid()])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_workroom_milestone_invalid');
        $this->assertSame(0, FreelanceWorkroomDecision::query()->count());

        $uuid = (string) $this->postJson($url, ['title' => '  <b>Blue</b> logo  ', 'body' => "<script>alert(1)</script> Use the blue \n of the guide"])->assertCreated()
            ->assertJsonPath('data.decision.title', 'Blue logo')->json('data.decision.uuid');
        $row = FreelanceWorkroomDecision::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertStringNotContainsString('<script', $row->body);
        $this->assertStringNotContainsString('<b>', $row->title);

        // Changing: a part of this agreement, or none; an empty text is refused and nothing changes.
        $this->putJson($this->decisions(self::CLIENT, "/{$uuid}"), ['milestone' => $parts[1]])->assertOk()->assertJsonPath('data.decision.milestone.title', 'Build');
        $this->putJson($this->decisions(self::CLIENT, "/{$uuid}"), ['milestone' => null])->assertOk()->assertJsonPath('data.decision.milestone', null);
        $this->putJson($this->decisions(self::CLIENT, "/{$uuid}"), ['milestone' => (string) Str::uuid()])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_workroom_milestone_invalid');
        $this->putJson($this->decisions(self::CLIENT, "/{$uuid}"), ['body' => '<b></b>'])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_workroom_decision_body_required');
        $this->putJson($this->decisions(self::CLIENT, "/{$uuid}"), ['title' => 'x'])->assertUnprocessable();
        $this->assertSame('Blue logo', $row->refresh()->title);

        // The answer's note is cleaned and bounded too.
        $this->as($this->sara);
        $this->postJson($this->decisions(self::FREELANCER, "/{$uuid}/agree"), ['note' => str_repeat('a', 301)])->assertUnprocessable();
        $this->postJson($this->decisions(self::FREELANCER, "/{$uuid}/agree"), ['note' => '  <b>Fine</b> by me '])->assertOk()->assertJsonPath('data.decision.answer_note', 'Fine by me');
    }

    public function test_the_list_filters_pages_and_shows_what_waits_first(): void
    {
        $parts = $this->active();
        $this->clientDecision(['title' => 'First from the client']);
        $d2 = $this->freelancerDecision(['title' => 'Second from Sara']);
        $this->as($this->owner);
        $this->postJson($this->decisions(self::CLIENT, "/{$d2}/agree"))->assertOk();
        $d3 = $this->freelancerDecision(['title' => 'Third from Sara', 'milestone' => $parts[1]]);
        $this->as($this->owner);
        $this->postJson($this->decisions(self::CLIENT, "/{$d3}/decline"))->assertOk();
        $this->freelancerDecision(['title' => 'Fourth from Sara']);

        // What waits first (newest first), then the answered ones, newest first.
        $this->as($this->sara);
        $titles = fn (string $q = '') => $this->getJson($this->decisions(self::FREELANCER).$q)->assertOk()->json('data.decisions.*.title');
        $this->assertSame(['Fourth from Sara', 'First from the client', 'Third from Sara', 'Second from Sara'], $titles());
        $this->assertSame(['First from the client'], $titles('?waiting=1'));
        $this->assertSame(['Fourth from Sara', 'Third from Sara', 'Second from Sara'], $titles('?mine=1'));
        $this->assertSame(['Third from Sara'], $titles('?status=declined'));
        $this->assertSame(['Second from Sara'], $titles('?status=agreed'));
        $this->assertSame(['Third from Sara'], $titles('?milestone='.$parts[1]));
        $this->assertSame(['Fourth from Sara', 'First from the client'], $titles('?status=proposed'));
        $this->getJson($this->decisions(self::FREELANCER).'?status=bogus')->assertUnprocessable();
        $this->getJson($this->decisions(self::FREELANCER).'?per_page=500')->assertUnprocessable();
        $this->getJson($this->decisions(self::FREELANCER).'?per_page=2')->assertOk()->assertJsonCount(2, 'data.decisions')->assertJsonPath('pagination.total', 4)->assertJsonPath('pagination.has_more_pages', true);
        $this->getJson($this->decisions(self::FREELANCER))->assertJsonPath('data.summary.total', 4)->assertJsonPath('data.summary.proposed', 2)->assertJsonPath('data.summary.agreed', 1)
            ->assertJsonPath('data.summary.declined', 1)->assertJsonPath('data.summary.superseded', 0)->assertJsonPath('data.summary.waiting_for_me', 1);

        // The client's view of the same list.
        $this->as($this->owner);
        $this->assertSame(['Fourth from Sara'], $this->getJson($this->decisions(self::CLIENT).'?waiting=1')->json('data.decisions.*.title'));
        $this->assertSame(['First from the client'], $this->getJson($this->decisions(self::CLIENT).'?mine=1')->json('data.decisions.*.title'));
        $this->getJson($this->decisions(self::CLIENT))->assertJsonPath('data.summary.waiting_for_me', 1);
    }

    public function test_the_decisions_exist_while_the_agreement_is_active_and_are_only_read_after_it(): void
    {
        // Not an agreement yet: nothing to open.
        $this->as($this->owner);
        $this->getJson($this->decisions(self::CLIENT))->assertNotFound();
        $this->getJson($this->timeline(self::CLIENT))->assertNotFound();
        $this->as($this->sara);
        $this->getJson($this->decisions(self::FREELANCER))->assertNotFound();
        $this->getJson($this->timeline(self::FREELANCER))->assertNotFound();

        $this->active();
        $waiting = $this->freelancerDecision(['title' => 'Still waiting']);
        $agreed = $this->clientDecision(['title' => 'Already agreed']);
        $this->as($this->sara);
        $this->postJson($this->decisions(self::FREELANCER, "/{$agreed}/agree"))->assertOk();
        FreelanceAgreement::query()->update(['status' => FreelanceAgreement::COMPLETED]);

        foreach ([[self::CLIENT, $this->owner], [self::FREELANCER, $this->sara]] as [$prefix, $who]) {
            $this->as($who);
            $this->getJson($this->decisions($prefix))->assertOk()->assertJsonCount(2, 'data.decisions')->assertJsonPath('data.read_only', true)->assertJsonPath('data.can.decision_create', false)
                ->assertJsonPath('data.decisions.0.can.decision_edit', false)->assertJsonPath('data.decisions.0.can.decision_withdraw', false)->assertJsonPath('data.decisions.0.can.decision_agree', false)
                ->assertJsonPath('data.decisions.0.can.decision_decline', false)->assertJsonPath('data.decisions.1.can.decision_supersede', false);
            $this->getJson($this->decisions($prefix, "/{$agreed}"))->assertOk();
            $this->getJson($this->timeline($prefix))->assertOk();

            $this->postJson($this->decisions($prefix), ['title' => 'Too late', 'body' => 'Too late'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_workroom_not_active');
            $this->putJson($this->decisions($prefix, "/{$waiting}"), ['title' => 'Too late now'])->assertStatus(409);
            $this->postJson($this->decisions($prefix, "/{$waiting}/agree"))->assertStatus(409)->assertJsonPath('error_code', 'freelance_workroom_not_active');
            $this->postJson($this->decisions($prefix, "/{$waiting}/decline"))->assertStatus(409);
            $this->deleteJson($this->decisions($prefix, "/{$waiting}"))->assertStatus(409);
        }
        $this->assertSame(2, FreelanceWorkroomDecision::query()->count());
        $this->assertSame('proposed', FreelanceWorkroomDecision::query()->where('uuid', $waiting)->firstOrFail()->status);
    }

    public function test_a_stranger_reaches_no_decision_and_no_timeline(): void
    {
        $this->active();
        $uuid = $this->clientDecision();

        // Another freelancer.
        $this->as($this->lina);
        $this->getJson($this->decisions(self::FREELANCER))->assertNotFound();
        $this->getJson($this->decisions(self::FREELANCER, "/{$uuid}"))->assertNotFound();
        $this->getJson($this->timeline(self::FREELANCER))->assertNotFound();
        $this->postJson($this->decisions(self::FREELANCER), ['title' => 'Hello there', 'body' => 'Hello'])->assertNotFound();
        $this->postJson($this->decisions(self::FREELANCER, "/{$uuid}/agree"))->assertNotFound();
        $this->deleteJson($this->decisions(self::FREELANCER, "/{$uuid}"))->assertNotFound();

        // A client who is neither the owner nor on the team.
        $this->as($this->huda);
        $this->getJson($this->decisions(self::CLIENT))->assertNotFound();
        $this->getJson($this->decisions(self::CLIENT, "/{$uuid}"))->assertNotFound();
        $this->getJson($this->timeline(self::CLIENT))->assertNotFound();
        $this->postJson($this->decisions(self::CLIENT), ['title' => 'Hello there', 'body' => 'Hello'])->assertNotFound();
        $this->putJson($this->decisions(self::CLIENT, "/{$uuid}"), ['title' => 'Hello there'])->assertNotFound();

        $this->assertSame(1, FreelanceWorkroomDecision::query()->count());
        $this->assertSame('proposed', FreelanceWorkroomDecision::query()->firstOrFail()->status);
    }

    public function test_a_member_of_the_clients_team_reads_and_a_manager_works_on_the_decisions(): void
    {
        $this->active();
        $this->join($this->omar, 'manager');
        $this->join($this->huda, 'collaborator');
        $uuid = $this->freelancerDecision();
        // The owner and the manager (who can answer) are told; the collaborator (who only follows) is not.
        $this->assertSame(1, $this->told($this->owner, 'freelance.workroom.decision_proposed'));
        $this->assertSame(1, $this->told($this->omar, 'freelance.workroom.decision_proposed'));
        $this->assertSame(0, $this->told($this->huda, 'freelance.workroom.decision_proposed'));

        // The collaborator reads, with every button off, and can not write.
        $this->as($this->huda);
        $this->getJson($this->decisions(self::CLIENT))->assertOk()->assertJsonCount(1, 'data.decisions')->assertJsonPath('data.can.decision_create', false)
            ->assertJsonPath('data.decisions.0.can.decision_agree', false)->assertJsonPath('data.decisions.0.can.decision_decline', false);
        $this->getJson($this->decisions(self::CLIENT, "/{$uuid}"))->assertOk();
        $this->getJson($this->timeline(self::CLIENT))->assertOk();
        $this->postJson($this->decisions(self::CLIENT), ['title' => 'Let me write one', 'body' => 'Text'])->assertForbidden()->assertJsonPath('error_code', 'freelance_team_forbidden');
        $this->postJson($this->decisions(self::CLIENT, "/{$uuid}/agree"))->assertForbidden()->assertJsonPath('error_code', 'freelance_team_forbidden');
        $this->postJson($this->decisions(self::CLIENT, "/{$uuid}/decline"))->assertForbidden();
        $this->assertSame('proposed', FreelanceWorkroomDecision::query()->firstOrFail()->status);

        // The manager answers as the owner, and the record says who.
        $this->as($this->omar);
        $this->getJson($this->decisions(self::CLIENT))->assertOk()->assertJsonPath('data.can.decision_create', true)->assertJsonPath('data.decisions.0.can.decision_agree', true);
        $this->postJson($this->decisions(self::CLIENT, "/{$uuid}/agree"), ['note' => 'Agreed by Omar'])->assertOk()->assertJsonPath('data.decision.status', 'agreed');
        $mine = (string) $this->postJson($this->decisions(self::CLIENT), ['title' => 'From the manager', 'body' => 'A decision of the manager.'])->assertCreated()->json('data.decision.uuid');
        $this->putJson($this->decisions(self::CLIENT, "/{$mine}"), ['title' => 'From the manager, changed'])->assertOk();

        $agreed = FreelanceProjectEvent::query()->where('type', 'workroom.decision.agreed')->firstOrFail();
        $this->assertSame('client', $agreed->actor_type);
        $this->assertSame($this->owner->id, (int) $agreed->actor_id);
        $this->assertSame($this->omar->id, (int) $agreed->acted_by_user_id);
        $this->assertSame($this->omar->id, (int) FreelanceProjectEvent::query()->where('type', 'workroom.decision.proposed')->where('actor_type', 'client')->firstOrFail()->acted_by_user_id);
        // Sara hears about the answer and the new proposal (not about the change of a waiting one).
        $this->assertSame(1, $this->told($this->sara, 'freelance.workroom.decision_agreed'));
        $this->assertSame(1, $this->told($this->sara, 'freelance.workroom.decision_proposed'));

        // The manager's proposal is the client side's: Sara answers it, and the owner and the manager are told (the collaborator is not).
        $this->as($this->sara);
        $this->postJson($this->decisions(self::FREELANCER, "/{$mine}/agree"))->assertOk();
        $this->assertSame(1, $this->told($this->omar, 'freelance.workroom.decision_agreed'));
        $this->assertSame(1, $this->told($this->owner, 'freelance.workroom.decision_agreed'));
        $this->assertSame(0, $this->told($this->huda, 'freelance.workroom.decision_agreed'));
    }

    public function test_every_step_is_on_the_project_record_without_its_text_and_tells_the_live_screens_once(): void
    {
        $this->active();
        Event::fake([FreelanceWorkroomChanged::class]);

        $a = $this->clientDecision(['title' => 'Decision A', 'body' => 'Secret body A']);
        $this->putJson($this->decisions(self::CLIENT, "/{$a}"), ['title' => 'Decision A2'])->assertOk();
        $this->as($this->sara);
        $this->postJson($this->decisions(self::FREELANCER, "/{$a}/agree"), ['note' => 'Secret note'])->assertOk();
        $b = (string) $this->postJson($this->decisions(self::FREELANCER), ['title' => 'Decision B', 'body' => 'Secret body B', 'supersedes' => $a])->assertCreated()->json('data.decision.uuid');
        $this->as($this->owner);
        $this->postJson($this->decisions(self::CLIENT, "/{$b}/decline"), ['note' => 'Secret decline note'])->assertOk();
        $c = $this->clientDecision(['title' => 'Decision C', 'body' => 'Secret body C']);
        $this->deleteJson($this->decisions(self::CLIENT, "/{$c}"))->assertOk();

        // They are the Workroom's record, not the project's own history the client's page lists.
        $types = collect($this->getJson(self::CLIENT."/{$this->project->uuid}")->assertOk()->json('data.timeline'))->pluck('type');
        $this->assertNotEmpty($types);
        $this->assertTrue($types->every(fn ($t) => ! str_starts_with((string) $t, 'workroom.')));

        $rows = FreelanceProjectEvent::query()->where('project_id', $this->project->id)->where('type', 'like', 'workroom.decision.%')->orderBy('id')->get();
        $this->assertSame([
            'workroom.decision.proposed', 'workroom.decision.updated', 'workroom.decision.agreed', 'workroom.decision.proposed', 'workroom.decision.declined',
            'workroom.decision.proposed', 'workroom.decision.withdrawn',
        ], $rows->pluck('type')->all());
        $this->assertSame(['client', 'client', 'freelancer', 'freelancer', 'client', 'client', 'client'], $rows->pluck('actor_type')->all());
        $this->assertSame($a, $rows[0]->payload['decision']);
        $this->assertSame('Decision A', $rows[0]->payload['title']);
        $this->assertSame(['title'], $rows[1]->payload['changed']);
        $this->assertSame($a, $rows[3]->payload['supersedes']);
        // The text of a decision and of an answer never goes on the record.
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('Secret', json_encode($row->payload));
            $this->assertSame('both', FreelanceProjectEvent::query()->findOrFail($row->id)->audience);
        }

        Event::assertDispatchedTimes(FreelanceWorkroomChanged::class, 7);
        Event::assertDispatched(FreelanceWorkroomChanged::class, fn (FreelanceWorkroomChanged $e) => $e->broadcastWith()['kind'] === 'decision' && $e->action === 'updated' && $e->uuid === $a);
        Event::assertDispatched(FreelanceWorkroomChanged::class, fn (FreelanceWorkroomChanged $e) => $e->broadcastWith()['kind'] === 'decision' && $e->action === 'declined' && $e->uuid === $b);
        Event::assertDispatched(FreelanceWorkroomChanged::class, fn (FreelanceWorkroomChanged $e) => $e->broadcastWith()['kind'] === 'decision' && $e->action === 'withdrawn' && $e->uuid === $c);
    }

    public function test_a_refused_step_tells_nobody_and_writes_nothing(): void
    {
        $this->active();
        $uuid = $this->freelancerDecision();
        Event::fake([FreelanceWorkroomChanged::class]);
        $records = FreelanceProjectEvent::query()->count();
        $told = $this->told($this->owner, 'freelance.workroom.decision_proposed');

        $this->as($this->owner);
        $this->putJson($this->decisions(self::CLIENT, "/{$uuid}"), ['title' => 'Mine'])->assertForbidden();
        $this->deleteJson($this->decisions(self::CLIENT, "/{$uuid}"))->assertForbidden();
        $this->postJson($this->decisions(self::CLIENT), ['title' => 'ab', 'body' => 'Text'])->assertUnprocessable();
        $this->postJson($this->decisions(self::CLIENT), ['title' => 'A good title', 'body' => '   '])->assertUnprocessable();
        $this->as($this->sara);
        $this->postJson($this->decisions(self::FREELANCER, "/{$uuid}/agree"))->assertForbidden();
        $this->postJson($this->decisions(self::FREELANCER, "/{$uuid}/decline"))->assertForbidden();

        Event::assertNotDispatched(FreelanceWorkroomChanged::class);
        $this->assertSame($records, FreelanceProjectEvent::query()->count());
        $this->assertSame($told, $this->told($this->owner, 'freelance.workroom.decision_proposed'));
        $this->assertSame(0, $this->told($this->sara, 'freelance.workroom.decision_agreed'));
        $this->assertSame(0, $this->told($this->sara, 'freelance.workroom.decision_declined'));
        $this->assertSame(1, FreelanceWorkroomDecision::query()->count());
    }

    public function test_the_timeline_lists_what_happened_newest_first_for_both_sides_without_any_text(): void
    {
        $this->active();
        // A minute apart, so the order of the list is the order of the steps whatever the speed of the test.
        Carbon::setTestNow(Carbon::now()->addMinute());
        $this->as($this->owner);
        $file = (string) $this->postJson(self::CLIENT."/{$this->project->uuid}/workroom/files", [
            'file' => UploadedFile::fake()->createWithContent('brief.pdf', "%PDF-1.4\n%%EOF"), 'title' => 'Project brief',
        ])->assertCreated()->json('data.file.uuid');
        Carbon::setTestNow(Carbon::now()->addMinute());
        $this->postJson(self::CLIENT."/{$this->project->uuid}/workroom/comments", ['file' => $file, 'body' => 'Top secret wording'])->assertCreated();
        Carbon::setTestNow(Carbon::now()->addMinute());
        $decision = $this->freelancerDecision(['title' => 'Blue logo', 'body' => 'Secret decision body']);
        Carbon::setTestNow(Carbon::now()->addMinute());
        $this->as($this->owner);
        $this->postJson($this->decisions(self::CLIENT, "/{$decision}/agree"), ['note' => 'Secret answer'])->assertOk();

        $this->as($this->owner);
        $client = $this->getJson($this->timeline(self::CLIENT))->assertOk();
        $this->as($this->sara);
        $freelancer = $this->getJson($this->timeline(self::FREELANCER))->assertOk();

        foreach ([$client, $freelancer] as $response) {
            $events = $response->json('data.events');
            $this->assertGreaterThan(4, count($events));
            // Newest first: the answer, the proposal, the note, the file, and then the agreement's own record (approvals).
            $this->assertSame(['decision', 'decision', 'comment', 'file'], array_slice(array_column($events, 'kind'), 0, 4));
            $this->assertSame(['agreed', 'proposed', 'created', 'uploaded'], array_slice(array_column($events, 'action'), 0, 4));
            $this->assertSame('Decision agreed: Blue logo', $events[0]['label']);
            $this->assertSame('Decision proposed: Blue logo', $events[1]['label']);
            $this->assertSame('File added: Project brief', $events[3]['label']);
            $this->assertSame('workroom', $events[0]['source']);
            $this->assertSame(['type' => 'decision', 'uuid' => $decision, 'title' => 'Blue logo'], $events[0]['subject']);
            $this->assertSame('file', $events[2]['subject']['on']['type']);
            $this->assertSame($file, $events[2]['subject']['on']['uuid']);
            $sources = array_unique(array_column($events, 'source'));
            sort($sources);
            $this->assertSame(['approval', 'workroom'], $sources);
            $this->assertContains('agreement', array_column($events, 'kind'));
            $this->assertNotNull($events[0]['at']);
            // Nothing the sides wrote - no note, no decision text, no answer - and no amount.
            $json = (string) $response->getContent();
            foreach (['Top secret', 'Secret decision body', 'Secret answer'] as $secret) {
                $this->assertStringNotContainsString($secret, $json);
            }
        }

        // `mine` is told from each side's point of view.
        $this->assertSame(['client', true], [$client->json('data.events.0.actor'), $client->json('data.events.0.mine')]);
        $this->assertSame(['client', false], [$freelancer->json('data.events.0.actor'), $freelancer->json('data.events.0.mine')]);
        $this->assertSame(['freelancer', false], [$client->json('data.events.1.actor'), $client->json('data.events.1.mine')]);
    }

    public function test_the_timeline_filters_by_kind_and_by_who_and_pages(): void
    {
        $this->active();
        $d1 = $this->clientDecision(['title' => 'From the client']);
        $d2 = $this->freelancerDecision(['title' => 'From Sara']);
        $this->as($this->owner);
        $this->postJson($this->decisions(self::CLIENT, "/{$d2}/agree"))->assertOk();
        $this->assertNotNull($d1);

        $this->as($this->sara);
        $kinds = fn (string $q) => $this->getJson($this->timeline(self::FREELANCER, $q))->assertOk()->json('data.events.*.kind');
        $this->assertSame(['decision', 'decision', 'decision'], $kinds('?kind=decision'));
        $this->assertSame([], $kinds('?kind=task'));
        $this->assertContains('agreement', $kinds('?kind=agreement'));
        $this->assertNotContains('decision', $kinds('?kind=agreement'));
        // Only what this side did: its own proposal (not the client's proposal or the client's answer).
        $mine = $this->getJson($this->timeline(self::FREELANCER, '?kind=decision&mine=1'))->assertOk();
        $this->assertSame(['From Sara'], $mine->json('data.events.*.subject.title'));
        $this->assertSame([true], array_values(array_unique($mine->json('data.events.*.mine'))));

        $this->getJson($this->timeline(self::FREELANCER, '?per_page=2'))->assertOk()->assertJsonCount(2, 'data.events')->assertJsonPath('pagination.has_more_pages', true)
            ->assertJsonPath('pagination.per_page', 2);
        $first = $this->getJson($this->timeline(self::FREELANCER, '?per_page=2&page=1'))->json('data.events.*.key');
        $second = $this->getJson($this->timeline(self::FREELANCER, '?per_page=2&page=2'))->json('data.events.*.key');
        $this->assertCount(2, $first);
        $this->assertSame([], array_values(array_intersect($first, $second)));
        $this->getJson($this->timeline(self::FREELANCER, '?per_page=0'))->assertUnprocessable();
        $this->getJson($this->timeline(self::FREELANCER, '?per_page=101'))->assertUnprocessable();
        $this->getJson($this->timeline(self::FREELANCER, '?kind=a1'))->assertUnprocessable();
    }

    public function test_each_side_sees_only_the_events_meant_for_it(): void
    {
        $this->active();
        $row = fn (string $title, string $audience) => FreelanceProjectEvent::query()->create([
            'project_id' => $this->project->id, 'type' => 'workroom.decision.proposed', 'actor_type' => 'client', 'actor_id' => $this->owner->id,
            'payload' => ['decision' => (string) Str::uuid(), 'title' => $title], 'audience' => $audience,
        ]);
        $row('For both', 'both');
        $row('Client only', 'client');
        $row('Freelancer only', 'freelancer');
        $row('Team only', 'team');

        $this->as($this->owner);
        $titles = $this->getJson($this->timeline(self::CLIENT).'?kind=decision')->assertOk()->json('data.events.*.subject.title');
        sort($titles);
        $this->assertSame(['Client only', 'For both'], $titles);
        $this->as($this->sara);
        $titles = $this->getJson($this->timeline(self::FREELANCER).'?kind=decision')->assertOk()->json('data.events.*.subject.title');
        sort($titles);
        $this->assertSame(['For both', 'Freelancer only'], $titles);

        // The project's own history on the client's page follows the same rule.
        $make = fn (string $type, string $audience) => FreelanceProjectEvent::query()->create([
            'project_id' => $this->project->id, 'type' => $type, 'actor_type' => 'freelancer', 'actor_id' => $this->sara->id, 'payload' => [], 'audience' => $audience,
        ]);
        $make('test.for_everyone', 'both');
        $make('test.for_the_client', 'client');
        $make('test.for_the_freelancer', 'freelancer');
        $make('test.for_the_team', 'team');
        $this->as($this->owner);
        $types = collect($this->getJson(self::CLIENT."/{$this->project->uuid}")->assertOk()->json('data.timeline'))->pluck('type')->all();
        $this->assertContains('test.for_everyone', $types);
        $this->assertContains('test.for_the_client', $types);
        $this->assertNotContains('test.for_the_freelancer', $types);
        $this->assertNotContains('test.for_the_team', $types);
    }

    public function test_every_step_of_the_workroom_has_a_label_in_both_languages(): void
    {
        $types = [
            'workroom.task.created', 'workroom.task.updated', 'workroom.task.status', 'workroom.task.completed', 'workroom.task.deleted',
            'workroom.file.uploaded', 'workroom.file.version', 'workroom.file.updated', 'workroom.file.deleted',
            'workroom.comment.created', 'workroom.comment.updated', 'workroom.comment.deleted',
            'workroom.decision.proposed', 'workroom.decision.updated', 'workroom.decision.agreed', 'workroom.decision.declined', 'workroom.decision.superseded', 'workroom.decision.withdrawn',
        ];
        foreach ($types as $type) {
            $key = 'freelance.timeline.'.str_replace('.', '_', $type);
            foreach (['ar', 'en'] as $locale) {
                $this->assertTrue(Lang::has($key, $locale, false), "$key is missing in $locale");
            }
        }
        foreach (['decision_proposed', 'decision_agreed', 'decision_declined'] as $event) {
            foreach (['client', 'freelancer'] as $side) {
                foreach (['title', 'body'] as $part) {
                    foreach (['ar', 'en'] as $locale) {
                        $this->assertTrue(Lang::has("notifications.freelance_{$side}_workroom_{$event}_{$part}", $locale, false), "$side $event $part is missing in $locale");
                    }
                }
            }
        }
    }
}
