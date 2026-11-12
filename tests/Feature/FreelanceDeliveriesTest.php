<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FL-601 / FL-602 / FL-603 / FL-604: a delivery is a numbered version with its files, seen and downloaded only by the two people of the
 * agreement; the changes asked are written on the version they are about; the client's checklist is set before the work and ticked while
 * reviewing.
 */
class FreelanceDeliveriesTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '/api/mobile/v1/freelance/projects';

    private const FREELANCER = '/api/freelance/v1/projects';

    private Country $saudi;

    private User $client;

    private FreelanceProject $project;

    private Freelancer $sara;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
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
        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/offers", [
            'price_minor' => 350000, 'duration_days' => 21, 'details' => 'I built three booking sites like this; I will deliver in three steps with a demo each week.',
        ])->assertCreated();
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

    /** Sara's agreement split in two and active; returns the parts' uuids. */
    private function activeInTwo(): array
    {
        $this->asClient();
        $offer = FreelanceOffer::query()->where('freelancer_id', $this->sara->id)->firstOrFail();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers")->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$offer->uuid}/choose")->assertOk();
        $this->putJson(self::CLIENT."/{$this->project->uuid}/agreement/milestones", ['milestones' => [['title' => 'Part 1', 'amount_minor' => 100000], ['title' => 'Part 2', 'amount_minor' => 250000]]])->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/send")->assertOk();
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/agreement/approve")->assertOk();

        return FreelanceMilestone::query()->orderBy('position')->pluck('uuid')->all();
    }

    private function fund(string $uuid): void
    {
        $m = FreelanceMilestone::query()->where('uuid', $uuid)->firstOrFail();
        $w = app(\Modules\Wallet\Services\WalletService::class);
        $w->credit($w->firstOrCreateWallet($this->client, $this->saudi), $m->amount_minor + $m->client_fee_minor, \Modules\Wallet\Enums\WalletBucket::Withdrawable, \Modules\Wallet\Enums\WalletTransactionType::Topup);
        app(\Modules\Freelance\Services\Projects\MilestonePayments::class)->fund($m->load('agreement'), $this->client);
    }

    /** The first part funded and started; returns its uuid. */
    private function working(): string
    {
        [$one] = $this->activeInTwo();
        $this->fund($one);
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$one}/start")->assertOk();

        return $one;
    }

    private function deliver(string $one, array $extra = [])
    {
        $this->asFreelancer($this->sara);

        return $this->post(self::FREELANCER."/{$this->project->uuid}/milestones/{$one}/deliver", ['note' => 'Here is the first version.'] + $extra, ['Accept' => 'application/json']);
    }

    public function test_a_delivery_keeps_its_files_and_a_new_one_is_a_new_version(): void
    {
        $one = $this->working();
        $this->deliver($one, ['files' => [UploadedFile::fake()->image('design.png'), UploadedFile::fake()->createWithContent('brief.pdf', "%PDF-1.4\n%%EOF")]])->assertOk();

        $this->asClient();
        $list = $this->getJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/deliveries")->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame(1, $list[0]['number']);
        $this->assertCount(2, $list[0]['files']);
        $this->assertTrue($list[0]['files'][0]['is_image']);
        $this->assertFalse($list[0]['files'][1]['is_image']);

        // An image shows in place, a pdf is a download; the type is never guessed.
        $image = $this->get('/api/mobile/v1/freelance/'.$list[0]['files'][0]['path'])->assertOk();
        $this->assertStringContainsString('inline', (string) $image->headers->get('content-disposition'));
        $this->assertSame('nosniff', $image->headers->get('x-content-type-options'));
        $pdf = $this->get('/api/mobile/v1/freelance/'.$list[0]['files'][1]['path'])->assertOk();
        $this->assertStringContainsString('attachment', (string) $pdf->headers->get('content-disposition'));

        // The changes asked are written on the version, and the next delivery is version 2.
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/revision", ['reason' => 'Make the header bigger please.'])->assertOk();
        $this->deliver($one, ['note' => 'Second version, header is bigger.'])->assertOk();
        $this->asClient();
        $list = $this->getJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/deliveries")->assertOk()->json('data');
        $this->assertSame([2, 1], array_column($list, 'number'));
        $this->assertSame('Make the header bigger please.', $list[1]['revision_reason']);
        $this->assertNull($list[0]['revision_reason']);
        $this->assertSame(2, FreelanceMilestone::query()->where('uuid', $one)->firstOrFail()->revisions_used + 1);
    }

    public function test_only_the_two_people_of_the_agreement_see_a_delivery_and_the_rules_hold(): void
    {
        $one = $this->working();
        $this->deliver($one, ['files' => [UploadedFile::fake()->image('design.png')]])->assertOk();

        // Too many files, or a type that may not be delivered.
        $this->deliver($one, ['files' => array_map(fn ($i) => UploadedFile::fake()->image("a{$i}.png"), range(1, 11))])->assertStatus(422);
        $this->deliver($one, ['files' => [UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload')]])->assertStatus(422);

        // FL-1205: a program called a pdf is refused whatever its name says, and nothing is kept.
        $pe = UploadedFile::fake()->createWithContent('brief.pdf', 'MZ'.str_repeat(" ", 0x3A).pack('V', 0x80).str_repeat(" ", 0x40)."PE  ".str_repeat(" ", 100));
        $this->deliver($one, ['files' => [$pe]])->assertStatus(422);

        $stranger = Freelancer::query()->create(['name' => 'Lina', 'phone' => '+966500000092', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->asFreelancer($stranger);
        $this->getJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$one}/deliveries")->assertNotFound();

        $this->asFreelancer($this->sara);
        $mine = $this->getJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$one}/deliveries")->assertOk()->json('data');
        $this->assertCount(1, $mine);
        $this->get('/api/freelance/v1/'.$mine[0]['files'][0]['path'])->assertOk();
        // The summary on the agreement counts the versions.
        $this->getJson(self::FREELANCER."/{$this->project->uuid}/agreement")->assertOk()->assertJsonPath('data.milestones.0.deliveries_count', 1);
    }

    public function test_the_checklist_is_set_before_the_work_ticked_while_reviewing_and_does_not_decide(): void
    {
        [$one] = $this->activeInTwo();
        $this->asClient();
        $url = self::CLIENT."/{$this->project->uuid}/milestones/{$one}/checklist";
        $this->putJson($url, ['items' => ['Works on phones', 'Arabic and English', 'Works on phones']])->assertOk()->assertJsonCount(2, 'data');

        // The freelancer sees it on the part but cannot change it.
        $this->asFreelancer($this->sara);
        $this->getJson(self::FREELANCER."/{$this->project->uuid}/agreement")->assertOk()->assertJsonPath('data.milestones.0.checklist.0.text', 'Works on phones');
        // (no route for it on the freelancer side)
        $this->putJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$one}/checklist", ['items' => ['x']])->assertNotFound();

        $this->fund($one);
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$one}/start")->assertOk();
        // Once the work has started, the list is locked (a scope change goes through a change request).
        $this->asClient();
        $this->putJson($url, ['items' => ['Something new']])->assertStatus(409);

        // Nothing is ticked before a delivery is there to look at.
        $item = $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->json('data.milestones.0.checklist.0.uuid');
        $this->postJson($url."/{$item}/check")->assertStatus(409);

        $this->deliver($one)->assertOk();
        $this->asClient();
        $this->postJson($url."/{$item}/check")->assertOk()->assertJsonPath('data.0.checked', true);
        // Approving does not need every line ticked: the client decides.
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/approve")->assertOk();
    }

    public function test_sending_a_delivery_back_clears_the_ticks_for_the_next_round(): void
    {
        [$one] = $this->activeInTwo();
        $this->asClient();
        $url = self::CLIENT."/{$this->project->uuid}/milestones/{$one}/checklist";
        $this->putJson($url, ['items' => ['One', 'Two']])->assertOk();
        $this->fund($one);
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$one}/start")->assertOk();
        $this->deliver($one)->assertOk();

        $this->asClient();
        $item = $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->json('data.milestones.0.checklist.0.uuid');
        $this->postJson($url."/{$item}/check")->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/revision", ['reason' => 'The second line is not done.'])->assertOk();
        $this->assertSame([false, false], array_column($this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->json('data.milestones.0.checklist'), 'checked'));
    }
}
