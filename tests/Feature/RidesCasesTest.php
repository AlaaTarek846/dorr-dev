<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Modules\Admin\Models\Admin;
use Modules\Driver\Enums\DriverStatus;
use Modules\Driver\Models\Driver;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideAdjustment;
use Modules\Rides\Models\RideCase;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideEvent;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Models\RidePriceLine;
use Modules\Rides\Services\Cases\RideCases;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;
use Modules\Wallet\Database\Seeders\FinancialCategorySeeder;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Models\FinancialEntry;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Services\WalletService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Batch 8 of docs/rides_tasks.md: complaints, financial disputes and "the rider left without paying"
 * reports as cases of their own (R-133..R-140, D-229..D-238) — opened against a ride the person was on with
 * the evidence frozen, a conversation and files, the team's decision with its reason and an optional refund
 * that only happens when asked, one appeal where the country allows it, and a record that is never deleted.
 */
class RidesCasesTest extends TestCase
{
    private Country $saudi;

    private User $rider;

    private Driver $driver;

    private int $categoryId;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('public');
        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id,
        ]);
        $this->seed(FinancialCategorySeeder::class);
        $this->categoryId = RideCategory::query()->create(['slug' => 'economy', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 1, 'capacity_max' => 4])->id;
        $this->switches(['rides', 'cases']);

        $this->rider = User::query()->create(['name' => 'Sara Ahmed', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->driver = Driver::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Omar Khaled', 'phone' => '+966550000001', 'phone_verified_at' => now(), 'country_id' => $this->saudi->id, 'status' => DriverStatus::Active]);
    }

    public function test_a_rider_opens_a_complaint_and_it_is_routed_and_kept_apart_from_others(): void
    {
        $ride = $this->ride();

        $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides/cases/options')->assertOk()
            ->assertJsonPath('data.available', true)->assertJsonPath('data.kinds.0.code', 'complaint')
            ->assertJsonPath('data.complaint_categories.0.code', 'driver')->assertJsonPath('data.dispute_reasons.0.code', 'waiting_fee');

        $opened = $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/cases', [
            'kind' => 'complaint', 'ride_uuid' => $ride->uuid, 'category' => 'safety', 'summary' => 'He drove too fast on the highway.',
        ])->assertCreated();
        $opened->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.category_label', 'Safety')->assertJsonPath('data.ride.number', $ride->number);
        $this->assertMatchesRegularExpression('/^C\d{7}$/', $opened->json('data.number'));
        $this->assertSame('urgent', RideCase::query()->sole()->priority, 'safety is routed as urgent');

        // The same thing twice is one case; an unknown category, or someone else's ride, is refused.
        $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/cases', ['kind' => 'complaint', 'ride_uuid' => $ride->uuid, 'category' => 'safety', 'summary' => 'Again.'])
            ->assertStatus(409)->assertJsonPath('error_code', 'rides_case_already_open');
        $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/cases', ['kind' => 'complaint', 'ride_uuid' => $ride->uuid, 'category' => 'weather', 'summary' => 'x y z'])
            ->assertStatus(422)->assertJsonPath('error_code', 'rides_case_category_invalid');
        $stranger = User::query()->create(['name' => 'Other', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->actingAs($stranger, 'user_api')->postJson('/api/mobile/v1/rides/cases', ['kind' => 'complaint', 'ride_uuid' => $ride->uuid, 'category' => 'price', 'summary' => 'Not mine'])
            ->assertStatus(404)->assertJsonPath('error_code', 'rides_ride_not_found');
        // A complaint needs no ride; a dispute does. A rider can't file a captain's kind.
        $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/cases', ['kind' => 'complaint', 'category' => 'privacy', 'summary' => 'Something about my data.'])->assertCreated();
        $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/cases', ['kind' => 'dispute', 'category' => 'fare', 'summary' => 'Too much.'])->assertStatus(422)->assertJsonPath('error_code', 'rides_case_needs_ride');
        $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/cases', ['kind' => 'unpaid_report', 'ride_uuid' => $ride->uuid, 'summary' => 'x y z'])->assertStatus(422)->assertJsonPath('error_code', 'rides_case_kind_not_allowed');

        // Mine only.
        $uuid = $opened->json('data.uuid');
        $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides/cases')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($stranger, 'user_api')->getJson('/api/mobile/v1/rides/cases')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($stranger, 'user_api')->getJson("/api/mobile/v1/rides/cases/{$uuid}")->assertStatus(404)->assertJsonPath('error_code', 'rides_case_not_found');
        $this->actingAs($this->driver, 'driver_api')->getJson("/api/driver/v1/cases/{$uuid}")->assertStatus(404);

        // With the switch off nothing opens.
        $this->switches(['cases'], false);
        $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides/cases/options')->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.kinds', []);
        $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/cases', ['kind' => 'complaint', 'category' => 'price', 'summary' => 'x y z'])->assertStatus(403)->assertJsonPath('error_code', 'rides_feature_disabled');
    }

    public function test_a_dispute_names_the_charge_and_freezes_the_evidence(): void
    {
        $ride = $this->ride(['final_total_minor' => 4000]);
        RideEvent::query()->create(['ride_id' => $ride->id, 'type' => 'arrived', 'actor_type' => 'driver', 'actor_id' => $this->driver->id, 'lat' => 24.7140, 'lng' => 46.6760, 'created_at' => now()->subMinutes(30)]);
        RideAdjustment::query()->create(['ride_id' => $ride->id, 'type' => 'cancel_fee', 'amount_minor' => 1500, 'affects' => 'rider', 'created_at' => now()]);
        RidePriceLine::query()->create(['ride_id' => $ride->id, 'phase' => 'final', 'code' => 'waiting', 'amount_minor' => 300, 'visible_to' => 'both', 'sort_order' => 1, 'created_at' => now()]);
        RidePriceLine::query()->create(['ride_id' => $ride->id, 'phase' => 'final', 'code' => 'commission', 'amount_minor' => 800, 'visible_to' => 'internal', 'sort_order' => 2, 'created_at' => now()]);

        // A charge that isn't on the ride can't be disputed.
        $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/cases', ['kind' => 'dispute', 'ride_uuid' => $ride->uuid, 'category' => 'no_show_fee', 'summary' => 'No such fee'])
            ->assertStatus(422)->assertJsonPath('error_code', 'rides_case_nothing_to_dispute');

        $res = $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/cases', ['kind' => 'dispute', 'ride_uuid' => $ride->uuid, 'category' => 'cancel_fee', 'summary' => 'I cancelled in the free minute.'])
            ->assertCreated()->assertJsonPath('data.disputed_minor', 1500)->assertJsonPath('data.currency.code', 'SAR');
        $evidence = $res->json('data.evidence');
        $this->assertSame('arrived', $evidence['timeline'][0]['event']);
        $this->assertSame($ride->number, $evidence['ride']['number']);
        $this->assertGreaterThanOrEqual(0, $evidence['timeline'][0]['distance_to_pickup_m']);
        $this->assertSame(['waiting'], array_column($evidence['lines'], 'code'), 'the rider never sees the internal commission line');
        $this->assertSame(['cancel_fee'], array_column($evidence['adjustments'], 'type'));

        // Frozen: the ride changes later, the case's evidence doesn't.
        $ride->forceFill(['pickup_waiting_s' => 999])->save();
        RidePriceLine::query()->where('ride_id', $ride->id)->where('code', 'waiting')->update(['amount_minor' => 1]);
        $case = RideCase::query()->sole();
        $this->assertSame(300, collect($case->evidence['lines'])->firstWhere('code', 'waiting')['amount_minor']);
        $this->assertSame(0, $case->evidence['waiting']['pickup_s']);
        // The admin sees all of it, including the internal line.
        $this->assertSame(['waiting', 'commission'], array_column(app(RideCases::class)->present($case, 'admin')['evidence']['lines'], 'code'));
    }

    public function test_a_captain_reports_an_unpaid_cash_ride_without_it_becoming_a_debt(): void
    {
        $unpaid = $this->ride(['payment_method' => 'cash', 'payment_status' => 'cash_due', 'final_total_minor' => 2500]);
        $paid = $this->ride(['payment_method' => 'cash', 'payment_status' => 'cash_collected', 'final_total_minor' => 2500]);
        $wallet = $this->ride(['payment_method' => 'wallet', 'payment_status' => 'captured', 'final_total_minor' => 2500]);

        foreach ([$paid, $wallet] as $ride) {
            $this->actingAs($this->driver, 'driver_api')->postJson('/api/driver/v1/cases', ['kind' => 'unpaid_report', 'ride_uuid' => $ride->uuid, 'summary' => 'He left.'])
                ->assertStatus(422)->assertJsonPath('error_code', 'rides_case_not_eligible');
        }
        $res = $this->actingAs($this->driver, 'driver_api')->postJson('/api/driver/v1/cases', ['kind' => 'unpaid_report', 'ride_uuid' => $unpaid->uuid, 'summary' => 'He ran off at the airport.'])->assertCreated();
        $res->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.disputed_minor', 2500)->assertJsonPath('data.kind_label', 'Rider left without paying');

        // A report is only a report (D-230): the ride, the rider's wallet and the captain's wallet are as they were.
        $this->assertSame('cash_due', $unpaid->refresh()->payment_status->value);
        $this->assertSame(0, WalletTransaction::query()->count());
        // What the captain sees never names the rider.
        $json = json_encode($res->json('data'));
        $this->assertStringNotContainsString('+966500000001', $json);
        $this->assertStringNotContainsString('Sara', $json);
        // A captain can't file a rider's complaint, and the other side doesn't see the case.
        $this->actingAs($this->driver, 'driver_api')->postJson('/api/driver/v1/cases', ['kind' => 'complaint', 'category' => 'price', 'summary' => 'x y z'])->assertStatus(422)->assertJsonPath('error_code', 'rides_case_kind_not_allowed');
        $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides/cases/'.$res->json('data.uuid'))->assertStatus(404);
    }

    public function test_the_conversation_and_files_between_the_person_and_the_team(): void
    {
        $uuid = $this->openComplaint();
        $case = RideCase::query()->sole();
        $this->asAdmin(['ride-cases.view', 'ride-cases.update']);

        // The person adds a line and a photo; a pdf too; a text file is refused.
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/cases/{$uuid}/messages", ['body' => 'Here is more.'])->assertCreated()->assertJsonPath('data.messages.0.from', 'me');
        $this->actingAs($this->rider, 'user_api')->post("/api/mobile/v1/rides/cases/{$uuid}/files", ['file' => UploadedFile::fake()->image('photo.jpg')], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.files.0.name', 'photo.jpg')->assertJsonPath('data.files.0.mine', true);
        $this->actingAs($this->rider, 'user_api')->post("/api/mobile/v1/rides/cases/{$uuid}/files", ['file' => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->actingAs($this->rider, 'user_api')->post("/api/mobile/v1/rides/cases/{$uuid}/files", ['file' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain')], ['Accept' => 'application/json'])->assertStatus(422);
        Storage::disk('public')->assertExists(RideCase::query()->sole()->files->first()->path);

        // The team asks for more → the status says so; the person's answer puts it back in review (D-231).
        $this->actingAs($this->admin, 'admin_api')->postJson("/api/admin/v1/ride-cases/{$case->id}/status", ['status' => 'needs_info'])->assertOk()->assertJsonPath('data.status', 'needs_info');
        $this->actingAs($this->admin, 'admin_api')->postJson("/api/admin/v1/ride-cases/{$case->id}/messages", ['body' => 'Which gate was it?'])->assertCreated();
        $this->actingAs($this->admin, 'admin_api')->postJson("/api/admin/v1/ride-cases/{$case->id}/messages", ['body' => 'Looks like a repeat complaint.', 'internal' => true])->assertCreated();
        $seen = $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/cases/{$uuid}")->assertOk()->assertJsonPath('data.status_label', 'We need more from you');
        $this->assertSame(['me', 'support'], array_column($seen->json('data.messages'), 'from'), 'the private note stays private');
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/cases/{$uuid}/messages", ['body' => 'Gate 3.'])->assertCreated()->assertJsonPath('data.status', 'in_review');
        $this->assertSame(4, count($this->actingAs($this->admin, 'admin_api')->getJson("/api/admin/v1/ride-cases/{$case->id}")->json('data.messages')), 'the admin sees all four lines');

        // Too many files.
        config(['rides.cases.max_files' => 2]);
        $this->actingAs($this->rider, 'user_api')->post("/api/mobile/v1/rides/cases/{$uuid}/files", ['file' => UploadedFile::fake()->image('third.png')], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('error_code', 'rides_case_too_many_files');
    }

    public function test_a_decision_shows_its_reason_and_pays_a_refund_only_when_asked(): void
    {
        $ride = $this->ride(['final_total_minor' => 4000]);
        RideAdjustment::query()->create(['ride_id' => $ride->id, 'type' => 'cancel_fee', 'amount_minor' => 1500, 'affects' => 'rider', 'created_at' => now()]);
        $uuid = $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/cases', ['kind' => 'dispute', 'ride_uuid' => $ride->uuid, 'category' => 'cancel_fee', 'summary' => 'Free minute.'])->assertCreated()->json('data.uuid');
        $case = RideCase::query()->sole();
        $this->asAdmin(['ride-cases.view', 'ride-cases.update']);
        $wallets = app(WalletService::class);
        $spendOnly = fn () => $wallets->firstOrCreateWallet($this->rider, $this->saudi)->refresh()->balanceMinor(WalletBucket::SpendOnly);

        // Not permitted without the permission.
        $this->actingAs(Admin::create(['name' => 'B', 'email' => 'b@example.com', 'password' => 'secret123', 'status' => 'active']), 'admin_api')
            ->postJson("/api/admin/v1/ride-cases/{$case->id}/decide", ['outcome' => 'rejected', 'reason' => 'No'])->assertForbidden();
        // A refund larger than the disputed charge, or none of the outcome's kinds, is refused.
        $this->actingAs($this->admin, 'admin_api')->postJson("/api/admin/v1/ride-cases/{$case->id}/decide", ['outcome' => 'confirmed', 'reason' => 'Fair.', 'refund_minor' => 1600, 'apply_refund' => true])->assertStatus(422)->assertJsonPath('error_code', 'rides_case_refund_too_large');
        $this->actingAs($this->admin, 'admin_api')->postJson("/api/admin/v1/ride-cases/{$case->id}/decide", ['outcome' => 'maybe', 'reason' => 'Fair.'])->assertStatus(422);
        $this->assertSame(0, $spendOnly());

        // Partly accepted with a refund of 500: in-app credit, a ledger entry, and the reason to the rider.
        $this->actingAs($this->admin, 'admin_api')->postJson("/api/admin/v1/ride-cases/{$case->id}/decide", ['outcome' => 'partially_confirmed', 'reason' => 'You cancelled 20 s late; half the fee is returned.', 'refund_minor' => 500, 'apply_refund' => true])
            ->assertOk()->assertJsonPath('data.status', 'partially_confirmed')->assertJsonPath('data.decided_by', 'A');
        $this->assertSame(500, $spendOnly());
        $tx = WalletTransaction::query()->sole();
        $this->assertSame(['refund', 'credit', 'spend_only'], [$tx->type->value, $tx->direction->value, $tx->bucket->value]);
        $this->assertSame(500, (int) FinancialEntry::query()->where('wallet_transaction_id', $tx->id)->value('amount_minor'));
        $shown = $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/cases/{$uuid}")->assertOk()->json('data');
        $this->assertSame(['partially_confirmed', 'Partly accepted', 'You cancelled 20 s late; half the fee is returned.', 500, true], [$shown['decision']['outcome'], $shown['status_label'], $shown['decision']['reason'], $shown['decision']['refund_minor'], $shown['decision']['refund_applied']]);
        $this->assertFalse($shown['can_message']);
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/cases/{$uuid}/messages", ['body' => 'Thanks.'])->assertStatus(422)->assertJsonPath('error_code', 'rides_case_closed');
        // Decided once.
        $this->actingAs($this->admin, 'admin_api')->postJson("/api/admin/v1/ride-cases/{$case->id}/decide", ['outcome' => 'rejected', 'reason' => 'Changed my mind.'])->assertStatus(409)->assertJsonPath('error_code', 'rides_case_already_decided');
        $this->assertSame(500, $spendOnly(), 'no second refund');

        // A decision with a refund that isn't applied pays nothing: the amount is only recorded.
        $other = $this->ride(['final_total_minor' => 2000]);
        RideAdjustment::query()->create(['ride_id' => $other->id, 'type' => 'no_show_fee', 'amount_minor' => 700, 'affects' => 'rider', 'created_at' => now()]);
        $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/cases', ['kind' => 'dispute', 'ride_uuid' => $other->uuid, 'category' => 'no_show_fee', 'summary' => 'I was there.'])->assertCreated();
        $second = RideCase::query()->where('category', 'no_show_fee')->sole();
        $this->actingAs($this->admin, 'admin_api')->postJson("/api/admin/v1/ride-cases/{$second->id}/decide", ['outcome' => 'confirmed', 'reason' => 'The GPS shows you were there.', 'refund_minor' => 700])->assertOk();
        $this->assertSame(500, $spendOnly());
        $this->assertFalse((bool) $second->refresh()->decision['refund_applied']);
    }

    public function test_an_appeal_exists_only_where_the_country_allows_it_and_only_once(): void
    {
        $ride = $this->ride(['final_total_minor' => 4000]);
        RideAdjustment::query()->create(['ride_id' => $ride->id, 'type' => 'cancel_fee', 'amount_minor' => 1500, 'affects' => 'rider', 'created_at' => now()]);
        $uuid = $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/cases', ['kind' => 'dispute', 'ride_uuid' => $ride->uuid, 'category' => 'cancel_fee', 'summary' => 'Free minute.'])->assertCreated()->json('data.uuid');
        $case = RideCase::query()->sole();
        $this->asAdmin(['ride-cases.view', 'ride-cases.update']);
        $this->actingAs($this->admin, 'admin_api')->postJson("/api/admin/v1/ride-cases/{$case->id}/decide", ['outcome' => 'rejected', 'reason' => 'The fee stands.', 'refund_minor' => 500, 'apply_refund' => true])->assertOk();

        // Off by default.
        $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/cases/{$uuid}")->assertJsonPath('data.can_appeal', false);
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/cases/{$uuid}/appeal", ['note' => 'Please look again.'])->assertStatus(422)->assertJsonPath('error_code', 'rides_case_cannot_appeal');

        app(RidePolicies::class)->save($this->saudi->id, null, null, null, ['cases.appeal_allowed' => true]);
        RidePolicies::forget();
        $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/cases/{$uuid}")->assertJsonPath('data.can_appeal', true);
        $this->actingAs($this->driver, 'driver_api')->postJson("/api/driver/v1/cases/{$uuid}/appeal", ['note' => 'Not mine.'])->assertStatus(404);
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/cases/{$uuid}/appeal", ['note' => 'Please look again.'])->assertOk()
            ->assertJsonPath('data.status', 'in_review')->assertJsonPath('data.can_appeal', false)->assertJsonPath('data.decision', null);
        $this->assertNotNull($case->refresh()->appealed_at);

        // The second look can change the answer — but a refund already paid isn't paid twice, and there is no second appeal.
        $this->actingAs($this->admin, 'admin_api')->postJson("/api/admin/v1/ride-cases/{$case->id}/decide", ['outcome' => 'confirmed', 'reason' => 'On reflection, you are right.', 'refund_minor' => 500, 'apply_refund' => true])
            ->assertStatus(422)->assertJsonPath('error_code', 'rides_case_refund_already_applied');
        $this->actingAs($this->admin, 'admin_api')->postJson("/api/admin/v1/ride-cases/{$case->id}/decide", ['outcome' => 'confirmed', 'reason' => 'On reflection, you are right.', 'refund_minor' => 500])->assertOk();
        $this->assertSame(1, WalletTransaction::query()->count(), 'the refund is paid once');
        $this->assertTrue((bool) $case->refresh()->decision['refund_applied'], 'and still shown as paid');
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/cases/{$uuid}/appeal", ['note' => 'And again.'])->assertStatus(422)->assertJsonPath('error_code', 'rides_case_cannot_appeal');
        $this->assertSame(['opened', 'decided', 'appealed', 'decided'], $case->events()->orderBy('id')->pluck('type')->all());
    }

    public function test_a_case_is_never_deleted_and_the_team_works_it_urgent_first(): void
    {
        $ride = $this->ride();
        $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/cases', ['kind' => 'complaint', 'ride_uuid' => $ride->uuid, 'category' => 'vehicle', 'summary' => 'The car was dirty.'])->assertCreated();
        $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/cases', ['kind' => 'complaint', 'ride_uuid' => $ride->uuid, 'category' => 'safety', 'summary' => 'Unsafe driving.'])->assertCreated();
        $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/cases', ['kind' => 'complaint', 'ride_uuid' => $ride->uuid, 'category' => 'payment', 'summary' => 'Charged twice.'])->assertCreated();
        $this->asAdmin(['ride-cases.view']);

        $list = $this->actingAs($this->admin, 'admin_api')->getJson('/api/admin/v1/ride-cases?open=1')->assertOk();
        $this->assertSame(['urgent', 'high', 'normal'], array_column($list->json('data'), 'priority'));
        $this->assertSame('Sara Ahmed', $list->json('data.0.reporter.name'));
        $this->actingAs($this->admin, 'admin_api')->getJson('/api/admin/v1/ride-cases?kind=dispute')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->admin, 'admin_api')->getJson('/api/admin/v1/ride-cases?search='.$ride->number)->assertOk()->assertJsonCount(3, 'data');
        // No decide without the update permission.
        $first = RideCase::query()->first();
        $this->actingAs($this->admin, 'admin_api')->postJson("/api/admin/v1/ride-cases/{$first->id}/decide", ['outcome' => 'rejected', 'reason' => 'No'])->assertForbidden();

        // Closing the account or blocking it doesn't touch the case, and deleting is refused (D-238).
        $this->rider->update(['status' => 'blocked']);
        $this->driver->update(['status' => DriverStatus::Blocked]);
        $this->assertSame(3, RideCase::query()->count());
        $this->expectException(LogicException::class);
        $first->delete();
    }

    public function test_a_support_ticket_can_be_about_a_ride(): void
    {
        $ride = $this->ride();
        $other = $this->ride([], User::query()->create(['name' => 'Other', 'phone' => '+966500000009', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]));

        $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/support-tickets', ['title' => 'About my ride', 'body' => 'Something happened.', 'ride_uuid' => $ride->uuid])
            ->assertCreated()->assertJsonPath('data.ride.uuid', $ride->uuid)->assertJsonPath('data.ride.number', $ride->number);
        $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/support-tickets', ['title' => 'Not mine', 'body' => 'Someone else\'s ride.', 'ride_uuid' => $other->uuid])->assertStatus(422);
        $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/support-tickets', ['title' => 'No ride', 'body' => 'Just a question.'])->assertCreated()->assertJsonPath('data.ride', null);
    }

    private function openComplaint(): string
    {
        $ride = $this->ride();

        return $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/cases', [
            'kind' => 'complaint', 'ride_uuid' => $ride->uuid, 'category' => 'price', 'summary' => 'The price was higher than shown.',
        ])->assertCreated()->json('data.uuid');
    }

    private Admin $admin;

    /** @param list<string> $permissions */
    private function asAdmin(array $permissions): void
    {
        $this->admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $this->admin->givePermissionTo($permissions);
        Sanctum::actingAs($this->admin, [], 'admin_api');
    }

    /** @param array<string, mixed> $overrides */
    private function ride(array $overrides = [], ?User $rider = null): Ride
    {
        $this->n++;

        return Ride::query()->create($overrides + [
            'uuid' => (string) Str::uuid(), 'number' => 'DR-CASE-'.$this->n, 'user_id' => ($rider ?? $this->rider)->id, 'driver_id' => $this->driver->id, 'category_id' => $this->categoryId,
            'country_id' => $this->saudi->id, 'currency_id' => $this->saudi->currency_id, 'status' => 'completed', 'pricing_mode' => 'upfront',
            'confirmed_snapshot' => [], 'payment_method' => 'cash', 'payment_status' => 'cash_collected', 'final_total_minor' => 3000,
            'origin_lat' => 24.7136, 'origin_lng' => 46.6753, 'origin_address' => 'Olaya', 'destination_lat' => 24.75, 'destination_lng' => 46.70,
            'completed_at' => now()->subMinutes(10),
        ]);
    }

    private function switches(array $features, bool $enabled = true): void
    {
        foreach ($features as $feature) {
            RideFeatureRule::query()->updateOrCreate(['feature' => $feature, 'country_id' => $this->saudi->id], ['enabled' => $enabled]);
        }
        RideFeatures::forget();
        RidePolicies::forget();
    }
}
