<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\ServiceCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Moving\Enums\MovingStatus;
use Modules\Moving\Models\MovingRequest;
use Modules\Moving\Services\Offers\MovingOfferService;
use Modules\Moving\Services\Payment\MovingPayments;
use Modules\Moving\Services\Payment\MovingSettlement;
use Modules\Moving\Services\Policy\MovingPolicies;
use Modules\Moving\Services\Requests\MovingAfterService;
use Modules\Moving\Services\Requests\MovingRequestService;
use Modules\Provider\Models\DocumentRequirement;
use Modules\Provider\Models\DocumentType;
use Modules\Provider\Models\Membership;
use Modules\Provider\Models\Provider;
use Modules\Provider\Models\ProviderDocument;
use Modules\Provider\Models\Vehicle;
use Modules\Provider\Models\VehicleType;
use Modules\Provider\Services\Documents\DocumentService;
use Modules\Provider\Services\Establishments\EstablishmentService;
use Modules\Provider\Services\Establishments\MembershipService;
use Modules\Rides\Services\Geo\GeoProvider;
use Modules\Rides\Services\Geo\RouteResult;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\Checkout;
use Modules\Wallet\Services\CheckoutService;
use Modules\Wallet\Services\WalletService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase B of docs/moving_tasks.md: a customer posts a move, it reaches the branches whose area holds the pickup
 * point (neighbourhood only), establishments send one offer each with an approved vehicle, the customer sees them
 * three at a time and accepts one on the payment screen (the money is held), and the establishment assigns a driver.
 */
class MovingFlowTest extends TestCase
{
    private Country $egypt;

    private ServiceCategory $moving;

