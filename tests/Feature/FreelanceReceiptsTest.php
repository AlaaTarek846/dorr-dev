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
 * FL-511: every money movement of a part leaves a receipt for the side it concerns, numbered in order per country and year,
 * with the movement; each side reads only its own.
 */
class FreelanceReceiptsTest extends TestCase
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
        foreach (['freelance', 'projects', 'bidding', 'chat', 'wallet_payment'] as $feature) {
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

    public function test_funding_and_approving_leave_numbered_receipts_for_each_side(): void
    {
        $this->feesOn();
        [$one] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(500000);
        $this->delivered($one);
        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/approve")->assertOk();
        $year = now()->year;

        $mine = $this->getJson('/api/mobile/v1/freelance/receipts')->assertOk()->json('data');
        $this->assertSame(['paid', 'funded'], array_column($mine, 'kind'));
        $this->assertSame("FR-SA-{$year}-000001", $mine[1]['number']);
        $this->assertSame(102000, $mine[1]['total_minor']);
        $this->assertSame(2000, $mine[0]['fee_minor']);
        $this->assertSame("FR-SA-{$year}-000002", $mine[0]['number']);
        $paid = $this->getJson("/api/mobile/v1/freelance/receipts/{$mine[0]['uuid']}")->assertOk()->assertJsonPath('data.reason', 'approved')->json('data');
        $this->assertStringContainsString("FR-SA-{$year}-000002", $paid['text']);
        $this->assertStringContainsString('1,020.00 SAR', $paid['text']);

        $this->asFreelancer($this->sara);
        $hers = $this->getJson('/api/freelance/v1/receipts')->assertOk()->json('data');
        $this->assertCount(1, $hers);
        $this->assertSame('earned', $hers[0]['kind']);
        $this->assertSame([100000, 10000, 90000], [$hers[0]['amount_minor'], $hers[0]['fee_minor'], $hers[0]['total_minor']]);
        $this->assertSame("FR-SA-{$year}-000003", $hers[0]['number']);
        // The client's receipt isn't hers.
        $this->getJson("/api/freelance/v1/receipts/{$mine[0]['uuid']}")->assertNotFound();
    }

    public function test_a_refund_and_an_ended_part_have_their_receipts(): void
    {
        [$one, $two] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(500000);
        $this->fundCall($one)->assertOk();
        // Part one, started: the client asks, the freelancer agrees — 30% for her by the default policy.
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$one}/start")->assertOk();
        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/cancel")->assertOk();
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$one}/cancel-accept")->assertOk();
        // Part two, funded, not started: everything back.
        $this->asClient();
        $this->fundCall($two)->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$two}/cancel")->assertOk();

        $r = fn (string $party, string $kind) => \Modules\Freelance\Models\FreelanceReceipt::query()->where('party', $party)->where('kind', $kind)->firstOrFail();
        $this->assertSame(250000, $r('client', 'refunded')->total_minor);
        $this->assertSame('cancelled', $r('client', 'refunded')->reason);
        $this->assertSame([30000, 70000, 'ended'], [$r('client', 'settled')->total_minor, $r('client', 'settled')->refunded_minor, $r('client', 'settled')->reason]);
        $this->assertSame(30000, $r('freelancer', 'earned')->total_minor);
        // Two holds, the settlement, her earning and the refund — five numbers in a row, none skipped.
        $this->assertSame(range(1, 5), \Modules\Freelance\Models\FreelanceReceipt::query()->orderBy('seq')->pluck('seq')->all());
    }

    public function test_a_receipt_is_never_changed(): void
    {
        [$one] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(500000);
        $this->fundCall($one)->assertOk();
        $receipt = \Modules\Freelance\Models\FreelanceReceipt::query()->firstOrFail();
        $this->expectException(\LogicException::class);
        $receipt->update(['total_minor' => 1]);
    }
}
