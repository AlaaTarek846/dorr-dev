<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceConversation;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Chat\ContactGuard;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FL-308: the talk about an offer before the agreement — the client opens the room from a live offer, both write in it, and no
 * phone number, email, link or handle gets through until the agreement; the room goes read-only when the offer or the project ends.
 */
class FreelanceOfferChatTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '/api/mobile/v1/freelance';

    private const FREELANCER = '/api/freelance/v1';

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
        foreach (['freelance', 'projects', 'bidding', 'chat'] as $feature) {
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
        $this->postJson(self::FREELANCER."/projects/{$this->project->uuid}/offers", [
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

    private function open(Freelancer $f): string
    {
        $this->asClient();

        return $this->postJson(self::CLIENT."/projects/{$this->project->uuid}/offers/{$this->offer($f)->uuid}/chat")->assertOk()->json('data.uuid');
    }

    public function test_the_guard_catches_ways_to_reach_someone_and_leaves_normal_talk_alone(): void
    {
        $g = new ContactGuard;
        foreach ([
            'call me on 0501234567' => 'phone', 'رقمي ٠٥٠١٢٣٤٥٦٧' => 'phone', 'ring +966 50 123 4567' => 'phone', '0 5 0 1 2 3 4 5 6 7' => 'phone', '050-123-4567' => 'phone',
            'mail me sara@example.com' => 'email', 'sara (at) example (dot) com' => 'email',
            'see https://example.org/me' => 'link', 'www.mysite.sa' => 'link', 'wa.me/966501234567' => 'link', 'my site is portfolio.com' => 'link',
            'add me @sara_dev' => 'handle',
        ] as $text => $kind) {
            $this->assertSame($kind, $g->find($text), $text);
        }
        foreach ([
            'I can finish in 21 days for 3500 SAR', 'Delivery on 2026-11-20 or 20/11/2026', 'Budget 1,500,000 is too high', 'Three steps, a demo each week.', 'يوم ٢١ و السعر ٣٥٠٠ ريال',
        ] as $text) {
            $this->assertNull($g->find($text), $text);
        }
    }

    public function test_the_client_opens_the_room_and_both_talk_but_contact_details_are_refused(): void
    {
        $room = $this->open($this->sara);
        // Opening again finds the same room.
        $this->assertSame($room, $this->postJson(self::CLIENT."/projects/{$this->project->uuid}/offers/{$this->offer($this->sara)->uuid}/chat")->json('data.uuid'));
        $this->assertSame(1, FreelanceConversation::query()->count());
        $this->getJson(self::CLIENT."/chat/conversations/{$room}")->assertJsonPath('data.guarded', true)->assertJsonPath('data.project.title', 'A booking website')->assertJsonPath('data.can_write', true);

        $this->postJson(self::CLIENT."/chat/conversations/{$room}/messages", ['body' => 'Can you start next week?'])->assertCreated();
        $this->postJson(self::CLIENT."/chat/conversations/{$room}/messages", ['body' => 'call me 0501234567'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_chat_contact_blocked');
        $this->assertSame(1, \Modules\Freelance\Models\FreelanceMessage::query()->count());

        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/chat/conversations/{$room}/messages", ['body' => 'add me on @sara_dev'])->assertStatus(422);
        $this->postJson(self::FREELANCER."/chat/conversations/{$room}/messages", ['body' => 'Yes, from Sunday.'])->assertCreated();

        // Each offer screen shows the room.
        $this->getJson(self::FREELANCER."/projects/{$this->project->uuid}/my-offer")->assertJsonPath('data.offer.chat.uuid', $room)->assertJsonPath('data.offer.chat.unread', 1);
        $this->asClient();
        $this->getJson(self::CLIENT."/projects/{$this->project->uuid}/offers/{$this->offer($this->sara)->uuid}")->assertJsonPath('data.chat.uuid', $room)->assertJsonPath('data.chat.unread', 1)->assertJsonPath('data.can.chat', true);
        // The other freelancer has none.
        $this->getJson(self::CLIENT."/projects/{$this->project->uuid}/offers/{$this->offer($this->lina)->uuid}")->assertJsonPath('data.chat', null);
    }

    public function test_only_the_projects_client_opens_a_room_and_a_freelancer_never_does(): void
    {
        $offer = $this->offer($this->sara)->uuid;
        $other = User::query()->create(['name' => 'Omar', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($other, [], 'user_api');
        $this->postJson(self::CLIENT."/projects/{$this->project->uuid}/offers/{$offer}/chat")->assertNotFound();
        $this->asFreelancer($this->sara);
        $this->postJson(self::CLIENT."/projects/{$this->project->uuid}/offers/{$offer}/chat")->assertUnauthorized();
        $this->assertSame(0, FreelanceConversation::query()->count());
    }

    public function test_the_room_goes_read_only_when_the_offer_or_the_project_ends(): void
    {
        $sara = $this->open($this->sara);
        $lina = $this->open($this->lina);

        // Turned down: Lina's room stops, Sara's goes on.
        $this->postJson(self::CLIENT."/projects/{$this->project->uuid}/offers/{$this->offer($this->lina)->uuid}/reject")->assertOk();
        $this->postJson(self::CLIENT."/chat/conversations/{$lina}/messages", ['body' => 'Sorry'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_chat_closed');
        $this->postJson(self::CLIENT."/projects/{$this->project->uuid}/offers/{$this->offer($this->lina)->uuid}/chat")->assertStatus(409)->assertJsonPath('error_code', 'freelance_chat_not_available');
        $this->postJson(self::CLIENT."/chat/conversations/{$sara}/messages", ['body' => 'Still with me?'])->assertCreated();

        // Chosen: the talk with the chosen one goes on, still kept to Dorr until the agreement.
        $this->postJson(self::CLIENT."/projects/{$this->project->uuid}/offers/{$this->offer($this->sara)->uuid}/shortlist")->assertOk();
        $this->postJson(self::CLIENT."/projects/{$this->project->uuid}/offers/{$this->offer($this->sara)->uuid}/choose")->assertOk();
        $this->postJson(self::CLIENT."/chat/conversations/{$sara}/messages", ['body' => 'Great, welcome'])->assertCreated();
        $this->postJson(self::CLIENT."/chat/conversations/{$sara}/messages", ['body' => 'my number 0501234567'])->assertStatus(422);

        // Cancelled: nobody writes any more.
        $this->postJson(self::CLIENT."/projects/{$this->project->uuid}/cancel", [])->assertOk();
        $this->postJson(self::CLIENT."/chat/conversations/{$sara}/messages", ['body' => 'Bye'])->assertStatus(409);
    }

    public function test_a_withdrawn_offer_closes_its_room_and_without_the_chat_switch_no_room_opens(): void
    {
        $room = $this->open($this->lina);
        $this->asFreelancer($this->lina);
        $this->postJson(self::FREELANCER.'/offers/'.$this->offer($this->lina)->uuid.'/withdraw')->assertOk();
        $this->postJson(self::FREELANCER."/chat/conversations/{$room}/messages", ['body' => 'Hello'])->assertStatus(409);

        FreelanceFeatureRule::query()->where('feature', 'chat')->update(['enabled' => false]);
        FreelanceFeatures::forget();
        $this->asClient();
        $this->postJson(self::CLIENT."/projects/{$this->project->uuid}/offers/{$this->offer($this->sara)->uuid}/chat")->assertStatus(403);
    }
}
