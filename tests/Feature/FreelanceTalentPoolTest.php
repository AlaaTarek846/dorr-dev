<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\FreelanceTalentListItem;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Offers\TalentPool;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FS-103: a client's own lists of freelancers - made, filled from a profile, noted, reminded on request only, used to invite to a private
 * project - and a freelancer who sees only how many lists he is in.
 */
class FreelanceTalentPoolTest extends TestCase
{
    use RefreshDatabase;

    private const LISTS = '/api/mobile/v1/freelance/talent-lists';

    private Country $saudi;

    private FreelanceSpecialty $web;

    private User $client;

    private User $other;

    private Freelancer $sara;

    private Freelancer $lina;

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
        $this->web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $this->web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->other = User::query()->create(['name' => 'Omar', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->lina = Freelancer::query()->create(['name' => 'Lina', 'phone' => '+966500000092', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function asClient(?User $u = null): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(($u ?? $this->client)->refresh(), [], 'user_api');
    }

    private function makeList(string $name = 'Web people', ?int $specialty = null): string
    {
        return $this->postJson(self::LISTS, ['name' => $name, 'specialty_id' => $specialty])->assertCreated()->json('data.uuid');
    }

    public function test_a_client_makes_lists_by_specialty_and_only_sees_his_own(): void
    {
        $this->asClient();
        $web = $this->makeList('Web people', $this->web->id);
        $this->makeList('Anything');
        $this->getJson(self::LISTS)->assertOk()->assertJsonCount(2, 'data.lists')->assertJsonPath('data.max_lists', TalentPool::MAX_LISTS);
        $this->getJson(self::LISTS.'?specialty_id='.$this->web->id)->assertJsonCount(1, 'data.lists')->assertJsonPath('data.lists.0.specialty.name', 'Web');

        $this->putJson(self::LISTS."/{$web}", ['name' => 'Websites'])->assertOk()->assertJsonPath('data.name', 'Websites')->assertJsonPath('data.specialty', null);
        $this->postJson(self::LISTS, ['name' => ''])->assertUnprocessable();

        $this->asClient($this->other);
        $this->getJson(self::LISTS)->assertJsonCount(0, 'data.lists');
        $this->getJson(self::LISTS."/{$web}")->assertNotFound();
        $this->deleteJson(self::LISTS."/{$web}")->assertNotFound();

        $this->asClient();
        $this->deleteJson(self::LISTS."/{$web}")->assertOk();
        $this->getJson(self::LISTS)->assertJsonCount(1, 'data.lists');
    }

    public function test_the_number_of_lists_is_limited(): void
    {
        $this->asClient();
        for ($i = 0; $i < TalentPool::MAX_LISTS; $i++) {
            $this->postJson(self::LISTS, ['name' => 'L'.$i])->assertCreated();
        }
        $this->postJson(self::LISTS, ['name' => 'One more'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_talent_lists_limit');
    }

    public function test_a_freelancer_is_saved_noted_and_taken_out(): void
    {
        $this->asClient();
        $list = $this->makeList();
        $this->postJson(self::LISTS."/{$list}/members", ['freelancer' => $this->sara->uuid, 'note' => 'Fast and tidy'])->assertOk()
            ->assertJsonPath('data.count', 1)->assertJsonPath('data.members.0.uuid', $this->sara->uuid)->assertJsonPath('data.members.0.note', 'Fast and tidy')
            ->assertJsonPath('data.members.0.worked_together', false);
        // Twice is the same person; no phone or email is ever shown.
        $res = $this->postJson(self::LISTS."/{$list}/members", ['freelancer' => $this->sara->uuid])->assertOk()->assertJsonPath('data.count', 1)->assertJsonPath('data.members.0.note', 'Fast and tidy');
        $this->assertArrayNotHasKey('phone', $res->json('data.members.0'));

        $this->getJson(self::LISTS."/containing/{$this->sara->uuid}")->assertOk()->assertJsonPath('data.lists', [$list]);
        $this->getJson(self::LISTS."/containing/{$this->lina->uuid}")->assertJsonPath('data.lists', []);

        $this->putJson(self::LISTS."/{$list}/members/{$this->sara->uuid}", ['note' => 'Great'])->assertOk()->assertJsonPath('data.members.0.note', 'Great');
        $this->deleteJson(self::LISTS."/{$list}/members/{$this->sara->uuid}")->assertOk()->assertJsonPath('data.count', 0);

        // Someone not verified (or unknown) can not be kept; nor in another client's list.
        $this->lina->forceFill(['status' => 'suspended'])->save();
        $this->postJson(self::LISTS."/{$list}/members", ['freelancer' => $this->lina->uuid])->assertNotFound();
        $this->asClient($this->other);
        $this->postJson(self::LISTS."/{$list}/members", ['freelancer' => $this->sara->uuid])->assertNotFound();
    }

    public function test_a_returning_freelancer_is_marked_as_worked_together(): void
    {
        $project = FreelanceProject::query()->create([
            'client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $this->web->id, 'title' => 'Old job', 'description' => 'A small site for a clinic with booking.',
            'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR', 'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Closed, 'published_at' => now()->subDays(40),
        ]);
        $offer = FreelanceOffer::query()->create(['project_id' => $project->id, 'freelancer_id' => $this->sara->id, 'price_minor' => 350000, 'currency_code' => 'SAR', 'duration_days' => 21, 'details' => 'Three steps with a demo each week of work.', 'status' => 'accepted']);
        FreelanceAgreement::query()->create([
            'project_id' => $project->id, 'offer_id' => $offer->id, 'client_id' => $this->client->id, 'freelancer_id' => $this->sara->id, 'scope' => 'The site', 'price_minor' => 350000,
            'currency_code' => 'SAR', 'duration_days' => 21, 'policy' => [], 'money' => [], 'status' => FreelanceAgreement::COMPLETED,
        ]);

        $this->asClient();
        $list = $this->makeList();
        $this->postJson(self::LISTS."/{$list}/members", ['freelancer' => $this->sara->uuid]);
        $this->postJson(self::LISTS."/{$list}/members", ['freelancer' => $this->lina->uuid])->assertOk()
            ->assertJsonPath('data.members.0.uuid', $this->lina->uuid)->assertJsonPath('data.members.0.worked_together', false)->assertJsonPath('data.members.1.worked_together', true);
    }

    public function test_a_list_invites_its_people_to_a_private_project(): void
    {
        $project = FreelanceProject::query()->create([
            'client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $this->web->id, 'title' => 'A booking website', 'description' => 'A small site where patients pick a doctor and a time.',
            'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR', 'duration_days' => 30, 'visibility' => 'private', 'status' => FreelanceProjectStatus::Published,
            'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
        $this->asClient();
        $list = $this->makeList();
        foreach ([$this->sara, $this->lina] as $f) {
            $this->postJson(self::LISTS."/{$list}/members", ['freelancer' => $f->uuid])->assertOk();
        }

        // Only some of them first, then the rest: the second call skips whoever was already asked.
        $this->postJson(self::LISTS."/{$list}/invite", ['project' => $project->uuid, 'freelancers' => [$this->sara->uuid]])->assertOk()->assertJsonPath('data.invited', 1);
        $res = $this->postJson(self::LISTS."/{$list}/invite", ['project' => $project->uuid])->assertOk();
        $this->assertSame(1, $res->json('data.invited'));
        $this->assertSame('already_invited', $res->json('data.skipped.0.reason'));
        $this->assertSame(2, $project->invitations()->count());

        // A project that is not his.
        $this->asClient($this->other);
        $mine = $this->postJson(self::LISTS, ['name' => 'Mine'])->json('data.uuid');
        $this->postJson(self::LISTS."/{$mine}/invite", ['project' => $project->uuid])->assertNotFound();
    }

    public function test_the_reminder_exists_only_when_the_client_asks_and_goes_to_him_alone(): void
    {
        $this->asClient();
        $list = $this->makeList();
        $this->postJson(self::LISTS."/{$list}/members", ['freelancer' => $this->sara->uuid]);
        $this->assertSame(0, app(TalentPool::class)->remindDue());
        $this->assertNull(FreelanceTalentListItem::query()->first()->remind_at);

        $this->putJson(self::LISTS."/{$list}/members/{$this->sara->uuid}", ['remind_in_days' => 7])->assertUnprocessable();
        $this->putJson(self::LISTS."/{$list}/members/{$this->sara->uuid}", ['remind_in_days' => 30])->assertOk()->assertJsonPath('data.remind_options', TalentPool::REMIND_DAYS);
        $this->assertNotNull(FreelanceTalentListItem::query()->first()->remind_at);

        // Not yet; then the day comes - once.
        $this->assertSame(0, app(TalentPool::class)->remindDue());
        Carbon::setTestNow(now()->addDays(31));
        $this->assertSame(1, app(TalentPool::class)->remindDue());
        $this->assertSame(0, app(TalentPool::class)->remindDue());
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.talent.reminder')->count());
        $this->assertSame(0, $this->sara->notifications()->count());

        // Turned off again: nothing more.
        $this->putJson(self::LISTS."/{$list}/members/{$this->sara->uuid}", ['remind_in_days' => null])->assertOk();
        Carbon::setTestNow(now()->addDays(100));
        $this->assertSame(0, app(TalentPool::class)->remindDue());
    }

    public function test_a_freelancer_only_counts_the_lists_he_is_in(): void
    {
        $this->asClient();
        $a = $this->makeList('A');
        $b = $this->makeList('B');
        $this->postJson(self::LISTS."/{$a}/members", ['freelancer' => $this->sara->uuid]);
        $this->postJson(self::LISTS."/{$b}/members", ['freelancer' => $this->sara->uuid]);
        $this->asClient($this->other);
        $c = $this->makeList('C');
        $this->postJson(self::LISTS."/{$c}/members", ['freelancer' => $this->sara->uuid]);
        $this->postJson(self::LISTS."/{$c}/members", ['freelancer' => $this->lina->uuid]);

        $pool = app(TalentPool::class);
        $this->assertSame(3, $pool->countFor($this->sara));
        $this->assertSame(1, $pool->countFor($this->lina));
        $this->assertSame(0, $pool->countFor($this->sara, now()->addDay()));

        // Deleting a list takes the person out of it.
        $this->deleteJson(self::LISTS."/{$c}")->assertOk();
        $this->assertSame(2, $pool->countFor($this->sara));
    }
}
