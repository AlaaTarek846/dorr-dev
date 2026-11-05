<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Projects\ProjectStateMachine;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FS-102: "hire again" - a finished project asked for again from the same freelancer. A private draft remembers who it is for and
 * what the last parts were; nothing of the old agreement comes along; the freelancer is invited by himself when the project is published.
 */
class FreelanceHireAgainTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '/api/mobile/v1/freelance/projects';

    private Country $saudi;

    private User $client;

    private Freelancer $sara;

    private FreelanceProject $old;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat', 'wallet_payment'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->old = FreelanceProject::query()->create([
            'client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $web->id, 'title' => 'A booking website',
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Closed, 'published_at' => now()->subDays(40),
        ]);
    }

    private function asClient(?User $u = null): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(($u ?? $this->client)->refresh(), [], 'user_api');
    }

    /** The old project had Sara's agreement, finished, in two parts. */
    private function finishedWithSara(string $status = FreelanceAgreement::COMPLETED): void
    {
        $offer = FreelanceOffer::query()->create([
            'project_id' => $this->old->id, 'freelancer_id' => $this->sara->id, 'price_minor' => 350000, 'currency_code' => 'SAR', 'duration_days' => 21, 'details' => 'I will build it in three steps with a demo each week.',
            'status' => 'accepted',
        ]);
        $a = FreelanceAgreement::query()->create([
            'project_id' => $this->old->id, 'offer_id' => $offer->id, 'client_id' => $this->client->id, 'freelancer_id' => $this->sara->id, 'scope' => 'The site', 'price_minor' => 350000,
            'currency_code' => 'SAR', 'duration_days' => 21, 'policy' => [], 'money' => [], 'status' => $status,
        ]);
        foreach ([[1, 'Design', 100000, 7], [2, 'Build', 250000, 14]] as [$pos, $title, $amount, $days]) {
            FreelanceMilestone::query()->create(['agreement_id' => $a->id, 'project_id' => $this->old->id, 'position' => $pos, 'title' => $title, 'amount_minor' => $amount, 'duration_days' => $days, 'status' => 'approved']);
        }
    }

    public function test_a_finished_project_is_asked_again_from_the_same_freelancer(): void
    {
        $this->finishedWithSara();
        $this->asClient();
        $this->getJson(self::CLIENT."/{$this->old->uuid}")->assertOk()->assertJsonPath('data.can.hire_again', true);

        $res = $this->postJson(self::CLIENT."/{$this->old->uuid}/hire-again")->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.visibility', 'private')
            ->assertJsonPath('data.hire_again.freelancer.uuid', $this->sara->uuid)->assertJsonPath('data.hire_again.from_project', $this->old->uuid)
            ->assertJsonPath('data.title', 'A booking website')
            ->assertJsonCount(2, 'data.hire_again.milestones')->assertJsonPath('data.hire_again.milestones.1.amount_minor', 250000);
        // A copy, with no agreement, offer or invitation of the old one.
        $new = FreelanceProject::query()->where('uuid', $res->json('data.uuid'))->firstOrFail();
        $this->assertNotSame($this->old->id, $new->id);
        $this->assertSame(0, FreelanceAgreement::query()->where('project_id', $new->id)->count());
        $this->assertSame(0, $new->invitations()->count());
    }

    public function test_only_the_owner_of_a_finished_project_with_a_freelancer_can(): void
    {
        $this->asClient();
        // Nothing was agreed on it.
        $this->postJson(self::CLIENT."/{$this->old->uuid}/hire-again")->assertStatus(422)->assertJsonPath('error_code', 'freelance_hire_again_unavailable');
        $this->getJson(self::CLIENT."/{$this->old->uuid}")->assertJsonPath('data.can.hire_again', false);

        // An agreement that did not end well is no reason to hire again.
        $this->finishedWithSara(FreelanceAgreement::CANCELLED);
        $this->postJson(self::CLIENT."/{$this->old->uuid}/hire-again")->assertStatus(422);

        FreelanceAgreement::query()->update(['status' => FreelanceAgreement::COMPLETED]);
        $other = User::query()->create(['name' => 'Omar', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->asClient($other);
        $this->postJson(self::CLIENT."/{$this->old->uuid}/hire-again")->assertNotFound();

        // A freelancer no longer verified can not be hired again.
        $this->asClient();
        $this->sara->forceFill(['status' => 'suspended'])->save();
        $this->postJson(self::CLIENT."/{$this->old->uuid}/hire-again")->assertStatus(422);
    }

    public function test_the_freelancer_is_invited_when_the_new_project_is_published(): void
    {
        $this->finishedWithSara();
        $this->asClient();
        $uuid = $this->postJson(self::CLIENT."/{$this->old->uuid}/hire-again")->assertCreated()->json('data.uuid');
        $new = FreelanceProject::query()->where('uuid', $uuid)->firstOrFail();

        $machine = app(ProjectStateMachine::class);
        $machine->move($new, FreelanceProjectStatus::PendingReview, 'project.submitted', 'client', $this->client->id);
        $this->assertSame(0, $new->invitations()->count());
        $machine->move($new->fresh(), FreelanceProjectStatus::Published, 'project.published', 'admin', null, ['published_at' => now(), 'offers_deadline_at' => now()->addDays(14)]);

        $this->assertSame(1, $new->invitations()->where('freelancer_id', $this->sara->id)->count());
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.invitation.received')->count());
    }
}
