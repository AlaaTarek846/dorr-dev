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
 * FL-704 / FL-507 / FL-705: a dispute freezes a part and its money; both sides show what they have; staff decide (refund, release or
 * split); the first decision waits for the appeal days, one appeal goes to someone else whose decision is final; the money moves once.
 */
class FreelanceDisputeTest extends TestCase
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

    private function admin(): \Modules\Admin\Models\Admin
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = \Modules\Admin\Models\Admin::create(['name' => 'Staff', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach (['freelance-disputes.view', 'freelance-disputes.update'] as $name) {
            \Spatie\Permission\Models\Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo(['freelance-disputes.view', 'freelance-disputes.update']);

        return $admin;
    }

    private function asAdmin(\Modules\Admin\Models\Admin $admin): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    /** Sara's part one delivered, then a dispute opened by the client; returns [part, dispute]. */
    private function disputed(): array
    {
        $this->feesOn();
        [$one] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(500000);
        $this->started($one);
        $this->f($one, 'deliver', ['note' => 'Here it is'])->assertOk();
        $this->asClient();
        $uuid = $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/dispute", ['reason' => 'not_as_agreed', 'details' => 'The booking page is missing the reminders we agreed on.'])
            ->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.mine', true)->json('data.uuid');

        return [$one, $uuid];
    }

    private function decide(string $dispute, array $body)
    {
        return $this->postJson("/api/admin/v1/freelance-disputes/{$dispute}/decide", $body);
    }

    public function test_a_dispute_freezes_the_part_and_both_sides_show_what_they_have(): void
    {
        [$one, $d] = $this->disputed();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertJsonPath('data.milestones.0.status', 'disputed')->assertJsonPath('data.milestones.0.dispute.uuid', $d)
            ->assertJsonPath('data.milestones.0.can.approve', false)->assertJsonPath('data.milestones.0.can.dispute', false);
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.dispute.opened')->count());
        // A second dispute on the same part: no.
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/dispute", ['reason' => 'late', 'details' => 'Trying to open another one on the same part.'])->assertStatus(409);

        // Frozen: the clock doesn't approve it.
        $this->travel(30)->days();
        $this->artisan('freelance:release-due')->assertSuccessful();
        $this->assertSame(0, (int) $this->wallet($this->sara)->withdrawable_minor);

        // Each side adds what it has, with a file.
        $this->postJson(self::CLIENT."/{$this->project->uuid}/disputes/{$d}/evidence", ['body' => 'The agreement says reminders.', 'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('agreement.pdf', "%PDF-1.4\n%%EOF")])->assertCreated();
        $this->asFreelancer($this->sara);
        $res = $this->postJson(self::FREELANCER."/{$this->project->uuid}/disputes/{$d}/evidence", ['body' => 'Reminders were moved to part two by chat.'])->assertCreated();
        $res->assertJsonCount(2, 'data.evidence')->assertJsonPath('data.evidence.1.mine', true)->assertJsonPath('data.mine', false);
        $path = $res->json('data.evidence.0.file.path');
        $this->get("/api/freelance/v1/{$path}")->assertOk();

        // Nobody else sees it.
        $this->asFreelancer($this->lina);
        $this->getJson(self::FREELANCER."/{$this->project->uuid}/disputes/{$d}")->assertNotFound();
        $this->get("/api/freelance/v1/{$path}")->assertNotFound();
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/disputes/{$d}/evidence", ['body' => 'Not my dispute at all.'])->assertNotFound();
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/disputes/{$d}/appeal", ['reason' => 'Not my dispute to appeal either.'])->assertNotFound();
    }

    public function test_a_first_decision_waits_for_the_appeal_days_then_moves_the_money_once(): void
    {
        [$one, $d] = $this->disputed();
        $staff = $this->admin();
        $this->asAdmin($staff);
        $this->getJson('/api/admin/v1/freelance-disputes')->assertOk()->assertJsonPath('data.0.uuid', $d)->assertJsonPath('pagination.counts.open', 1);
        $this->getJson("/api/admin/v1/freelance-disputes/{$d}")->assertOk()->assertJsonPath('data.part.delivery_note', 'Here it is')->assertJsonPath('data.part.held_minor', 102000);
        $this->decide($d, ['decision' => 'release', 'note' => 'The reminders were moved by chat; the delivery matches.'])->assertOk()->assertJsonPath('data.status', 'decided');
        $this->assertSame(0, (int) $this->wallet($this->sara)->withdrawable_minor, 'nothing moves before the appeal days');
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.dispute.decided')->count());
        $this->decide($d, ['decision' => 'refund', 'note' => 'Changing my mind is not possible.'])->assertStatus(409);

        $this->travel(2)->days();
        $this->artisan('freelance:execute-disputes')->assertSuccessful();
        $this->assertSame(0, (int) $this->wallet($this->sara)->withdrawable_minor);
        $this->travel(2)->days();
        $this->artisan('freelance:execute-disputes')->assertSuccessful();
        $this->artisan('freelance:execute-disputes')->assertSuccessful();
        $this->assertSame(90000, (int) $this->wallet($this->sara)->withdrawable_minor, 'released once');
        $this->assertSame(2000, $this->income('freelance_client_fees'));
        $this->asClient();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/disputes/{$d}")->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.decision.type', 'release');
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.dispute.resolved')->count());
    }

    public function test_one_appeal_goes_to_someone_else_whose_decision_is_final_and_runs_at_once(): void
    {
        [$one, $d] = $this->disputed();
        $first = $this->admin();
        $this->asAdmin($first);
        $this->decide($d, ['decision' => 'release', 'note' => 'The delivery matches the agreement.'])->assertOk();

        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/disputes/{$d}/appeal", ['reason' => 'The reminders were never moved; see my evidence.'])->assertOk()->assertJsonPath('data.status', 'appealed');
        $this->postJson(self::CLIENT."/{$this->project->uuid}/disputes/{$d}/appeal", ['reason' => 'Appealing a second time is not allowed.'])->assertStatus(409);
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.dispute.appealed')->count());
        $this->travel(5)->days();
        $this->artisan('freelance:execute-disputes')->assertSuccessful();
        $this->assertSame(0, (int) $this->wallet($this->sara)->withdrawable_minor, 'an appealed decision does not run');

        // The same person can't decide the appeal; someone else does — a 40% split, at once.
        $this->asAdmin($first);
        $this->decide($d, ['decision' => 'split', 'freelancer_percent' => 40, 'note' => 'Half the reminders were done.'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_dispute_same_admin');
        $this->asAdmin($this->admin());
        $this->decide($d, ['decision' => 'split', 'freelancer_percent' => 40, 'note' => 'Part of the reminders were done.'])->assertOk()->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.decision.final', true);
        // 40% of 100000 = 40000, commission 10% of it = 4000 → 36000; fee 40% of 2000 = 800; back to the client 102000 − 40800.
        $this->assertSame(36000, (int) $this->wallet($this->sara)->withdrawable_minor);
        $client = $this->wallet($this->client)->fresh();
        $this->assertSame(500000 - 40800, (int) $client->availableMinor(\Modules\Wallet\Enums\WalletBucket::Withdrawable));
        $this->assertSame(0, (int) $client->held_withdrawable_minor + (int) $client->held_spend_only_minor);
        $this->assertSame('cancelled', \Modules\Freelance\Models\FreelanceMilestone::query()->where('uuid', $one)->first()->status->value);
    }

    public function test_a_refund_gives_everything_back_and_who_may_decide(): void
    {
        [$one, $d] = $this->disputed();
        // Without the permission: no.
        $nobody = \Modules\Admin\Models\Admin::create(['name' => 'X', 'email' => uniqid('x').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        $this->asAdmin($nobody);
        $this->decide($d, ['decision' => 'refund', 'note' => 'Everything back to the client.'])->assertForbidden();
        $this->asAdmin($this->admin());
        $this->decide($d, ['decision' => 'split', 'note' => 'A split without a share is refused.'])->assertStatus(422);
        $this->decide($d, ['decision' => 'refund', 'note' => 'The delivery is not what was agreed.'])->assertOk();
        $this->travel(4)->days();
        $this->artisan('freelance:execute-disputes')->assertSuccessful();
        $this->assertSame(500000, (int) $this->wallet($this->client)->fresh()->availableMinor(\Modules\Wallet\Enums\WalletBucket::Withdrawable));
        $this->assertSame(0, (int) $this->wallet($this->sara)->withdrawable_minor);
        $this->assertSame(0, $this->income('freelance_client_fees') + $this->income('freelance_commissions'));
    }

    public function test_a_dispute_can_be_opened_only_on_a_part_with_money_held(): void
    {
        $this->feesOn();
        [$one] = $this->activeInTwo();
        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/dispute", ['reason' => 'late', 'details' => 'Nothing funded yet, so nothing to dispute.'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_dispute_not_allowed');
        $this->topUp(500000);
        $this->fundCall($one)->assertOk();
        // The freelancer can open one too.
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$one}/dispute", ['reason' => 'no_response', 'details' => 'The client stopped answering after funding.'])->assertCreated()->assertJsonPath('data.opened_by', 'freelancer');
        // The project can't be cancelled while it is in dispute.
        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/cancel", [])->assertStatus(409);
    }

    /** FL-606: staff read one ordered list of everything - agreement, approvals, work, deliveries with files, evidence, chat. */
    public function test_the_evidence_timeline_puts_everything_in_one_ordered_list_for_staff(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->feesOn();
        [$one] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(500000);
        $this->started($one);
        $this->asFreelancer($this->sara);
        $this->post(self::FREELANCER."/{$this->project->uuid}/milestones/{$one}/deliver", ['note' => 'First version with the design.', 'files' => [\Illuminate\Http\UploadedFile::fake()->image('design.png')]], ['Accept' => 'application/json'])->assertOk();
        $this->asClient();
        $d = $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/dispute", ['reason' => 'not_as_agreed', 'details' => 'The booking page is missing the reminders we agreed on.'])->assertCreated()->json('data.uuid');
        $this->postJson(self::CLIENT."/{$this->project->uuid}/disputes/{$d}/evidence", ['body' => 'The agreement says reminders.'])->assertCreated();
        // A chat between the two, one line each.
        $room = \Modules\Freelance\Models\FreelanceConversation::query()->create(['client_id' => $this->client->id, 'freelancer_id' => $this->sara->id, 'context_type' => 'project', 'context_id' => $this->project->id, 'status' => 'open']);
        foreach ([['client', 'Where are the reminders?'], ['freelancer', 'They were moved to part two.']] as [$side, $text]) {
            \Modules\Freelance\Models\FreelanceMessage::query()->create(['conversation_id' => $room->id, 'sender_type' => $side, 'sender_id' => 1, 'kind' => 'text', 'body' => $text, 'created_at' => now()]);
        }

        // Only staff with the permission.
        $this->asClient();
        $this->getJson("/api/admin/v1/freelance-disputes/{$d}/timeline")->assertUnauthorized();
        $this->asAdmin($this->admin());
        $res = $this->getJson("/api/admin/v1/freelance-disputes/{$d}/timeline")->assertOk();
        $entries = $res->json('data.entries');
        $kinds = array_unique(array_column($entries, 'kind'));
        foreach (['agreement', 'approval', 'delivery', 'dispute', 'evidence', 'chat'] as $kind) {
            $this->assertContains($kind, $kinds);
        }
        // Oldest first.
        $times = array_column($entries, 'at');
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times);
        // The delivery carries its file and staff can open it; the chat is there.
        $delivery = collect($entries)->firstWhere('kind', 'delivery');
        $this->assertSame('design.png', $delivery['files'][0]['name']);
        $this->get('/api/admin/v1/'.$delivery['files'][0]['path'])->assertOk();
        $this->assertContains('Where are the reminders?', array_column($entries, 'label'));
        // Reading it is on the audit log.
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'freelance.dispute.timeline_viewed')->count());

        // The CSV for the case folder.
        $csv = $this->get("/api/admin/v1/freelance-disputes/{$d}/timeline/export")->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));
        $this->assertStringContainsString('design.png', $csv->streamedContent());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'freelance.dispute.timeline_exported')->count());
    }

    /** FL-803 / FL-806 / FL-805 / FL-807 / FL-808 on the same case: classify, link a ticket, the audit log, operations, improvement and risk. */
    public function test_staff_classify_link_audit_and_read_the_figures(): void
    {
        [$one, $d] = $this->disputed();
        $staff = $this->admin();
        foreach (['freelance-support.view', 'freelance-support.update', 'freelance-audit.view', 'freelance-reports.view', 'freelance-risk.view', 'freelance-projects.view'] as $name) {
            \Spatie\Permission\Models\Permission::findOrCreate($name, 'admin_api');
            $staff->givePermissionTo($name);
        }
        $this->asAdmin($staff);

        // The person's own reason gives a suggestion; staff's label replaces it and is logged.
        $this->getJson("/api/admin/v1/freelance-disputes/{$d}")->assertOk()->assertJsonPath('data.category', null)->assertJsonPath('data.suggested_category', 'quality');
        $this->postJson("/api/admin/v1/freelance-disputes/{$d}/classify", ['category' => 'bogus'])->assertStatus(422);
        $this->postJson("/api/admin/v1/freelance-disputes/{$d}/classify", ['category' => 'payment'])->assertOk()->assertJsonPath('data.category', 'payment');
        $this->getJson('/api/admin/v1/freelance-disputes?category=payment')->assertOk()->assertJsonPath('data.0.category', 'payment');
        $this->getJson('/api/admin/v1/freelance-disputes?category=delivery')->assertOk()->assertJsonCount(0, 'data');

        // A ticket from the freelancer shows on the Freelance desk, and links to the dispute.
        $ticket = \Modules\User\Models\SupportTicket::query()->create(['freelancer_id' => $this->sara->id, 'title' => 'Where is my money', 'body' => 'The client disputed my part.', 'status' => 'opened']);
        $this->getJson('/api/admin/v1/freelance-support/tickets')->assertOk()->assertJsonPath('data.0.number', $ticket->number)->assertJsonPath('data.0.link', null)->assertJsonPath('pagination.counts.opened', 1);
        $this->postJson("/api/admin/v1/freelance-support/tickets/{$ticket->number}/link", ['kind' => 'dispute', 'uuid' => (string) \Illuminate\Support\Str::uuid()])->assertStatus(404);
        $this->postJson("/api/admin/v1/freelance-support/tickets/{$ticket->number}/link", ['kind' => 'dispute', 'uuid' => $d])->assertOk()->assertJsonPath('data.link.kind', 'dispute');
        $this->getJson("/api/admin/v1/freelance-support/tickets?project={$this->project->uuid}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/v1/freelance-support/tickets?q=nothing-like-this')->assertOk()->assertJsonCount(0, 'data');

        // The decision and the label are in the audit log, with who did them.
        $this->decide($d, ['decision' => 'release', 'note' => 'The delivery matches what was agreed on.'])->assertOk();
        $audit = $this->getJson('/api/admin/v1/freelance-audit?action=freelance.dispute')->assertOk();
        $this->assertEqualsCanonicalizing(['freelance.dispute.classified', 'freelance.dispute.decided'], array_column($audit->json('data'), 'action'));
        $this->assertSame('Staff', $audit->json('data.0.actor.name'));
        $this->getJson('/api/admin/v1/freelance-audit?action=freelance.support')->assertOk()->assertJsonCount(1, 'data');

        // The figures read what is already kept.
        $ops = $this->getJson('/api/admin/v1/freelance-reports/operations')->assertOk();
        $this->assertSame(1, $ops->json('data.disputes_opened'));
        $this->assertSame(1, $ops->json('data.disputes_by_category.payment'));
        $this->assertArrayHasKey('active', $ops->json('data.agreements_by_status'));
        $this->getJson('/api/admin/v1/freelance-reports/improvement')->assertOk()->assertJsonStructure(['data' => ['cancellations' => ['by_client', 'by_freelancer'], 'dissatisfaction' => ['client', 'freelancer'], 'offers', 'invitations']]);

        // Nobody at risk yet; once Sara is marked, she is listed with her reasons.
        $this->getJson('/api/admin/v1/freelance-risk/accounts')->assertOk()->assertJsonCount(0, 'data');
        $this->sara->forceFill(['risk_level' => 'high', 'quality_score' => 40])->save();
        $risk = $this->getJson('/api/admin/v1/freelance-risk/accounts?side=freelancer')->assertOk();
        $this->assertSame(['freelancer', 'Sara', 'high'], [$risk->json('data.0.side'), $risk->json('data.0.name'), $risk->json('data.0.risk_level')]);
        $this->getJson('/api/admin/v1/freelance-risk/overview')->assertOk()->assertJsonPath('data.freelancers.high', 1);

        // Where the service is open: closing it on the availability page closes it for the apps too (FL-804).
        $this->getJson('/api/admin/v1/freelance-oversight/countries')->assertOk()->assertJsonPath('data.0.service_open', true)->assertJsonPath('data.0.closed_by_availability', false);
        (new \Database\Seeders\General\ServiceCategoriesSeeder)->run();
        $category = \App\Models\ServiceCategory::query()->where('module_name', 'freelance')->first();
        $this->assertNotNull($category);
        \App\Models\ServiceAvailability::query()->create(['service_category_id' => $category->id, 'country_id' => $this->saudi->id, 'service_enabled' => false]);
        \Illuminate\Support\Facades\Cache::forget('freelance:service-closed');
        $this->getJson('/api/admin/v1/freelance-oversight/countries')->assertOk()->assertJsonPath('data.0.service_open', false)->assertJsonPath('data.0.closed_by_availability', true);
    }
}
