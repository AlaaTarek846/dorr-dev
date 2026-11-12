<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatCalendarItem;
use Modules\Freelance\Models\FreelanceConversation;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceInterview;
use Modules\Freelance\Models\FreelanceMessage;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Chat\FreelanceChatService;
use Modules\Freelance\Services\Chat\FreelanceInterviews;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\User\Models\User;
use Tests\TestCase;

/** FL-310: a short interview arranged inside the chat room. */
class FreelanceInterviewsTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Freelancer $freelancer;

    private FreelanceConversation $room;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id,
        ]);
        foreach (['freelance', 'chat'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();

        $this->client = User::query()->create(['name' => 'Mona Ali', 'phone' => '+966500000001', 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->freelancer = Freelancer::query()->create(['name' => 'Sara Hassan', 'phone' => '+966500000077', 'country_id' => $saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->room = app(FreelanceChatService::class)->open($this->client, $this->freelancer, 'project', 42);
    }

    private function asClient(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->client, [], 'user_api');
    }

    private function asFreelancer(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->freelancer, [], 'freelance_api');
    }

    private function clientUrl(string $tail = ''): string
    {
        return '/api/mobile/v1/freelance/chat/conversations/'.$this->room->uuid.'/interviews'.$tail;
    }

    private function freelancerUrl(string $tail = ''): string
    {
        return '/api/freelance/v1/chat/conversations/'.$this->room->uuid.'/interviews'.$tail;
    }

    public function test_one_side_proposes_the_other_confirms_and_the_client_calendar_gets_it(): void
    {
        $this->asClient();
        $when = now()->addDays(2)->toIso8601String();
        $this->postJson($this->clientUrl(), ['starts_at' => $when, 'duration_minutes' => 20, 'note' => 'About the scope'])->assertCreated();
        $interview = FreelanceInterview::query()->firstOrFail();
        $this->assertSame('proposed', $interview->status);
        $this->assertSame('client', $interview->proposed_by);

        // The proposer can not answer their own proposal.
        $this->postJson($this->clientUrl("/{$interview->uuid}/confirm"))->assertForbidden();

        $this->asFreelancer();
        $this->getJson($this->freelancerUrl())->assertOk()->assertJsonPath('data.0.status', 'proposed');
        $this->postJson($this->freelancerUrl("/{$interview->uuid}/confirm"))->assertOk()->assertJsonPath('data.0.status', 'confirmed');

        $interview->refresh();
        $this->assertNotNull($interview->calendar_item_id);
        $this->assertSame(1, ChatCalendarItem::query()->whereKey($interview->calendar_item_id)->count());
        // Written in the room as system lines, one for each step.
        $this->assertSame(2, FreelanceMessage::query()->where('conversation_id', $this->room->id)->where('kind', 'system')->count());
    }

    public function test_declining_cancelling_and_a_new_proposal_take_the_calendar_item_away(): void
    {
        $service = app(FreelanceInterviews::class);
        $first = $service->propose($this->room, $this->freelancer, now()->addDay(), 15, null);
        $service->respond($this->room, $this->client, $first->uuid, 'confirm');
        $this->assertNotNull($first->refresh()->calendar_item_id);

        // A new proposal replaces the open one.
        $second = $service->propose($this->room, $this->client, now()->addDays(3), 30, null);
        $this->assertSame('superseded', $first->refresh()->status);
        $this->assertNull($first->calendar_item_id);
        $this->assertSame(0, ChatCalendarItem::query()->count());

        $service->respond($this->room, $this->freelancer, $second->uuid, 'decline');
        $this->assertSame('declined', $second->refresh()->status);

        $third = $service->propose($this->room, $this->freelancer, now()->addDays(4), 15, null);
        $service->respond($this->room, $this->client, $third->uuid, 'confirm');
        $service->respond($this->room, $this->client, $third->uuid, 'cancel');
        $this->assertSame('cancelled', $third->refresh()->status);
        $this->assertSame(0, ChatCalendarItem::query()->count());
    }

    public function test_the_time_must_be_ahead_and_a_stranger_sees_nothing(): void
    {
        $this->asClient();
        $this->postJson($this->clientUrl(), ['starts_at' => now()->subHour()->toIso8601String()])->assertStatus(422);
        $this->postJson($this->clientUrl(), ['starts_at' => now()->addDays(90)->toIso8601String()])->assertStatus(422);
        $this->postJson($this->clientUrl(), ['starts_at' => now()->addDay()->toIso8601String(), 'duration_minutes' => 7])->assertStatus(422);

        $other = Freelancer::query()->create(['name' => 'Other', 'phone' => '+966500000078', 'country_id' => $this->freelancer->country_id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($other, [], 'freelance_api');
        $this->getJson($this->freelancerUrl())->assertNotFound();
    }

    public function test_reminders_go_out_once_before_it_starts(): void
    {
        $service = app(FreelanceInterviews::class);
        $soon = $service->propose($this->room, $this->client, now()->addMinutes(10), 15, null);
        $service->respond($this->room, $this->freelancer, $soon->uuid, 'confirm');

        $this->assertSame(1, $service->remindDue());
        $this->assertSame(0, $service->remindDue());
        $this->assertNotNull($soon->refresh()->reminded_at);
    }
}
