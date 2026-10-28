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
 * FL-404 / FL-405: one side asks for a change to an active agreement, the other answers in time; more days, a new scope, a new price
 * for a part not funded, a new part for more work — never the price of a funded part, never on a part in dispute.
 */
class FreelanceChangeRequestsTest extends TestCase
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
        foreach (['freelance', 'projects', 'bidding', 'chat', 'wallet_payment', 'disputes'] as $feature) {
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

    private function ask(string $as, array $body)
    {
        return $this->postJson(($as === 'client' ? self::CLIENT : self::FREELANCER)."/{$this->project->uuid}/agreement/changes", $body);
    }

    private function answer(string $as, string $change, string $action)
    {
        return $this->postJson(($as === 'client' ? self::CLIENT : self::FREELANCER)."/{$this->project->uuid}/agreement/changes/{$change}/{$action}");
    }

    private function agreement(): \Modules\Freelance\Models\FreelanceAgreement
    {
        return \Modules\Freelance\Models\FreelanceAgreement::query()->latest('id')->firstOrFail();
    }

    public function test_more_days_and_a_new_scope_need_the_other_side(): void
    {
        [$one] = $this->activeInTwo();
        $this->asFreelancer($this->sara);
        $c = $this->ask('freelancer', ['type' => 'extend_days', 'milestone' => $one, 'days' => 5, 'reason' => 'The client sent the texts late.'])->assertCreated()->json('data.0.uuid');
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.change.requested')->count());
        // Nothing changed yet; the one who asked can't answer it.
        $this->assertNull(\Modules\Freelance\Models\FreelanceMilestone::query()->where('uuid', $one)->value('duration_days'));
        $this->answer('freelancer', $c, 'accept')->assertStatus(409)->assertJsonPath('error_code', 'freelance_change_not_open');
        // One waiting at a time on a part.
        $this->ask('freelancer', ['type' => 'extend_days', 'milestone' => $one, 'days' => 2])->assertStatus(409)->assertJsonPath('error_code', 'freelance_change_pending');

        $this->asClient();
        $this->answer('client', $c, 'accept')->assertOk()->assertJsonPath('data.0.status', 'accepted');
        $this->assertSame(5, \Modules\Freelance\Models\FreelanceMilestone::query()->where('uuid', $one)->value('duration_days'));
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.change.accepted')->count());

        // The scope: the client asks, the freelancer says no — nothing changes.
        $s = $this->ask('client', ['type' => 'scope', 'excludes' => 'Hosting and the domain'])->assertCreated()->json('data.0.uuid');
        $this->asFreelancer($this->sara);
        $this->answer('freelancer', $s, 'reject')->assertOk();
        $this->assertNull($this->agreement()->excludes);
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.change.rejected')->count());
    }

    public function test_a_part_not_funded_gets_a_new_price_and_the_money_follows(): void
    {
        $this->feesOn();
        [$one, $two] = $this->activeInTwo();
        $this->asFreelancer($this->sara);
        $c = $this->ask('freelancer', ['type' => 'milestone_price', 'milestone' => $two, 'amount_minor' => 300000, 'reason' => 'The second part grew.'])->assertCreated()->json('data.0.uuid');
        $this->asClient();
        $this->answer('client', $c, 'accept')->assertOk();
        $two_ = \Modules\Freelance\Models\FreelanceMilestone::query()->where('uuid', $two)->firstOrFail();
        // 300000: fee 2% = 6000, commission 10% = 30000, net 270000 — the frozen policy.
        $this->assertSame([300000, 6000, 30000, 270000], [$two_->amount_minor, $two_->client_fee_minor, $two_->commission_minor, $two_->net_minor]);
        $a = $this->agreement();
        $this->assertSame(400000, $a->price_minor);
        $this->assertSame(8000, $a->money['client_fee_minor']);

        // A funded part keeps its price: the request is refused.
        $this->topUp(500000);
        $this->fundCall($one)->assertOk();
        $this->asFreelancer($this->sara);
        $this->ask('freelancer', ['type' => 'milestone_price', 'milestone' => $one, 'amount_minor' => 150000])->assertStatus(409)->assertJsonPath('error_code', 'freelance_change_part_funded');
    }

    public function test_more_work_becomes_a_new_part_funded_like_any_other(): void
    {
        [$one] = $this->activeInTwo();
        $this->asFreelancer($this->sara);
        $c = $this->ask('freelancer', ['type' => 'add_milestone', 'title' => 'Reminder messages', 'amount_minor' => 80000, 'reason' => 'Outside the agreement.'])->assertCreated()->json('data.0.uuid');
        $this->ask('freelancer', ['type' => 'add_milestone', 'title' => 'Tiny', 'amount_minor' => 100])->assertStatus(409);
        $this->asClient();
        $res = $this->answer('client', $c, 'accept')->assertOk();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertJsonCount(3, 'data.milestones')->assertJsonPath('data.milestones.2.title', 'Reminder messages')
            ->assertJsonPath('data.milestones.2.status', 'unfunded')->assertJsonPath('data.price_minor', 430000);
        $this->assertContains('change.accepted', $this->approvals());
    }

    public function test_the_rules_too_small_too_late_a_dispute_and_outsiders(): void
    {
        [$one, $two] = $this->activeInTwo();
        $this->asClient();
        // Below the minimum part (50.00) when there is more than one part.
        $this->ask('client', ['type' => 'milestone_price', 'milestone' => $two, 'amount_minor' => 1000])->assertStatus(422)->assertJsonPath('error_code', 'freelance_milestone_too_small');

        // Nobody answers in time: it lapses, and a new one can be asked.
        $c = $this->ask('client', ['type' => 'extend_days', 'days' => 7])->assertCreated()->json('data.0.uuid');
        $this->travel(4)->days();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement/changes")->assertJsonPath('data.0.status', 'expired');
        $this->asFreelancer($this->sara);
        $this->answer('freelancer', $c, 'accept')->assertStatus(409);
        $this->asClient();
        $this->ask('client', ['type' => 'extend_days', 'days' => 7])->assertCreated();

        // A part in dispute takes its waiting changes with it, and takes no new ones.
        $this->topUp(500000);
        $this->fundCall($one)->assertOk();
        $p = $this->ask('client', ['type' => 'extend_days', 'milestone' => $one, 'days' => 3])->assertCreated()->json('data.0.uuid');
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/dispute", ['reason' => 'late', 'details' => 'The first part is late and nothing was shown yet.'])->assertCreated();
        $this->assertSame('cancelled', \Modules\Freelance\Models\FreelanceChangeRequest::query()->where('uuid', $p)->value('status'));
        $this->ask('client', ['type' => 'extend_days', 'milestone' => $one, 'days' => 3])->assertStatus(409)->assertJsonPath('error_code', 'freelance_change_not_allowed');

        // Another freelancer sees none of it.
        $this->asFreelancer($this->lina);
        $this->getJson(self::FREELANCER."/{$this->project->uuid}/agreement/changes")->assertNotFound();
    }

    public function test_a_price_change_for_a_part_funded_meanwhile_ends_and_the_asker_is_told(): void
    {
        [$one] = $this->activeInTwo();
        $this->asFreelancer($this->sara);
        $c = $this->ask('freelancer', ['type' => 'milestone_price', 'milestone' => $one, 'amount_minor' => 150000])->assertCreated()->json('data.0.uuid');
        // The client funds the part before answering, then tries to accept: refused, and the request is over (not left waiting).
        $this->asClient();
        $this->topUp(500000);
        $this->fundCall($one)->assertOk();
        $this->answer('client', $c, 'accept')->assertStatus(409)->assertJsonPath('error_code', 'freelance_change_part_funded');
        $this->assertSame('cancelled', \Modules\Freelance\Models\FreelanceChangeRequest::query()->where('uuid', $c)->value('status'));
        $this->assertSame(100000, \Modules\Freelance\Models\FreelanceMilestone::query()->where('uuid', $one)->value('amount_minor'));
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.change.cancelled')->count());
        // The place is free again.
        $this->asFreelancer($this->sara);
        $this->ask('freelancer', ['type' => 'extend_days', 'milestone' => $one, 'days' => 2])->assertCreated();
    }

    public function test_at_most_five_changes_and_no_part_beyond_the_policys_count(): void
    {
        [$one] = $this->activeInTwo();
        foreach (range(1, 5) as $i) {
            $this->asClient();
            $c = $this->ask('client', ['type' => 'extend_days', 'days' => 1])->assertCreated()->json('data.0.uuid');
            $this->asFreelancer($this->sara);
            $this->answer('freelancer', $c, 'accept')->assertOk();
        }
        $this->asClient();
        $this->ask('client', ['type' => 'extend_days', 'days' => 1])->assertStatus(422)->assertJsonPath('error_code', 'freelance_changes_limit');
        $this->assertSame(26, $this->agreement()->duration_days);

        // A fresh agreement frozen with at most 2 parts: a third one is refused.
        \Modules\Freelance\Models\FreelanceChangeRequest::query()->update(['status' => 'rejected']);
        \Modules\Freelance\Models\FreelanceAgreement::query()->each(fn ($a) => $a->forceFill(['policy' => ['milestones.max_count' => 2] + (array) $a->policy])->save());
        $this->ask('client', ['type' => 'add_milestone', 'title' => 'One more', 'amount_minor' => 60000])->assertStatus(422)->assertJsonPath('error_code', 'freelance_milestones_too_many');
    }
}
