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
 * FL-406: the project's record of approvals — every step that agrees to something is written with the step itself, never changed
 * or deleted, and read by the two sides (their own agreement) and by staff (with who did it).
 */
class FreelanceApprovalTrailTest extends TestCase
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

    private function admin(): \Modules\Admin\Models\Admin
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = \Modules\Admin\Models\Admin::create(['name' => 'Staff', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        \Spatie\Permission\Models\Permission::findOrCreate('freelance-projects.view', 'admin_api');
        $admin->givePermissionTo(['freelance-projects.view']);

        return $admin;
    }

    public function test_every_step_of_the_project_is_in_the_record_in_order(): void
    {
        [$one, $two] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(500000);
        $this->delivered($one);
        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/approve")->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$two}/cancel")->assertOk();

        $expected = ['agreement.milestones', 'agreement.sent', 'agreement.approved', 'milestone.funded', 'milestone.delivered', 'milestone.approved', 'milestone.cancelled', 'agreement.completed'];
        $this->assertSame($expected, array_values(array_intersect($this->approvals(), $expected)));

        $trail = $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement/trail")->assertOk()->json('data');
        $funded = collect($trail)->firstWhere('action', 'milestone.funded');
        $this->assertSame('client', $funded['actor']);
        $this->assertTrue($funded['mine']);
        $this->assertSame($one, $funded['details']['milestone']);
        $this->assertSame('Part funded', $funded['label']);
        $this->assertArrayNotHasKey('actor_id', $funded);

        $this->asFreelancer($this->sara);
        $mine = $this->getJson(self::FREELANCER."/{$this->project->uuid}/agreement/trail")->assertOk()->json('data');
        $this->assertSame(count($trail), count($mine));
        $this->assertTrue(collect($mine)->firstWhere('action', 'milestone.delivered')['mine']);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->admin(), [], 'admin_api');
        $staff = $this->getJson("/api/admin/v1/freelance-projects/{$this->project->uuid}/trail")->assertOk()->json('data');
        $this->assertSame($this->client->id, collect($staff)->firstWhere('action', 'milestone.funded')['actor_id']);
    }

    public function test_the_record_is_never_changed_or_deleted(): void
    {
        $this->activeInTwo();
        $row = \Modules\Freelance\Models\FreelanceProjectApproval::query()->firstOrFail();
        try {
            $row->update(['action' => 'agreement.declined']);
            $this->fail('An approval was changed.');
        } catch (\LogicException) {
        }
        try {
            $row->delete();
            $this->fail('An approval was deleted.');
        } catch (\LogicException) {
        }
        $this->assertSame('agreement.milestones', $row->fresh()->action);
    }

    public function test_another_freelancer_who_offered_cannot_read_it(): void
    {
        $this->activeInTwo();
        $this->asFreelancer($this->lina);
        $this->getJson(self::FREELANCER."/{$this->project->uuid}/agreement/trail")->assertNotFound();
    }

    public function test_changes_and_requests_to_end_a_part_are_recorded(): void
    {
        [$one] = $this->activeInTwo();
        $this->asFreelancer($this->sara);
        $c = $this->postJson(self::FREELANCER."/{$this->project->uuid}/agreement/changes", ['type' => 'extend_days', 'milestone' => $one, 'days' => 5])->assertCreated()->json('data.0.uuid');
        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/changes/{$c}/reject")->assertOk();

        $this->topUp(500000);
        $this->fundCall($one)->assertOk();
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$one}/start")->assertOk();
        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/cancel", ['reason' => 'Plans changed'])->assertOk();
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$one}/cancel-reject")->assertOk();

        $actions = $this->approvals();
        foreach (['change.requested', 'change.rejected', 'milestone.cancel_requested', 'milestone.cancel_rejected'] as $a) {
            $this->assertContains($a, $actions);
        }
        $asked = \Modules\Freelance\Models\FreelanceProjectApproval::query()->where('action', 'milestone.cancel_requested')->firstOrFail();
        $this->assertSame('Plans changed', $asked->payload['reason']);
        $this->assertSame('client', $asked->actor_type);
    }
}