    private User $customer;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['broadcasting.default' => 'null']);
        $this->egypt = Country::create([
            'code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'eg'])->id,
            'currency_id' => Currency::create(['code' => 'EGP', 'symbol' => 'ج.م', 'decimal_places' => 2])->id,
        ]);
        $this->moving = ServiceCategory::query()->create(['module_name' => 'moving', 'audiences' => ['admin', 'user', 'provider'], 'requires_provider' => true, 'status' => true, 'sort_order' => 1]);
        DB::table('country_service_category')->insert(['country_id' => $this->egypt->id, 'service_category_id' => $this->moving->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach (VehicleType::query()->get() as $type) {
            $type->countries()->sync([$this->egypt->id]);
            $type->services()->sync([$this->moving->id]);
        }
        foreach (DocumentType::query()->get() as $type) {
            DocumentRequirement::query()->create([
                'document_type_id' => $type->id, 'country_id' => $this->egypt->id,
                'service_category_id' => $type->subject->value === 'establishment' ? null : $this->moving->id,
                'establishment_type' => match ($type->code) {
                    'national_id' => 'individual', 'commercial_register' => 'company', default => null
                },
            ]);
        }
        $this->admin = Admin::query()->create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);
        $this->customer = User::query()->create(['phone' => '+201000000001', 'status' => 'active', 'phone_verified_at' => now(), 'country_id' => $this->egypt->id]);
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->firstOrCreateWallet($this->customer, $this->egypt), 1_000_000, WalletBucket::Withdrawable, WalletTransactionType::Topup, ['idempotency_key' => 'test:customer']);
    }

    public function test_a_move_goes_from_draft_to_a_held_payment_and_an_assigned_driver(): void
    {
        app(MovingPolicies::class)->save($this->egypt->id, ['commission.bp' => 1000, 'tax.bp' => 1400], $this->admin->id);
        [$owner, $seat, $vehicle] = $this->readyIndividual('1011111111', 30.0444, 31.2357, 20);
        [, $farSeat] = $this->readyIndividual('1022222222', 31.2001, 29.9187, 20); // Alexandria: too far
        $type = VehicleType::query()->where('code', 'half_truck')->value('id');

        // The customer's draft, then publishing.
        $this->asCustomer();
        $uuid = $this->postJson('/api/mobile/v1/moving/requests', ['load_type' => 'home', 'description' => 'شقة غرفتين'])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.uuid');
        $this->postJson("/api/mobile/v1/moving/requests/{$uuid}/publish")->assertStatus(422)->assertJsonPath('error_code', 'moving_fields_missing');
        $this->putJson("/api/mobile/v1/moving/requests/{$uuid}", [
            'vehicle_type_id' => $type, 'workers_count' => 3, 'disassembly' => true, 'scheduled_at' => now()->addDays(2)->toIso8601String(),
            'pickup_lat' => 30.0500, 'pickup_lng' => 31.2400, 'pickup_address' => '12 شارع النصر', 'pickup_area' => 'مدينة نصر', 'pickup_floor' => 4,
            'dropoff_lat' => 30.0200, 'dropoff_lng' => 31.4900, 'dropoff_address' => 'التجمع الخامس', 'dropoff_area' => 'التجمع', 'dropoff_floor' => 2, 'dropoff_elevator' => true,
        ])->assertOk();
        $this->postJson("/api/mobile/v1/moving/requests/{$uuid}/publish")->assertOk()->assertJsonPath('data.status', 'open');
        $move = MovingRequest::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(1000, $move->policy('commission.bp'));
        $this->assertSame([$seat->establishment_id], DB::table('moving_request_branches')->where('request_id', $move->id)->pluck('establishment_id')->all());

        // The near establishment sees the neighbourhood only; the far one does not see it at all.
        $this->asProvider($owner);
        $this->getJson('/api/provider/v1/moving/requests')
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $uuid)
            ->assertJsonPath('data.0.pickup.area', 'مدينة نصر')
            ->assertJsonPath('data.0.pickup.address', null);
        // The home's numbers: one request this establishment hasn't answered yet.
        $this->getJson('/api/provider/v1/moving/dashboard')->assertOk()->assertJsonPath('data.new_requests', 1)->assertJsonPath('data.my_jobs', 0);
        $offer = [
            'vehicle_uuid' => $vehicle->uuid, 'price_minor' => 300000, 'workers_count' => 3, 'includes' => ['disassembly' => true, 'packing' => false],
            'arrival_at' => now()->addDays(2)->toIso8601String(), 'valid_until' => now()->addDay()->toIso8601String(),
        ];
        $offerUuid = $this->postJson("/api/provider/v1/moving/requests/{$uuid}/offers", $offer)->assertCreated()->assertJsonPath('data.status', 'sent')->json('data.uuid');
        // One offer per establishment.
        $this->postJson("/api/provider/v1/moving/requests/{$uuid}/offers", $offer)->assertStatus(409)->assertJsonPath('error_code', 'moving_offer_exists');
        $this->app['auth']->forgetGuards();
        $this->asProvider($farSeat->provider);
        $this->getJson('/api/provider/v1/moving/requests')->assertOk()->assertJsonCount(0, 'data');

        // The customer compares and accepts on the payment screen.
        $this->app['auth']->forgetGuards();
        $this->asCustomer();
        $this->getJson("/api/mobile/v1/moving/requests/{$uuid}/offers")
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.has_more', false)
            ->assertJsonPath('data.offers.0.reasons', ['cheapest', 'earliest', 'vehicle_fit']);
        $checkoutUuid = $this->postJson("/api/mobile/v1/moving/requests/{$uuid}/offers/{$offerUuid}/accept")
            ->assertCreated()->assertJsonPath('data.amount_minor', 342000)->json('data.id');
        app(CheckoutService::class)->payWithWallet($this->customer, Checkout::query()->where('uuid', $checkoutUuid)->firstOrFail());

        $move->refresh();
        $this->assertSame(MovingStatus::Confirmed, $move->status);
        $this->assertSame(342000, $move->total_minor);
        $this->assertSame(30000, $move->commission_minor);
        $this->assertSame(270000, $move->provider_net_minor);
        $this->assertSame(342000, app(MovingPayments::class)->held($move));

        // The owner of an individual establishment drives it himself: assigned and accepted at once.
        $this->app['auth']->forgetGuards();
        $this->asProvider($owner);
        $this->postJson("/api/provider/v1/moving/jobs/{$uuid}/assign", ['driver_uuid' => $seat->uuid])->assertOk()->assertJsonPath('data.status', 'assigned');
        $this->assertNotNull($move->fresh()->driver_accepted_at);
        $this->getJson('/api/provider/v1/moving/requests/'.$uuid)->assertOk()->assertJsonPath('data.pickup.address', '12 شارع النصر');
        $this->getJson('/api/provider/v1/moving/my-jobs')->assertOk()->assertJsonPath('data.0.uuid', $uuid);
    }

    public function test_an_offer_above_the_senders_limit_waits_for_the_approver_and_a_leaving_driver_hands_jobs_back(): void
    {
        [$owner, $seat, $vehicle] = $this->readyIndividual('1011111111', 30.0444, 31.2357, 20);
        $move = $this->publishedRequest();

        // A quoter who may offer up to 2,000.
        $quoterPerson = Provider::query()->create(['phone' => '+201033333333', 'country_id' => $this->egypt->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $quoter = app(MembershipService::class)->invite($seat, ['phone' => $quoterPerson->phone, 'permissions' => ['requests.view', 'offers.send', 'offers.manage'], 'offer_limit_minor' => 200000]);
        app(MembershipService::class)->accept($quoterPerson, $quoter);
        $this->asProvider($quoterPerson);
        $offerUuid = $this->postJson("/api/provider/v1/moving/requests/{$move->uuid}/offers", [
            'vehicle_uuid' => $vehicle->uuid, 'price_minor' => 300000, 'includes' => [],
            'arrival_at' => now()->addDays(2)->toIso8601String(), 'valid_until' => now()->addDay()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.status', 'awaiting_approval')->json('data.uuid');

        // The customer doesn't see it until the owner lets it out.
        $this->app['auth']->forgetGuards();
        $this->asCustomer();
        $this->getJson("/api/mobile/v1/moving/requests/{$move->uuid}/offers")->assertOk()->assertJsonPath('data.total', 0);
        $this->app['auth']->forgetGuards();
        $this->asProvider($owner);
        $this->postJson("/api/provider/v1/moving/offers/{$offerUuid}/approve")->assertOk()->assertJsonPath('data.status', 'sent');

        // A company driver given a job leaves: the job returns to the establishment.
        $driverPerson = Provider::query()->create(['phone' => '+201044444444', 'country_id' => $this->egypt->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $driver = Membership::query()->create(['establishment_id' => $seat->establishment_id, 'provider_id' => $driverPerson->id, 'status' => 'active', 'joined_at' => now()]);
        $driver->permissionRows()->create(['permission' => 'driving.work']);
        $this->approve('membership', $driver->id, 'driving_licence', $seat->establishment_id);
        $move->update(['status' => 'assigned', 'establishment_id' => $seat->establishment_id, 'driver_membership_id' => $driver->id]);
        app(MembershipService::class)->leave($driverPerson, $driver);
        $this->assertSame(MovingStatus::Confirmed, $move->fresh()->status);
        $this->assertNull($move->fresh()->driver_membership_id);
    }

    public function test_requests_with_no_chosen_offer_expire_and_a_withdrawn_one_takes_no_offers(): void
    {
        $this->readyIndividual('1011111111', 30.0444, 31.2357, 20);
        $move = $this->publishedRequest();
        $move->update(['offers_deadline_at' => now()->subMinute()]);
        $this->assertSame(1, app(MovingRequestService::class)->expireDue());
        $this->assertSame(MovingStatus::Expired, $move->fresh()->status);

        $other = $this->publishedRequest();
        $this->asCustomer();
        $this->postJson("/api/mobile/v1/moving/requests/{$other->uuid}/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->postJson("/api/mobile/v1/moving/requests/{$other->uuid}/withdraw")->assertStatus(409);
    }

    public function test_the_admin_sets_the_values_and_published_requests_keep_theirs(): void
    {
        $this->readyIndividual('1011111111', 30.0444, 31.2357, 20);
        Sanctum::actingAs($this->admin, [], 'admin_api');
        Permission::findOrCreate('moving-policies.update', 'admin_api');
        Permission::findOrCreate('moving-policies.view', 'admin_api');
        $this->admin->givePermissionTo(['moving-policies.update', 'moving-policies.view']);
        $this->putJson('/api/admin/v1/moving-policies', ['country_id' => $this->egypt->id, 'values' => ['commission.bp' => 1000]])->assertOk();

        $move = $this->publishedRequest();
        $this->putJson('/api/admin/v1/moving-policies', ['country_id' => $this->egypt->id, 'values' => ['commission.bp' => 1500]])->assertOk();
        $this->assertSame(1000, $move->fresh()->policy('commission.bp'));
        $this->assertSame(1500, $this->publishedRequest()->policy('commission.bp'));
    }

    public function test_moving_day_ends_with_the_code_and_a_report_keeps_only_the_claim_held_until_a_person_decides(): void
    {
        app(MovingPolicies::class)->save($this->egypt->id, ['commission.bp' => 1000, 'delivery.objection_hours' => 24, 'dispute.max_hold_bp' => 5000], $this->admin->id);
        [$owner, $seat, , $move] = $this->confirmedJob(300000);
        $this->asProvider($owner);
        $this->postJson("/api/provider/v1/moving/jobs/{$move->uuid}/assign", ['driver_uuid' => $seat->uuid])->assertOk();

        $step = fn (string $s, array $extra = []) => $this->postJson("/api/provider/v1/moving/jobs/{$move->uuid}/step", ['step' => $s] + $extra);
        $step('arrived')->assertStatus(409);
        $step('set_out')->assertOk()->assertJsonPath('data.status', 'driver_on_way');
        $this->postJson("/api/provider/v1/moving/jobs/{$move->uuid}/locations", ['points' => [['lat' => 30.04, 'lng' => 31.23], ['lat' => 30.045, 'lng' => 31.235, 'heading' => 90]]])->assertOk();
        $this->assertEqualsWithDelta(30.045, $move->fresh()->driver_lat, 0.0001);
        $step('arrived', ['lat' => 30.05, 'lng' => 31.24])->assertOk();
        $step('loading')->assertOk();
        $step('departed')->assertStatus(422)->assertJsonPath('error_code', 'moving_inventory_empty');
        $this->post("/api/provider/v1/moving/jobs/{$move->uuid}/inventory", ['label' => 'ثلاجة', 'photo' => UploadedFile::fake()->image('f.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.number', 1);
        $step('departed')->assertOk();
        $step('unloading')->assertOk();

        // Delivery needs the customer's code; without it a reason and a note are recorded.
        $this->postJson("/api/provider/v1/moving/jobs/{$move->uuid}/deliver", ['code' => '0000'])->assertStatus(422)->assertJsonPath('error_code', 'moving_wrong_code');
        $this->postJson("/api/provider/v1/moving/jobs/{$move->uuid}/deliver", ['code' => $move->fresh()->delivery_code, 'recipient_name' => 'أحمد'])
            ->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->assertSame('code', $move->fresh()->delivery_ack);

        // The customer reports a broken piece within the window, claiming 2,000.
        $this->app['auth']->forgetGuards();
        $this->asCustomer();
        $this->post("/api/mobile/v1/moving/requests/{$move->uuid}/report", ['reason' => 'damage', 'claim_minor' => 200000, 'photos' => [UploadedFile::fake()->image('b.jpg')]], ['Accept' => 'application/json'])
            ->assertCreated();

        // The window ends: the establishment gets its net less the claim; the claim stays held.
        $this->travel(25)->hours();
        $this->assertSame(1, app(MovingSettlement::class)->settleDue());
        $move->refresh();
        $this->assertSame(MovingStatus::Disputed, $move->status);
        $this->assertSame(150000, app(MovingPayments::class)->held($move)); // the claim, capped at the country's 50%
        $this->assertSame(270000 - 150000, $this->establishmentBalance($seat));

        // A person decides: 500 back to the customer, the rest of the claim to the establishment.
        Sanctum::actingAs($this->admin, [], 'admin_api');
        Permission::findOrCreate('moving-cases.update', 'admin_api');
        $this->admin->givePermissionTo('moving-cases.update');
        $case = $move->cases()->firstOrFail();
        $this->postJson("/api/admin/v1/moving-cases/{$case->uuid}/decide", ['award_minor' => 50000, 'note' => 'كسر بسيط'])->assertOk()->assertJsonPath('data.decision', 'partial');
        $this->assertSame(MovingStatus::Completed, $move->fresh()->status);
        $this->assertSame(0, app(MovingPayments::class)->held($move));
        $this->assertSame(270000 - 50000, $this->establishmentBalance($seat));
    }

    public function test_cancelling_costs_the_fee_of_the_stage_and_a_reason_that_blames_the_other_side_goes_to_a_person(): void
    {
        app(MovingPolicies::class)->save($this->egypt->id, ['cancel.customer.early_bp' => 1000], $this->admin->id);
        [, $seat, , $move] = $this->confirmedJob(300000);
        $this->asCustomer();
        $this->getJson("/api/mobile/v1/moving/requests/{$move->uuid}")->assertOk()->assertJsonPath('data.cancel_fee_minor', 30000);

        // "The driver asked for extra money" is not a plain cancellation: it waits for a decision, no fee.
        $this->postJson("/api/mobile/v1/moving/requests/{$move->uuid}/cancel", ['reason' => 'extra_money_asked'])->assertCreated()->assertJsonPath('data.kind', 'exception');
        $this->assertSame(MovingStatus::Confirmed, $move->fresh()->status);

        $this->postJson("/api/mobile/v1/moving/requests/{$move->uuid}/cancel", ['reason' => 'changed_plans'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(0, app(MovingPayments::class)->held($move));
        $this->assertSame(270000, $move->fresh()->refunded_minor);
        $this->assertSame(30000, $this->establishmentBalance($seat));
    }

    public function test_a_price_difference_counts_only_once_the_customer_approves_and_pays_and_tickets_go_to_the_establishment_first(): void
    {
        app(MovingPolicies::class)->save($this->egypt->id, ['commission.bp' => 1000], $this->admin->id);
        [$owner, $seat, , $move] = $this->confirmedJob(300000);

        // The establishment asks 500 more for an extra trip; nothing changes until the customer says yes and pays.
        $this->asProvider($owner);
        $changeUuid = $this->postJson("/api/provider/v1/moving/jobs/{$move->uuid}/changes", ['price_delta_minor' => 50000, 'trips_count' => 2, 'reason' => 'رحلة إضافية'])
            ->assertCreated()->json('data.uuid');
        $this->assertSame(300000, $move->fresh()->price_minor);
        $this->app['auth']->forgetGuards();
        $this->asCustomer();
        $checkoutId = $this->postJson("/api/mobile/v1/moving/requests/{$move->uuid}/changes/{$changeUuid}/respond", ['approve' => true])
            ->assertCreated()->assertJsonPath('data.change.status', 'awaiting_payment')->assertJsonPath('data.checkout.amount_minor', 50000)->json('data.checkout.id');
        app(CheckoutService::class)->payWithWallet($this->customer, Checkout::query()->where('uuid', $checkoutId)->firstOrFail());
        $move->refresh();
        $this->assertSame(350000, $move->price_minor);
        $this->assertSame(315000, $move->provider_net_minor);
        $this->assertSame(350000, app(MovingPayments::class)->held($move));

        // The customer asks for another day; the establishment agrees.
        $newTime = now()->addDays(4)->startOfHour();
        $rescheduleUuid = $this->postJson("/api/mobile/v1/moving/requests/{$move->uuid}/reschedule", ['scheduled_at' => $newTime->toIso8601String(), 'reason' => 'سفر'])
            ->assertCreated()->json('data.uuid');
        $this->app['auth']->forgetGuards();
        $this->asProvider($owner);
        $this->postJson("/api/provider/v1/moving/changes/{$rescheduleUuid}/respond", ['approve' => true])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertTrue($move->fresh()->scheduled_at->equalTo($newTime));

        // A complaint goes to the establishment; its answer doesn't close it; the customer takes it to Dorr.
        $this->app['auth']->forgetGuards();
        $this->asCustomer();
        $ticket = $this->postJson("/api/mobile/v1/moving/requests/{$move->uuid}/tickets", ['type' => 'delay', 'subject' => 'تأخير', 'body' => 'تأخر الموعد'])
            ->assertCreated()->assertJsonPath('data.status', 'awaiting_company')->json('data.uuid');
        $this->app['auth']->forgetGuards();
        $this->asProvider($owner);
        $this->postJson("/api/provider/v1/moving/tickets/{$ticket}/reply", ['body' => 'نعتذر'])->assertOk()->assertJsonPath('data.status', 'company_replied');
        $this->app['auth']->forgetGuards();
        $this->asCustomer();
        $this->postJson("/api/mobile/v1/moving/tickets/{$ticket}/act", ['action' => 'escalate', 'body' => 'لم يُحل'])->assertOk()->assertJsonPath('data.status', 'escalated');

        // The invoice shows the agreed price and the approved change, never Dorr's commission to the customer.
        $this->getJson("/api/mobile/v1/moving/requests/{$move->uuid}/invoice")
            ->assertOk()->assertJsonPath('data.price_minor', 350000)->assertJsonPath('data.lines.1.amount_minor', 50000)->assertJsonPath('data.commission_minor', null);

        // The admin follows it live.
        Sanctum::actingAs($this->admin, [], 'admin_api');
        foreach (['moving-requests.view', 'moving-tickets.view'] as $p) {
            Permission::findOrCreate($p, 'admin_api');
        }
        $this->admin->givePermissionTo(['moving-requests.view', 'moving-tickets.view']);
        $this->getJson('/api/admin/v1/moving-requests?status=confirmed')->assertOk()->assertJsonPath('data.0.uuid', $move->uuid);
        $this->getJson("/api/admin/v1/moving-requests/{$move->uuid}")->assertOk()->assertJsonPath('data.held_minor', 350000)->assertJsonCount(2, 'data.changes');
        $this->getJson('/api/admin/v1/moving-tickets')->assertOk()->assertJsonPath('data.0.uuid', $ticket);
    }

    public function test_an_expired_request_comes_back_as_a_draft_and_the_screens_get_what_they_need(): void
    {
        $this->readyIndividual('1011111111', 30.0444, 31.2357, 20);
        $this->asCustomer();
        $this->getJson('/api/mobile/v1/moving/options')->assertOk()->assertJsonPath('data.available', true);

        $move = $this->publishedRequest();
        $this->postJson("/api/mobile/v1/moving/requests/{$move->uuid}/republish")->assertStatus(409);
        $move->update(['offers_deadline_at' => now()->subMinute()]);
        app(MovingRequestService::class)->expireDue();
        $draft = $this->postJson("/api/mobile/v1/moving/requests/{$move->uuid}/republish")
            ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.description', 'ثلاجة')->json('data.uuid');
        $this->assertNotSame($move->uuid, $draft);

        $this->getJson("/api/mobile/v1/moving/requests/{$draft}")->assertOk()
            ->assertJsonPath('data.rated', false)->assertJsonPath('data.request.currency_decimals', 2);

        DB::table('country_service_category')->delete();
        $this->getJson('/api/mobile/v1/moving/options')->assertOk()->assertJsonPath('data.available', false);
    }

    public function test_the_route_between_the_two_ends_gives_the_road_distance_and_is_asked_once_per_pair(): void
    {
        $this->asCustomer();
        $geo = \Mockery::mock(GeoProvider::class);
        $geo->shouldReceive('route')->once()->andReturn(new RouteResult(18400, 1500, 'abc_poly'));
        $this->app->instance(GeoProvider::class, $geo);

        $query = '/api/mobile/v1/moving/route?from_lat=30.0444&from_lng=31.2357&to_lat=30.1&to_lng=31.3';
        $this->getJson($query)->assertOk()
            ->assertJsonPath('data.distance_m', 18400)->assertJsonPath('data.duration_s', 1500)
            ->assertJsonPath('data.polyline', 'abc_poly')->assertJsonPath('data.estimated', false);
        $this->getJson($query)->assertOk()->assertJsonPath('data.distance_m', 18400);
        $this->getJson('/api/mobile/v1/moving/route?from_lat=300&from_lng=31&to_lat=30&to_lng=31')->assertStatus(422);
    }

    /**
     * The owner's scenario (2026-11-11): a request reaches only the companies with an approved vehicle of a type the
     * customer accepts; the customer may accept several types; every company sends its offer and the customer hears
     * of each one, then compares them three at a time, cheapest first.
     */
    public function test_a_request_reaches_only_the_vehicle_types_asked_and_the_customer_hears_of_every_offer(): void
    {
        $type = fn (string $code) => VehicleType::query()->where('code', $code)->value('id');
        [$quarterOwner, $quarterSeat, $quarter] = $this->readyIndividual('1031111111', 30.0444, 31.2357, 20, 'quarter_truck');
        [$halfOwner, $halfSeat, $half] = $this->readyIndividual('1032222222', 30.0500, 31.2300, 20, 'half_truck');
        [$jumboOwner, $jumboSeat] = $this->readyIndividual('1033333333', 30.0600, 31.2200, 20, 'jumbo_truck');
        // A half truck still waiting for the admin: its company can't answer, so it isn't sent the request.
        [, $pendingSeat, $pending] = $this->readyIndividual('1034444444', 30.0500, 31.2400, 20, 'half_truck');
        $pending->update(['status' => 'pending']);

        // A half truck only: the quarter-truck company never sees it.
        $this->asCustomer();
        $uuid = $this->postJson('/api/mobile/v1/moving/requests', [
            'load_type' => 'home', 'description' => 'غرفة نوم', 'vehicle_type_ids' => [$type('half_truck')],
            'scheduled_at' => now()->addDays(2)->toIso8601String(),
            'pickup_lat' => 30.0500, 'pickup_lng' => 31.2400, 'pickup_area' => 'مدينة نصر',
            'dropoff_lat' => 30.0200, 'dropoff_lng' => 31.4900, 'dropoff_area' => 'التجمع',
        ])->assertCreated()->assertJsonPath('data.vehicle_type_id', $type('half_truck'))->json('data.uuid');
        $this->postJson("/api/mobile/v1/moving/requests/{$uuid}/publish")->assertOk();
        $move = MovingRequest::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame([$halfSeat->establishment_id], DB::table('moving_request_branches')->where('request_id', $move->id)->pluck('establishment_id')->all());

        // A quarter or a half truck: both companies get it, the jumbo one and the one waiting for approval don't.
        $uuid = $this->postJson('/api/mobile/v1/moving/requests', [
            'load_type' => 'home', 'description' => 'ثلاجة وغسالة وسرير', 'vehicle_type_ids' => [$type('half_truck'), $type('quarter_truck')],
            'scheduled_at' => now()->addDays(2)->toIso8601String(),
            'pickup_lat' => 30.0500, 'pickup_lng' => 31.2400, 'pickup_area' => 'مدينة نصر',
            'dropoff_lat' => 30.0200, 'dropoff_lng' => 31.4900, 'dropoff_area' => 'التجمع',
        ])->assertCreated()
            ->assertJsonPath('data.vehicle_type_ids', [$type('half_truck'), $type('quarter_truck')])
            ->assertJsonCount(2, 'data.vehicle_types')
            ->json('data.uuid');
        $this->postJson("/api/mobile/v1/moving/requests/{$uuid}/publish")->assertOk();
        $move = MovingRequest::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertEqualsCanonicalizing(
            [$halfSeat->establishment_id, $quarterSeat->establishment_id],
            DB::table('moving_request_branches')->where('request_id', $move->id)->pluck('establishment_id')->all(),
        );
        $this->app['auth']->forgetGuards();
        $this->asProvider($jumboOwner);
        $this->getJson('/api/provider/v1/moving/requests')->assertOk()->assertJsonCount(0, 'data');
        $this->app['auth']->forgetGuards();
        $this->asProvider($pendingSeat->provider);
        $this->getJson('/api/provider/v1/moving/requests')->assertOk()->assertJsonCount(0, 'data');

        // Each company opens it: its vehicles that can answer come first, and it offers.
        $offer = fn (Vehicle $vehicle, int $price, int $hours) => [
            'vehicle_uuid' => $vehicle->uuid, 'price_minor' => $price, 'workers_count' => 3, 'includes' => ['disassembly' => true],
            'arrival_at' => now()->addDays(2)->addHours($hours)->toIso8601String(), 'valid_until' => now()->addDay()->toIso8601String(),
        ];
        $this->app['auth']->forgetGuards();
        $this->asProvider($halfOwner);
        $this->getJson("/api/provider/v1/moving/requests/{$uuid}")->assertOk()
            ->assertJsonPath('data.my_offer', null)
            ->assertJsonPath('data.my_vehicles.0.uuid', $half->uuid)
            ->assertJsonPath('data.my_vehicles.0.fits', true)
            ->assertJsonPath('data.my_vehicles.0.problem', null)
            ->assertJsonPath('data.pricing.tax_bp', 0);
        $this->postJson("/api/provider/v1/moving/requests/{$uuid}/offers", $offer($half, 250000, 1))->assertCreated();
        $this->getJson("/api/provider/v1/moving/requests/{$uuid}")->assertOk()->assertJsonPath('data.my_offer.price_minor', 250000);

        $this->app['auth']->forgetGuards();
        $this->asProvider($quarterOwner);
        // A vehicle of a type the customer didn't ask for is refused (here: the quarter company's extra jumbo).
        $jumbo = $quarterSeat->establishment->vehicles()->create([
            'vehicle_type_id' => $type('jumbo_truck'), 'country_id' => $this->egypt->id, 'plate_number' => '9999', 'status' => 'approved',
        ]);
        $this->getJson("/api/provider/v1/moving/requests/{$uuid}")->assertOk()
            ->assertJsonPath('data.my_vehicles.0.uuid', $quarter->uuid)
            ->assertJsonPath('data.my_vehicles.1.fits', false)
            ->assertJsonPath('data.my_vehicles.1.problem', 'vehicle_type_not_asked');
        $this->postJson("/api/provider/v1/moving/requests/{$uuid}/offers", $offer($jumbo, 150000, 0))
            ->assertStatus(422)->assertJsonPath('error_code', 'moving_vehicle_type_not_asked');
        $this->postJson("/api/provider/v1/moving/requests/{$uuid}/offers", $offer($quarter, 180000, 0))->assertCreated();

        // The customer was told of both offers, and compares them: the cheapest first, their first choice marked.
        $events = $this->customer->notifications()->get()->pluck('data.event')->all();
        $this->assertSame(2, collect($events)->filter(fn ($e) => $e === 'moving.offer_received')->count());
        $this->app['auth']->forgetGuards();
        $this->asCustomer();
        $offers = $this->getJson("/api/mobile/v1/moving/requests/{$uuid}/offers")->assertOk()->assertJsonPath('data.total', 2)->json('data.offers');
        $this->assertSame([180000, 250000], array_column($offers, 'price_minor'));
        $this->assertContains('cheapest', $offers[0]['reasons']);
        $this->assertContains('vehicle_fit', $offers[1]['reasons']);
        $this->assertNotContains('vehicle_fit', $offers[0]['reasons']);
    }

    /**
     * The owner's rules (2026-11-12): a request reaches only the branch whose area holds it, and that branch offers with
     * any vehicle of the company (this week's half truck is written on Alexandria); the customer sees the company's
     * branches with the offer, then rates it by criteria as in rides, and the averages show with its next offers.
     */
    public function test_a_branch_offers_with_any_company_vehicle_the_customer_sees_its_branches_and_rates_by_criteria(): void
    {
        [$owner, $seat] = $this->readyIndividual('1051111111', 30.0444, 31.2357, 20, 'quarter_truck');
        $alexandria = $seat->establishment->branches()->create([
            'name' => 'فرع الإسكندرية', 'lat' => 31.2001, 'lng' => 29.9187, 'radius_km' => 20, 'address' => 'سموحة', 'status' => true,
        ]);
        $half = $seat->establishment->vehicles()->create([
            'vehicle_type_id' => VehicleType::query()->where('code', 'half_truck')->value('id'), 'country_id' => $this->egypt->id,
            'branch_id' => $alexandria->id, 'plate_number' => '5555', 'status' => 'approved',
        ]);
        foreach (DocumentType::query()->where('subject', 'vehicle')->pluck('code') as $code) {
            $this->approve('vehicle', $half->id, $code, $seat->establishment_id);
        }

        // A half truck in Cairo: the Cairo branch gets it (the truck is the company's), Alexandria doesn't.
        $move = $this->publishedRequest();
        $this->assertSame([$seat->establishment->branches()->where('is_main', true)->value('id')], DB::table('moving_request_branches')->where('request_id', $move->id)->pluck('branch_id')->all());

        $this->asProvider($owner);
        $this->getJson("/api/provider/v1/moving/requests/{$move->uuid}")->assertOk()
            ->assertJsonPath('data.my_vehicles.0.uuid', $half->uuid)->assertJsonPath('data.my_vehicles.0.problem', null);
        $this->postJson("/api/provider/v1/moving/requests/{$move->uuid}/offers", [
            'vehicle_uuid' => $half->uuid, 'price_minor' => 200000, 'includes' => ['packing' => true],
            'arrival_at' => now()->addDays(2)->toIso8601String(), 'valid_until' => now()->addDay()->toIso8601String(),
        ])->assertCreated();

        // The customer sees where the company is: the answering branch first, then the others with their distance.
        $this->app['auth']->forgetGuards();
        $this->asCustomer();
        $offer = $this->getJson("/api/mobile/v1/moving/requests/{$move->uuid}/offers")->assertOk()->json('data.offers.0');
        $this->assertCount(2, $offer['branches']);
        $this->assertTrue($offer['branches'][0]['answering']);
        $this->assertSame('سموحة', $offer['branches'][1]['address']);
        $this->assertGreaterThan(150, $offer['branches'][1]['distance_km']);
        $this->assertNull($offer['establishment']['criteria'][0]['average']);

        // Paid, delivered, then rated by criteria: the overall stars are their average.
        $checkouts = app(CheckoutService::class);
        $checkouts->payWithWallet($this->customer, $checkouts->create($this->customer, $this->egypt, 'moving', ['request_uuid' => $move->uuid, 'offer_uuid' => $offer['uuid']]));
        $move->refresh()->update(['status' => MovingStatus::Delivered]);
        $show = $this->getJson("/api/mobile/v1/moving/requests/{$move->uuid}")->assertOk()->json('data');
        $this->assertSame(['punctuality', 'care', 'team', 'assembly', 'price'], array_column($show['rating_criteria'], 'key'));
        $this->postJson("/api/mobile/v1/moving/requests/{$move->uuid}/rating", ['scores' => ['punctuality' => 5, 'care' => 4, 'team' => 5, 'assembly' => 4, 'price' => 2]])
            ->assertCreated();
        $this->assertSame(4, (int) DB::table('moving_ratings')->where('request_id', $move->id)->value('establishment_stars'));
        $this->postJson("/api/mobile/v1/moving/requests/{$move->uuid}/rating", ['establishment_stars' => 5])->assertStatus(409);

        // The next customer sees the averages per criterion with the company's offer.
        $reputation = MovingAfterService::reputation($seat->establishment_id);
        $this->assertSame(4.0, $reputation['average']);
        $this->assertSame(['key' => 'care', 'label' => __('moving.rating_criteria.care'), 'average' => 4.0, 'count' => 1], $reputation['criteria'][1]);
    }

    /** The customer may tell the load in a voice note on the description page; it counts as the description and the companies hear it. */
    public function test_a_voice_note_describes_the_load_and_the_companies_hear_it(): void
    {
        [$owner] = $this->readyIndividual('1061111111', 30.0444, 31.2357, 20);
        $this->asCustomer();
        $uuid = $this->postJson('/api/mobile/v1/moving/requests', [
            'load_type' => 'home', 'vehicle_type_ids' => [VehicleType::query()->where('code', 'half_truck')->value('id')],
            'scheduled_at' => now()->addDays(2)->toIso8601String(),
            'pickup_lat' => 30.05, 'pickup_lng' => 31.24, 'pickup_area' => 'مدينة نصر', 'dropoff_lat' => 30.02, 'dropoff_lng' => 31.49, 'dropoff_area' => 'التجمع',
        ])->assertCreated()->json('data.uuid');
        $this->postJson("/api/mobile/v1/moving/requests/{$uuid}/publish")->assertStatus(422)->assertJsonPath('data.missing', ['description_or_photo']);

        $note = fn () => UploadedFile::fake()->create('note.m4a', 40, 'audio/mp4');
        $this->post("/api/mobile/v1/moving/requests/{$uuid}/media", ['file' => $note(), 'kind' => 'voice', 'duration_ms' => 9000, 'waveform' => json_encode([10, 80, 140, -5])], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.collection', 'voice')->assertJsonPath('data.waveform', [10, 80, 100, 0]);
        // A new recording replaces the last one.
        $this->post("/api/mobile/v1/moving/requests/{$uuid}/media", ['file' => $note(), 'kind' => 'voice', 'duration_ms' => 12000], ['Accept' => 'application/json'])->assertCreated();
        $this->getJson("/api/mobile/v1/moving/requests/{$uuid}")->assertOk()->assertJsonPath('data.request.voice.duration_ms', 12000);
        $this->postJson("/api/mobile/v1/moving/requests/{$uuid}/publish")->assertOk();
        $this->assertSame(1, MovingRequest::query()->where('uuid', $uuid)->firstOrFail()->getMedia('voice')->count());

        $this->app['auth']->forgetGuards();
        $this->asProvider($owner);
        $this->getJson("/api/provider/v1/moving/requests/{$uuid}")->assertOk()->assertJsonPath('data.voice.duration_ms', 12000);
    }

    public function test_a_driver_only_seat_works_the_job_without_seeing_its_money(): void
    {
        [, $seat, , $move] = $this->confirmedJob(300000);
        $person = Provider::query()->create(['phone' => '+201077777777', 'country_id' => $this->egypt->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $driver = Membership::query()->create(['establishment_id' => $seat->establishment_id, 'provider_id' => $person->id, 'status' => 'active', 'joined_at' => now()]);
        $driver->permissionRows()->create(['permission' => 'driving.work']);
        $this->approve('membership', $driver->id, 'driving_licence', $seat->establishment_id);
        $move->update(['status' => 'assigned', 'driver_membership_id' => $driver->id]);

        $this->asProvider($person);
        $this->getJson('/api/provider/v1/moving/my-jobs')->assertOk()->assertJsonPath('data.0.uuid', $move->uuid)->assertJsonPath('data.0.money', null);
        $this->getJson("/api/provider/v1/moving/requests/{$move->uuid}")->assertOk()->assertJsonPath('data.money', null)->assertJsonPath('data.branch_id', $move->branch_id);
        $this->getJson("/api/provider/v1/moving/jobs/{$move->uuid}/invoice")->assertForbidden();
    }

    /** @return array{0: Provider, 1: Membership, 2: Vehicle, 3: MovingRequest} a move whose offer is accepted and paid */
    private function confirmedJob(int $price): array
    {
        [$owner, $seat, $vehicle] = $this->readyIndividual('1011111111', 30.0444, 31.2357, 20);
        $move = $this->publishedRequest();
        $offer = app(MovingOfferService::class)->send($seat, $move, [
            'vehicle_uuid' => $vehicle->uuid, 'price_minor' => $price, 'includes' => [],
            'arrival_at' => now()->addDays(2)->toIso8601String(), 'valid_until' => now()->addDay()->toIso8601String(),
        ]);
        $checkouts = app(CheckoutService::class);
        $checkout = $checkouts->create($this->customer, $this->egypt, 'moving', ['request_uuid' => $move->uuid, 'offer_uuid' => $offer->uuid]);
        $checkouts->payWithWallet($this->customer, $checkout);

        return [$owner, $seat, $vehicle, $move->fresh()];
    }

    private function establishmentBalance(Membership $seat): int
    {
        return (int) app(WalletService::class)->firstOrCreateWallet($seat->establishment, $this->egypt)->fresh()->withdrawable_minor;
    }

    /** @return array{0: Provider, 1: Membership, 2: Vehicle} an approved individual mover with an approved half truck */
    private function readyIndividual(string $phone, float $lat, float $lng, float $radius, string $vehicleType = 'half_truck'): array
    {
        $owner = Provider::query()->create(['phone' => '+20'.$phone, 'name' => 'Mover '.$phone, 'country_id' => $this->egypt->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $seat = app(EstablishmentService::class)->create($owner, [
            'type' => 'individual', 'name' => 'نقل '.$phone, 'country_id' => $this->egypt->id,
            'service_ids' => [$this->moving->id], 'branch' => ['lat' => $lat, 'lng' => $lng, 'radius_km' => $radius],
        ]);
        $seat->establishment->update(['status' => 'approved']);
        $vehicle = $seat->establishment->vehicles()->create([
            'vehicle_type_id' => VehicleType::query()->where('code', $vehicleType)->value('id'), 'country_id' => $this->egypt->id,
            'plate_number' => $phone, 'status' => 'approved',
        ]);
        foreach (DocumentType::query()->where('subject', 'vehicle')->pluck('code') as $code) {
            $this->approve('vehicle', $vehicle->id, $code, $seat->establishment_id);
        }
        $this->approve('membership', $seat->id, 'driving_licence', $seat->establishment_id);

        return [$owner, $seat->fresh('establishment', 'provider'), $vehicle];
    }

    private function approve(string $subjectType, int $subjectId, string $code, int $establishmentId): void
    {
        $type = DocumentType::query()->where('code', $code)->firstOrFail();
        $document = ProviderDocument::query()->create([
            'establishment_id' => $establishmentId, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'document_type_id' => $type->id, 'expires_on' => $type->has_expiry ? now()->addYear() : null, 'status' => 'pending',
        ]);
        app(DocumentService::class)->approve($document, $this->admin->id);
    }

    private function publishedRequest(): MovingRequest
    {
        $service = app(MovingRequestService::class);
        $move = $service->create($this->customer, $this->egypt, [
            'load_type' => 'single_piece', 'description' => 'ثلاجة', 'vehicle_type_id' => VehicleType::query()->where('code', 'half_truck')->value('id'),
            'scheduled_at' => now()->addDays(2), 'pickup_lat' => 30.05, 'pickup_lng' => 31.24, 'pickup_area' => 'مدينة نصر',
            'dropoff_lat' => 30.02, 'dropoff_lng' => 31.49, 'dropoff_area' => 'التجمع',
        ]);

        return $service->publish($move);
    }

    private function asCustomer(): void
    {
        Sanctum::actingAs($this->customer, [], 'user_api');
    }

    private function asProvider(Provider $provider): void
    {
        Sanctum::actingAs($provider, [], 'provider_api');
    }
}
