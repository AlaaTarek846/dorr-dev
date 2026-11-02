<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Models\FreelanceConsultation;
use Modules\Freelance\Models\FreelanceEarning;
use Modules\Freelance\Models\FreelanceConsultationOffer;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletHoldStatus;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletHold;
use Modules\Wallet\Services\WalletService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FL-L07: paid consultations. A freelancer lists consultations like ready-made services (reviewed by the staff first), each with lengths at a
 * price in weekly hours; the client asks for a free time and the money is held at once; the freelancer accepts; cancelling, confirming,
 * complaining and the clock decide where the money goes. The session itself is a video call (FL-L16), so there is no room.
 */
class FreelanceConsultationsTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1';

    private const CLIENT = '/api/mobile/v1/freelance';

    private Country $saudi;

    private Freelancer $sara;

    private User $mona;

    /** The uuid of the listing {@see setUpOffer()} made. */
    private string $offer = '';

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
        // Monday 2 November 2026, 06:00 UTC.
        $this->travelTo(Carbon::parse('2026-11-02 06:00:00', 'UTC'));
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'wallet_payment', 'consultations', 'chat'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();

        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->mona = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    // -------------------------------------------------------------- helpers

    private function asSara(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->sara->refresh(), [], 'freelance_api');
    }

    private function asMona(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->mona->refresh(), [], 'user_api');
    }

    private function listingBody(string $zone = 'UTC', string $title = 'Brand advice'): array
    {
        return [
            'title' => $title, 'about' => 'A call about your logo, colours and the next step.',
            'options' => [['minutes' => 60, 'price_minor' => 35000], ['minutes' => 30, 'price_minor' => 20000]],
            'weekly' => [['day' => 1, 'from' => '09:00', 'to' => '11:00']], 'timezone' => $zone,
        ];
    }

    /** Sara lists 30 minutes at 200 and 60 at 350, Mondays 09:00-11:00 in the given zone; the staff approve it. */
    private function setUpOffer(string $zone = 'UTC', string $title = 'Brand advice', bool $makeCurrent = true): string
    {
        $this->asSara();
        $uuid = $this->postJson(self::API.'/consultations/offers', $this->listingBody($zone, $title))->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.options.0.minutes', 30)->assertJsonPath('data.options.1.price_minor', 35000)->json('data.uuid');
        $this->postJson(self::API."/consultations/offers/{$uuid}/submit")->assertOk()->assertJsonPath('data.status', 'pending_review');
        $this->staff(['freelance-consultations.view', 'freelance-consultations.update']);
        $this->postJson("/api/admin/v1/freelance-consultation-offers/{$uuid}/approve")->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.live', true);
        if ($makeCurrent) {
            $this->offer = $uuid;
        }

        return $uuid;
    }

    private function topUp(int $minor): void
    {
        $w = app(WalletService::class);
        $w->credit($w->firstOrCreateWallet($this->mona, $this->saudi), $minor, WalletBucket::Withdrawable, WalletTransactionType::Topup);
    }

    private function book(string $at = '2026-11-02T09:00:00Z', int $minutes = 30): array
    {
        $this->asMona();

        return $this->postJson(self::CLIENT.'/consultations', [
            'offer' => $this->offer, 'minutes' => $minutes, 'starts_at' => $at, 'topic' => 'I need help choosing the colours of my brand.',
        ])->assertCreated()->json('data');
    }

    /** The freelancer accepts a request; the client's view of it is returned. */
    private function accept(string $uuid): array
    {
        $this->asSara();
        $this->postJson(self::API."/consultations/{$uuid}/accept")->assertOk()->assertJsonPath('data.status', 'booked');
        $this->asMona();

        return $this->getJson(self::CLIENT."/consultations/{$uuid}")->assertOk()->json('data');
    }

    /** A request that the freelancer accepted at once: the booking is confirmed. */
    private function bookAccepted(string $at = '2026-11-02T09:00:00Z', int $minutes = 30): array
    {
        return $this->accept($this->book($at, $minutes)['uuid']);
    }

    private function heldFor(string $uuid): int
    {
        $c = FreelanceConsultation::query()->where('uuid', $uuid)->firstOrFail();

        return (int) WalletHold::query()->where('reference_type', 'freelance_consultation')->where('reference_id', $c->id)->where('status', WalletHoldStatus::Active)->sum('amount_minor');
    }

    private function saraBalance(): int
    {
        $w = Wallet::query()->where('owner_type', 'freelancer')->where('owner_id', $this->sara->id)->first();

        return $w === null ? 0 : (int) $w->availableMinor(WalletBucket::Withdrawable);
    }

    private function monaAvailable(): int
    {
        $w = Wallet::query()->where('owner_type', 'user')->where('owner_id', $this->mona->id)->first();

        return (int) $w->availableMinor(WalletBucket::Withdrawable);
    }

    private function staff(array $permissions): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'Staff', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'admin_api');
            $admin->givePermissionTo($p);
        }
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    // -------------------------------------------------------------- tests

    public function test_a_listing_needs_review_and_a_length_with_hours_before_clients_see_it(): void
    {
        $this->asSara();
        $this->getJson(self::API.'/consultations/info')->assertOk()->assertJsonPath('data.lengths', [15, 30, 45, 60, 90])->assertJsonPath('data.review', true)->assertJsonPath('data.max_active', 5);
        $this->postJson(self::API.'/consultations/offers', ['title' => 'x'])->assertStatus(422);
        $this->postJson(self::API.'/consultations/offers', ['about' => 'Call me on 0501234567 to talk.'] + $this->listingBody())->assertStatus(422);
        $this->postJson(self::API.'/consultations/offers', ['weekly' => [['day' => 1, 'from' => '09:00', 'to' => '11:00'], ['day' => 1, 'from' => '10:30', 'to' => '12:00']]] + $this->listingBody())
            ->assertStatus(422)->assertJsonPath('error_code', 'freelance_consultation_hours_overlap');

        // A draft is nobody's but hers.
        $uuid = $this->postJson(self::API.'/consultations/offers', $this->listingBody())->assertCreated()->assertJsonPath('data.can.submit', true)->json('data.uuid');
        $this->asMona();
        $this->getJson(self::CLIENT.'/consultation-offers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::CLIENT."/consultation-offers/{$uuid}")->assertStatus(409)->assertJsonPath('error_code', 'freelance_consultation_unavailable');

        // Sent: waiting; the staff look at it, reject it with a reason; she edits and sends it again.
        $this->asSara();
        $this->postJson(self::API."/consultations/offers/{$uuid}/submit")->assertOk()->assertJsonPath('data.status', 'pending_review');
        $this->postJson(self::API."/consultations/offers/{$uuid}/submit")->assertStatus(409)->assertJsonPath('error_code', 'freelance_consultation_listing_state');
        $this->staff(['freelance-consultations.view']);
        $this->getJson('/api/admin/v1/freelance-consultation-offers')->assertOk()->assertJsonPath('data.0.status', 'pending_review')->assertJsonPath('counts.pending_review', 1);
        $this->postJson("/api/admin/v1/freelance-consultation-offers/{$uuid}/reject", ['reason' => 'Add what the call covers'])->assertForbidden();
        $this->staff(['freelance-consultations.view', 'freelance-consultations.update']);
        $this->postJson("/api/admin/v1/freelance-consultation-offers/{$uuid}/reject", ['reason' => 'Add what the call covers'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->asSara();
        $this->getJson(self::API."/consultations/offers/{$uuid}")->assertJsonPath('data.status_reason', 'Add what the call covers')->assertJsonPath('data.can.submit', true);
        $this->putJson(self::API."/consultations/offers/{$uuid}", ['about' => 'The call covers your logo, colours and the next step.'])->assertOk();
        $this->postJson(self::API."/consultations/offers/{$uuid}/submit")->assertOk()->assertJsonPath('data.status', 'pending_review');
        $this->staff(['freelance-consultations.view', 'freelance-consultations.update']);
        $this->postJson("/api/admin/v1/freelance-consultation-offers/{$uuid}/approve")->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertStringContainsString('Add what the call covers', json_encode($this->sara->refresh()->notifications()->get()->toArray(), JSON_UNESCAPED_UNICODE));

        // Published: clients see it, with what it costs and the first free time; she sees what she keeps of each price.
        $this->asMona();
        $this->getJson(self::CLIENT.'/consultation-offers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Brand advice')
            ->assertJsonPath('data.0.freelancer.name', 'Sara')->assertJsonPath('data.0.minutes', [30, 60]);
        $this->asSara();
        $mine = $this->getJson(self::API.'/consultations/offers')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame($mine['options'][0]['price_minor'] - $mine['options'][0]['commission_minor'], $mine['options'][0]['net_minor']);

        // Changing what clients see goes back to the staff; pausing hides it at once; the staff can stop it.
        $this->putJson(self::API."/consultations/offers/{$uuid}", ['title' => 'Brand advice call'])->assertOk()->assertJsonPath('data.status', 'pending_review');
        $this->asMona();
        $this->getJson(self::CLIENT.'/consultation-offers')->assertOk()->assertJsonCount(0, 'data');
        $this->staff(['freelance-consultations.view', 'freelance-consultations.update']);
        $this->postJson("/api/admin/v1/freelance-consultation-offers/{$uuid}/approve")->assertOk();
        $this->postJson("/api/admin/v1/freelance-consultation-offers/{$uuid}/suspend", ['reason' => 'Misleading'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->asSara();
        $this->postJson(self::API."/consultations/offers/{$uuid}/resume")->assertStatus(409);
        $this->staff(['freelance-consultations.view', 'freelance-consultations.update']);
        $this->postJson("/api/admin/v1/freelance-consultation-offers/{$uuid}/unsuspend")->assertOk()->assertJsonPath('data.status', 'paused');
        $this->asSara();
        $this->postJson(self::API."/consultations/offers/{$uuid}/resume")->assertOk()->assertJsonPath('data.status', 'published');
        $this->postJson(self::API."/consultations/offers/{$uuid}/pause")->assertOk()->assertJsonPath('data.status', 'paused');
        $this->deleteJson(self::API."/consultations/offers/{$uuid}")->assertOk();
        $this->assertSame(0, FreelanceConsultationOffer::query()->count());
    }

    public function test_a_freelancer_lists_several_consultations_up_to_the_limit_and_without_review_it_is_published_at_once(): void
    {
        $this->asSara();
        for ($i = 1; $i <= 5; $i++) {
            $uuid = $this->postJson(self::API.'/consultations/offers', $this->listingBody('UTC', "Advice {$i}"))->assertCreated()->json('data.uuid');
            $this->postJson(self::API."/consultations/offers/{$uuid}/submit")->assertOk();
        }
        $extra = $this->postJson(self::API.'/consultations/offers', $this->listingBody('UTC', 'Advice 6'))->assertCreated()->json('data.uuid');
        $this->postJson(self::API."/consultations/offers/{$extra}/submit")->assertStatus(422)->assertJsonPath('error_code', 'freelance_consultation_listing_limit');
        $this->assertSame(6, $this->getJson(self::API.'/consultations/offers')->json('data') ? count($this->getJson(self::API.'/consultations/offers')->json('data')) : 0);

        // The country does not look first: straight to published.
        \Modules\Freelance\Models\FreelancePolicy::query()->create(['country_id' => $this->saudi->id, 'specialty_id' => null, 'data' => ['consultations.require_review' => false], 'status' => true]);
        FreelancePolicies::forget();
        FreelanceConsultationOffer::query()->where('freelancer_id', $this->sara->id)->where('status', 'pending_review')->delete();
        $this->postJson(self::API."/consultations/offers/{$extra}/submit")->assertOk()->assertJsonPath('data.status', 'published');
    }

    public function test_the_client_sees_the_free_times_in_the_weekly_hours_and_after_the_notice(): void
    {
        $this->setUpOffer();
        $this->asMona();
        $offer = $this->getJson(self::CLIENT."/consultation-offers/{$this->offer}")->assertOk()
            ->assertJsonPath('data.title', 'Brand advice')->assertJsonPath('data.options.0.minutes', 30)->json('data');
        $this->assertSame($offer['options'][0]['price_minor'] + $offer['options'][0]['client_fee_minor'], $offer['options'][0]['total_minor']);
        $this->assertSame('2026-11-02T09:00:00+00:00', $offer['next_slot']);

        $slots = $this->getJson(self::CLIENT."/consultation-offers/{$this->offer}/slots?minutes=60")->assertOk()->json('data.slots');
        // Mondays 09:00-11:00: an hour fits at 09:00, 09:30, 10:00.
        $this->assertSame(['2026-11-02T09:00:00+00:00', '2026-11-02T09:30:00+00:00', '2026-11-02T10:00:00+00:00'], array_slice($slots, 0, 3));
        $this->assertSame('2026-11-09T09:00:00+00:00', $slots[3]);

        // Two hours of notice at 08:00: 09:00 is too close.
        $this->travelTo(Carbon::parse('2026-11-02 07:30:00', 'UTC'));
        $slots = $this->getJson(self::CLIENT."/consultation-offers/{$this->offer}/slots?minutes=60")->assertOk()->json('data.slots');
        $this->assertSame('2026-11-02T09:30:00+00:00', $slots[0]);
    }

    public function test_hours_are_read_in_the_freelancers_zone(): void
    {
        $this->setUpOffer('Asia/Riyadh');
        $this->asMona();
        // 09:00 in Riyadh is 06:00 UTC: today it is too close, so next Monday.
        $slots = $this->getJson(self::CLIENT."/consultation-offers/{$this->offer}/slots?minutes=30")->assertOk()->json('data.slots');
        $this->assertSame('2026-11-09T06:00:00+00:00', $slots[0]);
    }

    public function test_booking_is_a_request_that_holds_the_money_and_accepting_takes_the_time(): void
    {
        $this->setUpOffer();
        $this->asMona();
        // No money: nothing requested.
        $this->postJson(self::CLIENT.'/consultations', ['offer' => $this->offer, 'minutes' => 30, 'starts_at' => '2026-11-02T09:00:00Z', 'topic' => 'I need help choosing the colours of my brand.'])
            ->assertStatus(422)->assertJsonPath('error_code', 'freelance_wallet_insufficient');
        $this->assertSame(0, FreelanceConsultation::query()->count());

        $this->topUp(100000);
        $c = $this->book();
        $this->assertSame('pending', $c['status']);
        $this->assertSame('pending', $c['phase']);
        $this->assertSame($c['money']['total_minor'], $this->heldFor($c['uuid']));
        // Withdrawing a request that is not accepted is always free, even close to the start.
        $this->assertTrue($c['can']['cancel']);
        $this->assertTrue($c['can']['cancel_free']);
        $this->assertArrayNotHasKey('room', $c);
        $this->assertSame($this->offer, $c['offer']);
        // Answered by the start at the latest (three hours ahead here).
        $this->assertSame('2026-11-02T09:00:00+00:00', $c['respond_by']);
        // A request alone does not take the time.
        $slots = $this->getJson(self::CLIENT."/consultation-offers/{$this->offer}/slots?minutes=60")->assertOk()->json('data.slots');
        $this->assertSame('2026-11-02T09:00:00+00:00', $slots[0]);

        $this->asSara();
        $this->getJson(self::API.'/consultations?tab=upcoming')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.can.accept', true)->assertJsonPath('data.0.can.decline', true)->assertJsonPath('counts.upcoming', 1);
        $this->getJson(self::API.'/consultations/info')->assertJsonPath('data.stats.pending', 1);

        $c = $this->accept($c['uuid']);
        $this->assertSame('booked', $c['status']);
        $this->assertSame('upcoming', $c['phase']);
        $this->assertSame($c['money']['total_minor'], $this->heldFor($c['uuid']));
        // Three hours ahead: inside the 24 free-cancel hours.
        $this->assertTrue($c['can']['cancel']);
        $this->assertFalse($c['can']['cancel_free']);
        $this->assertArrayNotHasKey('room', $c);
        // No chat is opened for the session: it is a video call.
        $this->assertSame(0, \Modules\Freelance\Models\FreelanceConversation::query()->count());

        // The time is gone, for this length and any that would overlap it.
        $slots = $this->getJson(self::CLIENT."/consultation-offers/{$this->offer}/slots?minutes=60")->assertOk()->json('data.slots');
        $this->assertSame('2026-11-02T09:30:00+00:00', $slots[0]);
        $this->postJson(self::CLIENT.'/consultations', ['offer' => $this->offer, 'minutes' => 60, 'starts_at' => '2026-11-02T09:00:00Z', 'topic' => 'Another question about my brand colours.'])
            ->assertStatus(409)->assertJsonPath('error_code', 'freelance_consultation_slot_taken');

        $this->asSara();
        $this->getJson(self::API.'/consultations?tab=upcoming')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.client.name', 'Mona')
            ->assertJsonPath('counts.upcoming', 1);
    }

    public function test_accepting_one_request_gives_the_others_for_that_time_back_and_the_time_is_then_closed(): void
    {
        $this->setUpOffer();
        $omar = User::query()->create(['name' => 'Omar', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->topUp(100000);
        $w = app(WalletService::class);
        $w->credit($w->firstOrCreateWallet($omar, $this->saudi), 100000, WalletBucket::Withdrawable, WalletTransactionType::Topup);

        $first = $this->book('2026-11-09T09:00:00Z', 60);
        // Both ask for the same time: neither has it yet.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($omar->refresh(), [], 'user_api');
        $second = $this->postJson(self::CLIENT.'/consultations', [
            'offer' => $this->offer, 'minutes' => 30, 'starts_at' => '2026-11-09T09:30:00Z', 'topic' => 'Can you look at my logo with me please?',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
        $this->assertSame($second['money']['total_minor'], $this->heldFor($second['uuid']));

        $this->accept($first['uuid']);

        $row = FreelanceConsultation::query()->where('uuid', $second['uuid'])->firstOrFail();
        $this->assertSame('declined', $row->status);
        $this->assertSame('system', $row->cancelled_by);
        $this->assertSame('taken', $row->cancel_reason);
        $this->assertSame(0, $this->heldFor($second['uuid']));
        $this->assertSame(100000, (int) Wallet::query()->where('owner_type', 'user')->where('owner_id', $omar->id)->first()->availableMinor(WalletBucket::Withdrawable));
        // The first keeps its money held.
        $this->assertGreaterThan(0, $this->heldFor($first['uuid']));

        // And nobody can ask for those hours any more.
        $this->postJson(self::CLIENT.'/consultations', [
            'offer' => $this->offer, 'minutes' => 30, 'starts_at' => '2026-11-09T09:30:00Z', 'topic' => 'Can you look at my logo with me please?',
        ])->assertStatus(409)->assertJsonPath('error_code', 'freelance_consultation_slot_taken');
        $this->asSara();
        $this->getJson(self::API.'/consultations?tab=cancelled')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'declined');
    }

    public function test_the_freelancer_declining_or_not_answering_gives_the_client_everything_back(): void
    {
        $this->setUpOffer();
        $this->topUp(100000);
        $one = $this->book('2026-11-09T09:00:00Z');
        $two = $this->book('2026-11-09T10:00:00Z');
        $this->assertSame(2, FreelanceConsultation::query()->where('status', 'pending')->count());

        $this->asSara();
        $this->postJson(self::API."/consultations/{$one['uuid']}/decline", ['reason' => 'I am busy then'])->assertOk()->assertJsonPath('data.status', 'declined');
        $this->assertSame(0, $this->heldFor($one['uuid']));
        // Declined is final.
        $this->postJson(self::API."/consultations/{$one['uuid']}/accept")->assertStatus(409);

        // The second one is never answered: a day later the clock gives it back.
        $this->travelTo(Carbon::parse('2026-11-03 07:00:00', 'UTC'));
        $this->artisan('freelance:sweep-consultations')->assertSuccessful();
        $row = FreelanceConsultation::query()->where('uuid', $two['uuid'])->firstOrFail();
        $this->assertSame('declined', $row->status);
        $this->assertSame('expired', $row->cancel_reason);
        $this->assertSame(100000, $this->monaAvailable());
        $this->assertSame(0, $this->saraBalance());

        // The time is free again for a new request.
        $this->asMona();
        $this->book('2026-11-09T09:00:00Z');
    }

    public function test_the_client_can_withdraw_a_pending_request_and_a_started_one_cannot_be_accepted(): void
    {
        $this->setUpOffer();
        $this->topUp(100000);
        $c = $this->book('2026-11-02T10:00:00Z');
        $this->postJson(self::CLIENT."/consultations/{$c['uuid']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel.late', false);
        $this->assertSame(100000, $this->monaAvailable());

        $late = $this->book('2026-11-02T10:00:00Z');
        $this->travelTo(Carbon::parse('2026-11-02 10:05:00', 'UTC'));
        $this->asSara();
        $this->postJson(self::API."/consultations/{$late['uuid']}/accept")->assertStatus(409)->assertJsonPath('error_code', 'freelance_consultation_invalid_state');
    }

    public function test_cancelling_early_gives_everything_back_and_late_pays_the_freelancer(): void
    {
        $this->setUpOffer();
        $this->topUp(100000);
        $early = $this->bookAccepted('2026-11-09T09:00:00Z');
        $this->postJson(self::CLIENT."/consultations/{$early['uuid']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel.late', false);
        $this->assertSame(0, $this->heldFor($early['uuid']));
        $this->assertSame(100000, $this->monaAvailable());

        // Within the 24 hours before the start: the freelancer is paid.
        $late = $this->bookAccepted('2026-11-02T10:00:00Z');
        $this->postJson(self::CLIENT."/consultations/{$late['uuid']}/cancel")->assertOk()->assertJsonPath('data.cancel.late', true);
        $this->assertSame(100000 - $late['money']['total_minor'], $this->monaAvailable());
        $row = FreelanceConsultation::query()->where('uuid', $late['uuid'])->firstOrFail();
        $this->assertSame($row->net_minor, $this->saraBalance());
        $this->assertSame(1, FreelanceEarning::query()->where('consultation_id', $row->id)->count());
    }

    public function test_the_freelancer_cancelling_gives_the_client_everything_back(): void
    {
        $this->setUpOffer();
        $this->topUp(100000);
        $c = $this->bookAccepted('2026-11-02T10:00:00Z');
        $this->asSara();
        $this->postJson(self::API."/consultations/{$c['uuid']}/cancel", ['reason' => 'Ill today'])->assertOk()->assertJsonPath('data.cancel.by', 'freelancer');
        $this->assertSame(100000, $this->monaAvailable());
        $this->assertSame(0, $this->saraBalance());
    }

    public function test_after_the_session_confirming_pays_and_the_clock_pays_what_nobody_complained_about(): void
    {
        $this->setUpOffer();
        $this->topUp(100000);
        $one = $this->bookAccepted('2026-11-02T09:00:00Z');
        $two = $this->bookAccepted('2026-11-02T10:00:00Z');
        // Not started yet: nothing to confirm.
        $this->postJson(self::CLIENT."/consultations/{$one['uuid']}/confirm")->assertStatus(409);

        $this->travelTo(Carbon::parse('2026-11-02 10:45:00', 'UTC'));
        $this->postJson(self::CLIENT."/consultations/{$one['uuid']}/confirm")->assertOk()->assertJsonPath('data.status', 'completed');
        $net = FreelanceConsultation::query()->where('uuid', $one['uuid'])->value('net_minor');
        $this->assertSame((int) $net, $this->saraBalance());

        $this->artisan('freelance:sweep-consultations')->assertSuccessful();
        $this->assertSame('booked', FreelanceConsultation::query()->where('uuid', $two['uuid'])->value('status'));
        $this->assertNotNull(FreelanceConsultation::query()->where('uuid', $two['uuid'])->value('ended_told_at'));

        $this->travelTo(Carbon::parse('2026-11-03 11:00:00', 'UTC'));
        $this->artisan('freelance:sweep-consultations')->assertSuccessful();
        $this->assertSame('completed', FreelanceConsultation::query()->where('uuid', $two['uuid'])->value('status'));
        $this->assertSame(2 * (int) $net, $this->saraBalance());
    }

    public function test_a_complaint_waits_for_the_staff_who_refund_or_pay(): void
    {
        $this->setUpOffer();
        $this->topUp(100000);
        $c = $this->bookAccepted('2026-11-02T09:00:00Z');
        $this->travelTo(Carbon::parse('2026-11-02 09:40:00', 'UTC'));
        $this->postJson(self::CLIENT."/consultations/{$c['uuid']}/complain", ['text' => 'The freelancer never came to the call.'])
            ->assertOk()->assertJsonPath('data.status', 'disputed');

        // The clock leaves it alone.
        $this->travelTo(Carbon::parse('2026-11-04 09:40:00', 'UTC'));
        $this->artisan('freelance:sweep-consultations')->assertSuccessful();
        $this->assertSame('disputed', FreelanceConsultation::query()->where('uuid', $c['uuid'])->value('status'));

        $this->staff(['freelance-consultations.view']);
        $this->getJson('/api/admin/v1/freelance-consultations')->assertOk()->assertJsonPath('data.0.status', 'disputed')->assertJsonPath('counts.disputed', 1);
        $this->postJson("/api/admin/v1/freelance-consultations/{$c['uuid']}/resolve", ['decision' => 'refund'])->assertForbidden();

        $this->staff(['freelance-consultations.view', 'freelance-consultations.update']);
        $this->postJson("/api/admin/v1/freelance-consultations/{$c['uuid']}/resolve", ['decision' => 'refund', 'note' => 'No sign of the freelancer in the call.'])
            ->assertOk()->assertJsonPath('data.status', 'refunded')->assertJsonPath('data.resolution.decision', 'refund');
        $this->assertSame(100000, $this->monaAvailable());
        $this->assertSame(0, $this->saraBalance());
    }

    public function test_two_listings_of_one_freelancer_share_her_time(): void
    {
        $first = $this->setUpOffer('UTC', 'Brand advice');
        $second = $this->setUpOffer('UTC', 'Logo review', false);
        $this->topUp(200000);
        $c = $this->book('2026-11-09T09:00:00Z');
        $this->accept($c['uuid']);

        // The other listing no longer offers the accepted time.
        $this->asMona();
        $slots = $this->getJson(self::CLIENT."/consultation-offers/{$second}/slots?minutes=60")->assertOk()->json('data.slots');
        $this->assertNotContains('2026-11-09T09:00:00+00:00', $slots);
        // The accepted half hour ends at 09:30, so an hour can start then.
        $this->assertContains('2026-11-09T09:30:00+00:00', $slots);
        $this->postJson(self::CLIENT.'/consultations', ['offer' => $second, 'minutes' => 30, 'starts_at' => '2026-11-09T09:00:00Z', 'topic' => 'Please look at my new logo with me.'])
            ->assertStatus(409)->assertJsonPath('error_code', 'freelance_consultation_slot_taken');
        $this->getJson(self::CLIENT.'/consultation-offers')->assertOk()->assertJsonCount(2, 'data');

        // A listing with a session ahead is paused, not deleted.
        $this->asSara();
        $this->deleteJson(self::API."/consultations/offers/{$first}")->assertStatus(409)->assertJsonPath('error_code', 'freelance_consultation_has_bookings');
        $this->deleteJson(self::API."/consultations/offers/{$second}")->assertOk();
    }

    public function test_each_side_is_told_the_time_on_their_own_device_clock(): void
    {
        $this->setUpOffer('Asia/Riyadh');
        $this->topUp(100000);
        // The apps send their phone's zone with every call; it is kept for both.
        $this->asMona();
        $this->withHeader('X-Timezone', 'America/New_York')->getJson(self::CLIENT.'/consultations')->assertOk();
        $this->withoutHeader('X-Timezone');
        $this->asSara();
        $this->withHeader('X-Timezone', 'Asia/Dubai')->getJson(self::API.'/consultations')->assertOk();
        $this->withoutHeader('X-Timezone');
        $this->assertSame('America/New_York', $this->mona->refresh()->timezone);
        $this->assertSame('Asia/Dubai', $this->sara->refresh()->timezone);

        // 06:00 UTC next Monday is 10:00 in Dubai and 01:00 in New York.
        $c = $this->book('2026-11-09T06:00:00Z');
        $this->asSara();
        $this->assertStringContainsString('2026-11-09 10:00', json_encode($this->sara->refresh()->notifications()->get()->toArray(), JSON_UNESCAPED_UNICODE));
        $this->postJson(self::API."/consultations/{$c['uuid']}/accept")->assertOk();
        $this->assertStringContainsString('2026-11-09 01:00', json_encode($this->mona->refresh()->notifications()->get()->toArray(), JSON_UNESCAPED_UNICODE));
    }

    public function test_the_whole_cycle_up_to_the_session_starting(): void
    {
        $this->setUpOffer();
        $this->topUp(100000);

        // 1. The client asks; the money is held and the freelancer is told.
        $c = $this->book('2026-11-02T09:00:00Z', 30);
        $this->assertSame('pending', $c['status']);
        $this->assertSame(0, \Modules\Freelance\Models\FreelanceConversation::query()->count());

        // 2. The freelancer accepts: the time is theirs, in the client's calendar, money still held.
        $c = $this->accept($c['uuid']);
        $this->assertSame('booked', $c['status']);
        $this->assertSame('upcoming', $c['phase']);
        $this->assertNotNull(FreelanceConsultation::query()->where('uuid', $c['uuid'])->value('accepted_at'));
        $this->assertGreaterThan(0, $this->heldFor($c['uuid']));

        // 3. Thirty minutes before: both sides are reminded, once.
        $this->travelTo(Carbon::parse('2026-11-02 08:35:00', 'UTC'));
        $this->artisan('freelance:sweep-consultations')->assertSuccessful();
        $this->assertNotNull(FreelanceConsultation::query()->where('uuid', $c['uuid'])->value('reminded_at'));

        // 4. The start comes: the session is live; nothing is paid yet and no chat exists.
        $this->travelTo(Carbon::parse('2026-11-02 09:00:00', 'UTC'));
        $this->artisan('freelance:sweep-consultations')->assertSuccessful();
        $this->asMona();
        $live = $this->getJson(self::CLIENT."/consultations/{$c['uuid']}")->assertOk()->json('data');
        $this->assertSame('booked', $live['status']);
        $this->assertSame('live', $live['phase']);
        $this->assertGreaterThan(0, $this->heldFor($c['uuid']));
        $this->assertSame(0, $this->saraBalance());
        $this->assertSame(0, \Modules\Freelance\Models\FreelanceConversation::query()->count());
        $this->asSara();
        $this->getJson(self::API.'/consultations?tab=upcoming')->assertOk()->assertJsonPath('data.0.phase', 'live');
    }

    public function test_the_client_rates_a_finished_consultation_once_and_it_shows_on_the_freelancers_profile(): void
    {
        $this->setUpOffer();
        $this->topUp(100000);
        $c = $this->bookAccepted('2026-11-02T09:00:00Z');
        $this->assertFalse($c['review']['can_rate']);

        // Live but not paid yet: no rating.
        $this->travelTo(Carbon::parse('2026-11-02 09:40:00', 'UTC'));
        $this->postJson(self::CLIENT."/consultations/{$c['uuid']}/review", ['stars' => 5])
            ->assertStatus(409)->assertJsonPath('error_code', 'freelance_consultation_review_closed');

        // It went well: paid, and now it can be rated.
        $done = $this->postJson(self::CLIENT."/consultations/{$c['uuid']}/confirm")->assertOk()->json('data');
        $this->assertTrue($done['review']['can_rate']);
        $this->assertSame('2026-11-16T09:40:00+00:00', $done['review']['rate_until']);
        $this->postJson(self::CLIENT."/consultations/{$c['uuid']}/review", ['stars' => 6])->assertStatus(422);
        $this->postJson(self::CLIENT."/consultations/{$c['uuid']}/review", ['stars' => 4, 'comment' => '<b>Clear</b> and useful advice.'])
            ->assertOk()->assertJsonPath('data.review.can_rate', false)->assertJsonPath('data.review.rating.stars', 4)
            ->assertJsonPath('data.review.rating.comment', 'Clear and useful advice.');
        $this->postJson(self::CLIENT."/consultations/{$c['uuid']}/review", ['stars' => 5])
            ->assertStatus(409)->assertJsonPath('error_code', 'freelance_consultation_review_exists');

        // The freelancer is told and reads it on the consultation and in their ratings, at once.
        $this->assertStringContainsString('consultation_rated', json_encode($this->sara->refresh()->notifications()->get()->toArray()));
        $this->asSara();
        $this->getJson(self::API."/consultations/{$c['uuid']}")->assertOk()->assertJsonPath('data.review.rating.stars', 4)->assertJsonPath('data.review.can_rate', false);
        $this->getJson(self::API.'/reviews')->assertOk()->assertJsonPath('data.reviews_count', 1)
            ->assertJsonPath('data.latest.0.kind', 'consultation')->assertJsonPath('data.latest.0.project', 'Brand advice')->assertJsonPath('data.latest.0.stars', 4);

        // A client reading the freelancer before booking sees it too.
        $this->asMona();
        $this->getJson(self::CLIENT."/consultation-offers/{$this->offer}")->assertOk()
            ->assertJsonPath('data.freelancer.reviews_count', 1)->assertJsonPath('data.freelancer.reviews.0.comment', 'Clear and useful advice.');

        // After the rating days nothing more can be rated.
        $other = $this->bookAccepted('2026-11-09T09:00:00Z');
        $this->travelTo(Carbon::parse('2026-11-09 09:40:00', 'UTC'));
        $this->postJson(self::CLIENT."/consultations/{$other['uuid']}/confirm")->assertOk();
        $this->travelTo(Carbon::parse('2026-11-24 09:40:00', 'UTC'));
        $this->getJson(self::CLIENT."/consultations/{$other['uuid']}")->assertOk()->assertJsonPath('data.review.can_rate', false);
        $this->postJson(self::CLIENT."/consultations/{$other['uuid']}/review", ['stars' => 5])->assertStatus(409);
    }

    public function test_the_feature_switch_closes_booking_but_keeps_my_list(): void
    {
        $this->setUpOffer();
        $this->topUp(100000);
        $this->book();
        FreelanceFeatureRule::query()->where('feature', 'consultations')->update(['enabled' => false]);
        FreelanceFeatures::forget();
        $this->asMona();
        $this->getJson(self::CLIENT."/consultation-offers/{$this->offer}")->assertForbidden();
        $this->getJson(self::CLIENT.'/consultations')->assertOk()->assertJsonCount(1, 'data');
    }
}
