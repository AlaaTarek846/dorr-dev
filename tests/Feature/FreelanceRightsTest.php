<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceProjectApproval;
use Modules\Freelance\Models\FreelanceRightsClaim;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerPortfolioItem;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Services\WalletService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FL-L13: ownership and licences. The freelancer says on an offer or a service who owns the work once it is paid and what the client may
 * do with it; the agreement keeps those terms and the standard text of that day; the rights of a part pass when it is approved and paid;
 * every delivery carries the freelancer's word that the work is theirs; a finished work goes in the portfolio only with the client's leave;
 * the staff keep the standard texts and decide the reports of works someone says are theirs.
 */
class FreelanceRightsTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1';

    private const CLIENT = '/api/mobile/v1/freelance';

    private Country $saudi;

    private User $mona;

    private Freelancer $sara;

    private FreelanceProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        $this->features(['freelance', 'projects', 'bidding', 'chat', 'wallet_payment', 'disputes', 'services', 'rights']);
        FreelancePolicies::forget();

        $this->mona = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->project = FreelanceProject::query()->create([
            'client_id' => $this->mona->id, 'country_id' => $this->saudi->id, 'title' => 'A booking website', 'description' => 'A small site where patients pick a doctor and a time.',
            'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR', 'duration_days' => 30,
            'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
    }

    // -------------------------------------------------------------- helpers

    /** @param  list<string>  $on */
    private function features(array $on): void
    {
        FreelanceFeatureRule::query()->delete();
        foreach ($on as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
    }

    private function asSara(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->sara->refresh(), [], 'freelance_api');
    }

    private function asClient(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->mona->refresh(), [], 'user_api');
    }

    private function staff(string ...$permissions): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'Staff', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'admin_api');
            $admin->givePermissionTo($permission);
        }
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    /** Sara offers on the open project with these terms (none: the platform's defaults). */
    private function offer(?array $rights = null): string
    {
        $this->asSara();
        $body = ['price_minor' => 350000, 'duration_days' => 21, 'details' => 'I built three booking sites like this; I will deliver in three steps with a demo each week.'];
        if ($rights !== null) {
            $body['rights'] = $rights;
        }

        return $this->postJson(self::API."/projects/{$this->project->uuid}/offers", $body)->assertCreated()->json('data.uuid');
    }

    /** The client chooses the offer, splits the work in two, sends it; Sara approves — active, two parts. @return list<string> the parts */
    private function active(): array
    {
        $this->asClient();
        $offer = FreelanceOffer::query()->where('freelancer_id', $this->sara->id)->firstOrFail();
        $this->getJson(self::CLIENT."/projects/{$this->project->uuid}/offers")->assertOk();
        $this->postJson(self::CLIENT."/projects/{$this->project->uuid}/offers/{$offer->uuid}/choose")->assertOk();
        $this->putJson(self::CLIENT."/projects/{$this->project->uuid}/agreement/milestones", ['milestones' => [['title' => 'Part 1', 'amount_minor' => 100000], ['title' => 'Part 2', 'amount_minor' => 250000]]])->assertOk();
        $this->postJson(self::CLIENT."/projects/{$this->project->uuid}/agreement/send")->assertOk();
        $this->asSara();
        $this->postJson(self::API."/projects/{$this->project->uuid}/agreement/approve")->assertOk();

        return FreelanceMilestone::query()->orderBy('position')->pluck('uuid')->all();
    }

    private function fund(string $uuid): void
    {
        $m = FreelanceMilestone::query()->where('uuid', $uuid)->firstOrFail();
        $w = app(WalletService::class);
        $w->credit($w->firstOrCreateWallet($this->mona, $this->saudi), $m->amount_minor + $m->client_fee_minor, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        app(\Modules\Freelance\Services\Projects\MilestonePayments::class)->fund($m->load('agreement'), $this->mona);
    }

    private function deliver(string $part, array $extra = [])
    {
        $this->asSara();

        return $this->post(self::API."/projects/{$this->project->uuid}/milestones/{$part}/deliver", ['note' => 'Here is the first version.'] + $extra, ['Accept' => 'application/json']);
    }

    /** The first part funded and started. */
    private function working(): string
    {
        [$one] = $this->active();
        $this->fund($one);
        $this->asSara();
        $this->postJson(self::API."/projects/{$this->project->uuid}/milestones/{$one}/start")->assertOk();

        return $one;
    }

    /** A work Sara finished for Mona, with these terms; the client's leave is whatever `$allowed` says. */
    private function finished(bool $allowed = false, ?array $rights = null): FreelanceAgreement
    {
        $p = FreelanceProject::query()->create([
            'client_id' => $this->mona->id, 'country_id' => $this->saudi->id, 'title' => 'A logo for the shop', 'description' => 'Old work finished last month.',
            'budget_type' => 'fixed', 'budget_min_minor' => 30000, 'currency_code' => 'SAR', 'duration_days' => 5, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Closed,
        ]);
        $o = FreelanceOffer::query()->create(['project_id' => $p->id, 'freelancer_id' => $this->sara->id, 'price_minor' => 30000, 'currency_code' => 'SAR', 'duration_days' => 5, 'details' => 'Done.', 'status' => 'accepted']);

        return FreelanceAgreement::query()->create([
            'project_id' => $p->id, 'offer_id' => $o->id, 'client_id' => $this->mona->id, 'freelancer_id' => $this->sara->id, 'scope' => 'A logo.',
            'price_minor' => 30000, 'currency_code' => 'SAR', 'duration_days' => 5, 'policy' => [], 'money' => [], 'status' => FreelanceAgreement::COMPLETED, 'completed_at' => now(),
            'rights' => $rights ?? ['model' => 'transfer', 'commercial' => true, 'exclusive' => true, 'term_months' => null, 'territory' => 'worldwide', 'portfolio' => true,
                'text' => ['id' => null, 'version' => 0, 'title' => ['ar' => 'ع', 'en' => 'T'], 'body' => ['ar' => 'ن', 'en' => 'B']]],
            'portfolio_allowed' => $allowed,
        ]);
    }

    private function portfolioItem(?string $agreementUuid = null, string $title = 'Shop logo')
    {
        $this->asSara();

        return $this->postJson(self::API.'/profile/portfolio', ['title' => $title, 'visibility' => 'public', 'rights_confirmed' => true] + ($agreementUuid === null ? [] : ['agreement_uuid' => $agreementUuid]));
    }

    // -------------------------------------------------------------- the terms

    public function test_the_composer_gets_the_three_models_and_the_offer_carries_the_terms(): void
    {
        $this->asSara();
        $this->getJson(self::API.'/rights')->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.default.model', 'transfer')
            ->assertJsonCount(3, 'data.models')->assertJsonPath('data.models.1.model', 'license')->assertJsonPath('data.limits.max_term_months', 120);
        $this->assertNotSame('', $this->getJson(self::API.'/rights')->json('data.models.0.terms.body'));

        $this->postJson(self::API."/projects/{$this->project->uuid}/offers", ['price_minor' => 350000, 'duration_days' => 21, 'details' => str_repeat('x', 40), 'rights' => ['model' => 'nope']])
            ->assertStatus(422)->assertJsonPath('error_code', 'freelance_rights_model_invalid');
        $this->postJson(self::API."/projects/{$this->project->uuid}/offers", ['price_minor' => 350000, 'duration_days' => 21, 'details' => str_repeat('x', 40), 'rights' => ['model' => 'license', 'term_months' => 500]])
            ->assertStatus(422)->assertJsonPath('error_code', 'freelance_rights_term_invalid');

        $this->offer(['model' => 'license', 'commercial' => false, 'exclusive' => true, 'term_months' => 12, 'territory' => 'country', 'portfolio' => true]);
        $stored = FreelanceOffer::query()->firstOrFail()->rights;
        $this->assertSame(['model' => 'license', 'commercial' => false, 'exclusive' => true, 'term_months' => 12, 'territory' => 'country', 'portfolio' => true], $stored);

        // The client sees the terms on the offer, in words.
        $this->asClient();
        $offer = $this->getJson(self::CLIENT."/projects/{$this->project->uuid}/offers")->assertOk()->json('data.0');
        $this->assertSame('license', $offer['rights']['model']);
        $this->assertTrue($offer['rights']['portfolio']);
        $this->assertGreaterThanOrEqual(5, count($offer['rights']['clauses']));
    }

    public function test_an_offer_with_no_terms_gets_the_defaults_and_a_transfer_ignores_the_limits(): void
    {
        $this->offer();
        $this->assertSame(['model' => 'transfer', 'commercial' => true, 'exclusive' => true, 'term_months' => null, 'territory' => 'worldwide', 'portfolio' => false], FreelanceOffer::query()->firstOrFail()->rights);

        // A transfer is the whole thing: whatever limits came with it are dropped.
        $this->asSara();
        $offer = FreelanceOffer::query()->firstOrFail();
        $this->putJson(self::API."/offers/{$offer->uuid}", ['rights' => ['model' => 'transfer', 'commercial' => false, 'term_months' => 6, 'territory' => 'country', 'portfolio' => true]])->assertOk();
        $this->assertSame(['model' => 'transfer', 'commercial' => true, 'exclusive' => true, 'term_months' => null, 'territory' => 'worldwide', 'portfolio' => true], $offer->refresh()->rights);

        // A kept ownership never limits the time and is never exclusive.
        $this->putJson(self::API."/offers/{$offer->uuid}", ['rights' => ['model' => 'retained', 'exclusive' => true, 'term_months' => 6]])->assertOk();
        $this->assertSame(['model' => 'retained', 'commercial' => true, 'exclusive' => false, 'term_months' => null, 'territory' => 'worldwide', 'portfolio' => false], $offer->refresh()->rights);

        // Saying the same again is no change.
        $this->putJson(self::API."/offers/{$offer->uuid}", ['rights' => ['model' => 'retained']])->assertStatus(422)->assertJsonPath('error_code', 'freelance_offer_unchanged');
    }

    public function test_the_agreement_keeps_the_terms_and_the_text_of_the_day(): void
    {
        $this->offer(['model' => 'license', 'commercial' => true, 'exclusive' => false, 'term_months' => 24, 'territory' => 'worldwide']);
        $this->active();

        $this->asClient();
        $agreement = $this->getJson(self::CLIENT."/projects/{$this->project->uuid}/agreement")->assertOk()->json('data');
        $this->assertSame('license', $agreement['rights']['model']);
        $this->assertSame(24, $agreement['rights']['details']['term_months']);
        $this->assertSame('pending', $agreement['rights']['state']);
        $this->assertFalse($agreement['rights']['portfolio']['allowed']);
        $this->assertTrue($agreement['rights']['can']['portfolio_toggle']);
        $before = $agreement['rights']['terms'];
        $this->assertNotSame('', $before['title']);

        // The staff write a new text for the country; the agreement keeps the one it was made with.
        $this->staff('freelance-rights.view', 'freelance-rights.update');
        $this->putJson('/api/admin/v1/freelance-rights/terms', [
            'country_id' => $this->saudi->id, 'model' => 'license', 'title_ar' => 'ترخيص جديد', 'title_en' => 'A new licence',
            'body_ar' => str_repeat('نص جديد للترخيص. ', 5), 'body_en' => str_repeat('A new text for the licence. ', 5),
        ])->assertOk()->assertJsonPath('data.version', 1);

        $this->asClient();
        $this->assertSame($before, $this->getJson(self::CLIENT."/projects/{$this->project->uuid}/agreement")->json('data.rights.terms'));
    }

    public function test_the_staff_keep_the_standard_text_per_country(): void
    {
        $this->staff('freelance-rights.view');
        $list = $this->getJson('/api/admin/v1/freelance-rights/terms')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.custom', false)->json();
        $this->assertNotEmpty($list['countries']);
        // No update right, no write.
        $this->putJson('/api/admin/v1/freelance-rights/terms', ['model' => 'transfer'])->assertForbidden();

        $this->staff('freelance-rights.view', 'freelance-rights.update');
        $body = ['country_id' => $this->saudi->id, 'model' => 'transfer', 'title_ar' => 'نقل الملكية', 'title_en' => 'Transfer', 'body_ar' => str_repeat('ا', 40), 'body_en' => str_repeat('a', 40)];
        $this->putJson('/api/admin/v1/freelance-rights/terms', $body)->assertOk()->assertJsonPath('data.version', 1);
        $this->putJson('/api/admin/v1/freelance-rights/terms', $body)->assertOk()->assertJsonPath('data.version', 2);
        $row = $this->getJson('/api/admin/v1/freelance-rights/terms?country_id='.$this->saudi->id)->assertOk()->assertJsonPath('data.0.custom', true)->assertJsonPath('data.0.version', 2)->json('data.0');
        $this->putJson('/api/admin/v1/freelance-rights/terms', ['model' => 'transfer'] + $body, [])->assertOk();

        $this->deleteJson('/api/admin/v1/freelance-rights/terms/'.$row['id'])->assertOk();
        $this->getJson('/api/admin/v1/freelance-rights/terms?country_id='.$this->saudi->id)->assertJsonPath('data.0.custom', false);
        // A text that is too short is refused.
        $this->putJson('/api/admin/v1/freelance-rights/terms', ['body_en' => 'x'] + $body)->assertStatus(422);
    }

    // -------------------------------------------------------------- delivery and the pass of rights

    public function test_a_delivery_needs_the_freelancers_word_and_keeps_the_outside_material(): void
    {
        $this->offer();
        $one = $this->working();

        $this->deliver($one)->assertStatus(422)->assertJsonPath('error_code', 'freelance_rights_declaration_required');
        $this->deliver($one, ['rights_confirmed' => '0'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_rights_declaration_required');
        $this->assertSame(0, \Modules\Freelance\Models\FreelanceDelivery::query()->count());

        $this->deliver($one, ['rights_confirmed' => '1', 'materials' => [
            ['name' => 'Inter font', 'source' => 'rsms.me/inter', 'license' => 'OFL'], ['name' => '  ', 'source' => 'x'], ['name' => 'Stock photo', 'license' => 'Unsplash'],
        ]])->assertOk();

        $this->asClient();
        $delivery = $this->getJson(self::CLIENT."/projects/{$this->project->uuid}/milestones/{$one}/deliveries")->assertOk()->json('data.0');
        $this->assertNotNull($delivery['rights']['confirmed_at']);
        $this->assertSame(['Inter font', 'Stock photo'], array_column($delivery['rights']['materials'], 'name'));
        $this->assertNull($delivery['rights']['materials'][1]['source']);
    }

    public function test_the_rights_of_a_part_pass_when_it_is_approved_and_paid(): void
    {
        $this->offer(['model' => 'transfer']);
        $one = $this->working();
        $this->deliver($one, ['rights_confirmed' => '1'])->assertOk();

        // Delivered but not approved: the client only looks.
        $this->asClient();
        $this->getJson(self::CLIENT."/projects/{$this->project->uuid}/agreement")->assertJsonPath('data.rights.state', 'pending')->assertJsonPath('data.rights.parts.total', 2)->assertJsonPath('data.rights.parts.granted', 0);
        $this->assertSame(0, $this->mona->notifications()->where('data->event', 'freelance.rights.granted')->count());

        $this->postJson(self::CLIENT."/projects/{$this->project->uuid}/milestones/{$one}/approve")->assertOk()
            ->assertJsonPath('data.rights.state', 'partial')->assertJsonPath('data.rights.parts.granted', 1);
        $this->assertSame(1, $this->mona->notifications()->where('data->event', 'freelance.rights.granted')->count());
        $this->assertSame(1, FreelanceProjectApproval::query()->where('action', 'rights.granted')->count());
        $this->assertSame('transfer', FreelanceProjectApproval::query()->where('action', 'rights.granted')->firstOrFail()->payload['model']);
        // It is in the record both sides read.
        $trail = $this->getJson(self::CLIENT."/projects/{$this->project->uuid}/agreement/trail")->assertOk()->json('data');
        $this->assertContains('rights.granted', array_column($trail, 'action'));
    }

    public function test_an_agreement_made_before_the_switch_has_no_terms_and_works_as_before(): void
    {
        $this->features(['freelance', 'projects', 'bidding', 'chat', 'wallet_payment']);
        $this->offer(['model' => 'license']);
        $this->assertNull(FreelanceOffer::query()->firstOrFail()->rights);

        $one = $this->working();
        $this->deliver($one)->assertOk();
        $this->asClient();
        $this->getJson(self::CLIENT."/projects/{$this->project->uuid}/agreement")->assertOk()->assertJsonPath('data.rights', null);
        $this->postJson(self::CLIENT."/projects/{$this->project->uuid}/milestones/{$one}/approve")->assertOk();
        $this->assertSame(0, $this->mona->notifications()->where('data->event', 'freelance.rights.granted')->count());
        // Nothing to allow or ask where there are no terms.
        $this->putJson(self::CLIENT."/projects/{$this->project->uuid}/agreement/rights/portfolio", ['allowed' => true])->assertStatus(403);
    }

    // -------------------------------------------------------------- the portfolio

    public function test_a_finished_work_goes_in_the_portfolio_only_with_the_clients_leave(): void
    {
        $a = $this->finished(allowed: false);

        $this->asSara();
        $this->getJson(self::API.'/rights/portfolio-sources')->assertOk()->assertJsonCount(0, 'data');
        $this->portfolioItem($a->uuid)->assertStatus(422)->assertJsonPath('error_code', 'freelance_rights_portfolio_not_allowed');

        // Asking is one notice to the client, and not again for a few days.
        $this->postJson(self::API."/projects/{$a->project->uuid}/agreement/rights/portfolio-request")->assertOk()->assertJsonPath('data.rights.portfolio.allowed', false);
        $this->assertSame(1, $this->mona->notifications()->where('data->event', 'freelance.rights.portfolio_requested')->count());
        $this->postJson(self::API."/projects/{$a->project->uuid}/agreement/rights/portfolio-request")->assertStatus(429)->assertJsonPath('error_code', 'freelance_rights_portfolio_asked_recently');
        $this->travel(4)->days();
        $this->postJson(self::API."/projects/{$a->project->uuid}/agreement/rights/portfolio-request")->assertOk();

        // The client allows: the freelancer is told and the work can go in.
        $this->asClient();
        $this->putJson(self::CLIENT."/projects/{$a->project->uuid}/agreement/rights/portfolio", ['allowed' => true])->assertOk()->assertJsonPath('data.rights.portfolio.allowed', true);
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.rights.portfolio_granted')->count());
        $this->asSara();
        $this->getJson(self::API.'/rights/portfolio-sources')->assertOk()->assertJsonPath('data.0.uuid', $a->uuid)->assertJsonPath('data.0.used', false);
        $this->postJson(self::API."/projects/{$a->project->uuid}/agreement/rights/portfolio-request")->assertStatus(409)->assertJsonPath('error_code', 'freelance_rights_portfolio_already_allowed');
        $this->portfolioItem($a->uuid)->assertCreated()->assertJsonPath('data.agreement', $a->uuid)->assertJsonPath('data.withdrawn_by_client', false);
        $this->getJson(self::API.'/rights/portfolio-sources')->assertJsonPath('data.0.used', true);

        $item = FreelancerPortfolioItem::query()->firstOrFail();
        $this->asClient();
        $this->getJson(self::CLIENT."/freelancers/{$this->sara->uuid}/portfolio")->assertOk()->assertJsonCount(1, 'data');

        // The client takes it back: it leaves everyone's view at once, and stays with its owner.
        $this->putJson(self::CLIENT."/projects/{$a->project->uuid}/agreement/rights/portfolio", ['allowed' => false])->assertOk();
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.rights.portfolio_revoked')->count());
        $this->getJson(self::CLIENT."/freelancers/{$this->sara->uuid}/portfolio")->assertOk()->assertJsonCount(0, 'data');
        $this->asSara();
        $this->getJson(self::API.'/profile/portfolio')->assertOk()->assertJsonPath('data.0.withdrawn_by_client', true);
        $this->assertTrue($item->refresh()->withdrawnByClient());
        $this->assertSame(2, FreelanceProjectApproval::query()->whereIn('action', ['rights.portfolio_granted', 'rights.portfolio_revoked'])->count());
    }

    public function test_only_my_own_finished_work_can_be_used_and_only_the_client_decides(): void
    {
        $a = $this->finished(allowed: true);
        $other = Freelancer::query()->create(['name' => 'Lina', 'phone' => '+966500000092', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($other, [], 'freelance_api');
        $this->postJson(self::API.'/profile/portfolio', ['title' => 'Stolen', 'visibility' => 'public', 'rights_confirmed' => true, 'agreement_uuid' => $a->uuid])
            ->assertStatus(422)->assertJsonPath('error_code', 'freelance_rights_portfolio_source_invalid');

        // A work not finished yet can not be shown.
        $a->forceFill(['status' => FreelanceAgreement::ACTIVE])->save();
        $this->portfolioItem($a->uuid)->assertStatus(422)->assertJsonPath('error_code', 'freelance_rights_portfolio_not_finished');

        // The freelancer can not allow it for themselves; the client of another project can not either.
        $this->asSara();
        $this->putJson(self::CLIENT."/projects/{$a->project->uuid}/agreement/rights/portfolio", ['allowed' => true])->assertStatus(401);
        $stranger = User::query()->create(['name' => 'Omar', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($stranger, [], 'user_api');
        $this->putJson(self::CLIENT."/projects/{$a->project->uuid}/agreement/rights/portfolio", ['allowed' => false])->assertNotFound();
    }

    // -------------------------------------------------------------- disputes

    public function test_a_rights_dispute_shows_the_staff_the_terms_both_sides_agreed_to(): void
    {
        $this->offer(['model' => 'license', 'commercial' => false]);
        $one = $this->working();

        $this->asClient();
        $dispute = $this->postJson(self::CLIENT."/projects/{$this->project->uuid}/milestones/{$one}/dispute", [
            'reason' => 'rights', 'details' => 'The logo is the same as one on another site that I found online.',
        ])->assertCreated()->json('data.uuid');

        $this->staff('freelance-disputes.view');
        $case = $this->getJson("/api/admin/v1/freelance-disputes/{$dispute}")->assertOk()->json('data');
        $this->assertSame('license', $case['agreement']['rights']['model']);
        $this->assertFalse($case['agreement']['rights']['details']['commercial']);
        $this->assertSame('rights', $case['reason']);
        $list = $this->getJson('/api/admin/v1/freelance-disputes?category=rights')->assertOk();
        $this->assertSame([], $list->json('data'));
        $this->assertSame('rights', $this->getJson('/api/admin/v1/freelance-disputes')->json('data.0.suggested_category'));
    }

    // -------------------------------------------------------------- a ready-made service

    public function test_a_service_carries_its_terms_into_every_order(): void
    {
        $this->asSara();
        $uuid = $this->postJson(self::API.'/services', [
            'title' => 'A logo for your shop', 'description' => 'I draw a clean logo that fits your shop, with the colours and the files you need for print and the web.',
            'tiers' => [['level' => 'basic', 'name' => 'Starter', 'price_minor' => 20000, 'delivery_days' => 3, 'revisions' => 1, 'features' => ['One idea']]],
            'rights' => ['model' => 'retained', 'commercial' => true, 'territory' => 'worldwide'],
        ])->assertCreated()->assertJsonPath('data.rights.model', 'retained')->json('data.uuid');
        $this->postJson(self::API."/services/{$uuid}/submit")->assertOk();
        $this->staff('freelance-services.view', 'freelance-services.update');
        $this->postJson("/api/admin/v1/freelance-services/{$uuid}/approve")->assertOk();

        // A service made with no terms carries the defaults.
        $this->asSara();
        $plain = $this->postJson(self::API.'/services', [
            'title' => 'A second logo', 'description' => 'I draw a second clean logo that fits your shop, with the colours and the files you need.',
            'tiers' => [['level' => 'basic', 'name' => 'Starter', 'price_minor' => 20000, 'delivery_days' => 3, 'revisions' => 1]],
        ])->assertCreated()->assertJsonPath('data.rights.model', 'transfer')->json('data.uuid');
        $this->assertNotNull($plain);

        $this->asClient();
        $this->getJson(self::CLIENT."/services/{$uuid}")->assertOk()->assertJsonPath('data.rights.model', 'retained');
        $order = $this->postJson(self::CLIENT."/services/{$uuid}/order", ['level' => 'basic'])->assertCreated()->json('data');
        $agreement = FreelanceAgreement::query()->latest('id')->firstOrFail();
        $this->assertSame('retained', $agreement->rights['model']);
        $this->assertNotEmpty($agreement->rights['text']['body']['en']);
        $this->assertNotNull($order['uuid']);
    }

    // -------------------------------------------------------------- reports of a work that is someone else's

    public function test_a_client_or_an_outsider_reports_a_portfolio_work_and_the_staff_decide(): void
    {
        $item = FreelancerPortfolioItem::query()->create(['freelancer_id' => $this->sara->id, 'title' => 'Shop logo', 'visibility' => 'public', 'rights_confirmed_at' => now()]);

        // A client reports it; the same client can not report it twice.
        $this->asClient();
        $this->postJson(self::CLIENT.'/rights/claims', ['subject_type' => 'portfolio_item', 'subject' => $item->uuid, 'details' => 'This is my logo, I drew it in 2021.'])
            ->assertCreated()->assertJsonPath('data.status', 'open');
        $this->postJson(self::CLIENT.'/rights/claims', ['subject_type' => 'portfolio_item', 'subject' => $item->uuid, 'details' => 'This is my logo, I drew it in 2021.'])
            ->assertStatus(409)->assertJsonPath('error_code', 'freelance_rights_claim_exists');
        $this->postJson(self::CLIENT.'/rights/claims', ['subject_type' => 'portfolio_item', 'subject' => (string) \Illuminate\Support\Str::uuid(), 'details' => 'This is my logo, I drew it in 2021.'])->assertNotFound();

        // Someone outside, with no sign-in.
        $this->app['auth']->forgetGuards();
        $this->postJson(self::API.'/public/rights-claims', ['subject' => $item->uuid, 'contact' => 'owner@example.com', 'name' => 'The designer', 'details' => 'I am the designer of this logo and I did not agree to this.'])
            ->assertCreated();
        $this->postJson(self::API.'/public/rights-claims', ['subject' => $item->uuid, 'details' => 'short'])->assertStatus(422);

        $staff = $this->staff('freelance-rights.view', 'freelance-rights.update');
        $list = $this->getJson('/api/admin/v1/freelance-rights/claims')->assertOk()->assertJsonPath('counts.open', 2)->json('data');
        $this->assertCount(2, $list);
        $this->assertEqualsCanonicalizing(['client', 'external'], array_column(array_column($list, 'reporter'), 'type'));

        // Upheld: the work comes down and its owner is told; the signed-in reporter is told how it ended.
        $client = collect($list)->firstWhere('reporter.type', 'client');
        $this->postJson("/api/admin/v1/freelance-rights/claims/{$client['uuid']}/decide", ['decision' => 'uphold', 'note' => 'Shown without the right to.'])->assertOk()->assertJsonPath('data.status', 'upheld');
        $this->assertNotNull($item->refresh()->hidden_by_admin_at);
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.portfolio.hidden')->count());
        $this->assertSame(1, $this->mona->notifications()->where('data->event', 'freelance.rights.claim_closed')->count());
        $this->postJson("/api/admin/v1/freelance-rights/claims/{$client['uuid']}/decide", ['decision' => 'dismiss'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_rights_claim_closed');

        // A work already down can not be reported again.
        $this->asClient();
        $this->postJson(self::CLIENT.'/rights/claims', ['subject_type' => 'portfolio_item', 'subject' => $item->uuid, 'details' => 'Reporting it again after it came down.'])->assertNotFound();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($staff, [], 'admin_api');
        $outside = collect($list)->firstWhere('reporter.type', 'external');
        $this->postJson("/api/admin/v1/freelance-rights/claims/{$outside['uuid']}/decide", ['decision' => 'dismiss'])->assertOk()->assertJsonPath('data.status', 'dismissed');
        $this->assertSame(0, FreelanceRightsClaim::query()->where('status', 'open')->count());
    }

    public function test_before_a_part_is_approved_the_client_only_previews_its_files(): void
    {
        $this->offer();
        $one = $this->working();
        $this->deliver($one, ['rights_confirmed' => '1', 'files' => [\Illuminate\Http\UploadedFile::fake()->image('logo.png', 1600, 1200), \Illuminate\Http\UploadedFile::fake()->createWithContent('src.pdf', "%PDF-1.4\n%%EOF")]])->assertOk();

        $this->asClient();
        $list = $this->getJson(self::CLIENT."/projects/{$this->project->uuid}/milestones/{$one}/deliveries")->assertOk()->json('data.0');
        $this->assertTrue($list['files'][0]['preview_only']);
        $base = self::CLIENT."/projects/{$this->project->uuid}/milestones/{$one}/deliveries/{$list['uuid']}/files/";
        $image = collect($list['files'])->firstWhere('is_image', true);
        $pdf = collect($list['files'])->firstWhere('is_image', false);

        $small = $this->get($base.$image['id']);
        $small->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        [$w, $h] = getimagesizefromstring($small->getContent());
        $this->assertLessThanOrEqual(800, max($w, $h));
        $this->get($base.$pdf['id'], ['Accept' => 'application/json'])->assertStatus(403)->assertJsonPath('error_code', 'freelance_rights_preview_only');

        // The freelancer always has the whole file.
        $this->asSara();
        $this->get(str_replace(self::CLIENT.'/', self::API.'/', $base).$pdf['id'])->assertOk();

        // Approved and paid: the client has it too.
        $this->asClient();
        $this->postJson(self::CLIENT."/projects/{$this->project->uuid}/milestones/{$one}/approve")->assertOk();
        $this->getJson(self::CLIENT."/projects/{$this->project->uuid}/milestones/{$one}/deliveries")->assertJsonPath('data.0.files.0.preview_only', false);
        $this->get($base.$pdf['id'])->assertOk();
    }

    public function test_the_staff_overview_counts_the_agreements_by_model(): void
    {
        $this->finished(allowed: true);
        $this->finished(allowed: false, rights: ['model' => 'license', 'commercial' => true, 'exclusive' => false, 'term_months' => null, 'territory' => 'worldwide', 'portfolio' => false,
            'text' => ['id' => null, 'version' => 0, 'title' => ['ar' => 'ع', 'en' => 'T'], 'body' => ['ar' => 'ن', 'en' => 'B']]]);

        $this->staff('freelance-rights.view');
        $this->getJson('/api/admin/v1/freelance-rights/overview')->assertOk()
            ->assertJsonPath('data.agreements.with_terms', 2)->assertJsonPath('data.agreements.by_model.transfer', 1)->assertJsonPath('data.agreements.by_model.license', 1)
            ->assertJsonPath('data.portfolio.allowed', 1)->assertJsonPath('data.portfolio.wished', 1)->assertJsonPath('data.claims.open', 0);
    }

    public function test_where_the_switch_is_off_nothing_about_rights_is_asked_or_shown(): void
    {
        $this->features(['freelance', 'projects', 'bidding', 'chat', 'wallet_payment', 'services']);
        $this->asSara();
        $this->getJson(self::API.'/rights')->assertOk()->assertJsonPath('data.enabled', false);
        $this->asClient();
        $this->postJson(self::CLIENT.'/rights/claims', ['subject_type' => 'service', 'subject' => (string) \Illuminate\Support\Str::uuid(), 'details' => 'Something is wrong with this.'])->assertStatus(403);
        $this->asSara();
        $this->getJson(self::API.'/rights/portfolio-sources')->assertStatus(403);
    }
}
