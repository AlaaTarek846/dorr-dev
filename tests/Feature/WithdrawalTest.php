<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Provider\Models\Provider;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletHoldStatus;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletHold;
use Modules\Wallet\Models\WalletSetting;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Models\WithdrawalMethod;
use Modules\Wallet\Models\WithdrawalRequest;
use Modules\Wallet\Services\PinService;
use Modules\Wallet\Services\WalletService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 8 — payout methods and withdrawal requests (hold → capture / release).
 */
class WithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private const IBAN = 'SA0380000000608010167519';

    private Country $saudi;

    private Provider $me;

    private Provider $other;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $currency = Currency::create(['code' => 'SAR', 'symbol' => 'SAR']);
        $flag = Flag::create(['code' => 'sa']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $currency->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);

        $this->me = Provider::create(['name' => 'Mona', 'email' => 'mona@example.com', 'country_id' => $this->saudi->id, 'status' => 'active']);
        $this->other = Provider::create(['name' => 'Other', 'email' => 'other@example.com', 'country_id' => $this->saudi->id, 'status' => 'active']);
        app(PinService::class)->set($this->me, '1234');
        app(PinService::class)->set($this->other, '1234');

        // 10,000 withdrawable + 500 that can never be withdrawn.
        $this->wallet = app(WalletService::class)->firstOrCreateWallet($this->me, $this->saudi);
        app(WalletService::class)->credit($this->wallet, 10000, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        app(WalletService::class)->credit($this->wallet, 500, WalletBucket::SpendOnly, WalletTransactionType::TopupBonus);

        Sanctum::actingAs($this->me, [], 'provider_api');
    }

    private function headers(array $extra = []): array
    {
        return array_merge(['X-Wallet-Pin' => '1234', 'X-Country' => 'SA'], $extra);
    }

    private function bank(array $overrides = []): array
    {
        return array_merge([
            'type' => 'bank', 'label' => 'Main',
            'data' => ['bank_name' => 'Al Rajhi', 'account_holder' => 'Mona Ali', 'iban' => self::IBAN],
        ], $overrides);
    }

    private function makeMethod(?Provider $owner = null): WithdrawalMethod
    {
        return WithdrawalMethod::create([
            'owner_type' => 'provider', 'owner_id' => ($owner ?? $this->me)->id, 'type' => 'bank',
            'data' => $this->bank()['data'], 'is_favorite' => true,
        ]);
    }

    private function withdraw(int $amount, ?WithdrawalMethod $method = null, string $key = 'key-12345678', array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/provider/v1/wallet/withdrawals', [
            'withdrawal_method_id' => ($method ?? $this->makeMethod())->id,
            'amount_minor' => $amount,
        ], $this->headers(['Idempotency-Key' => $key] + $headers));
    }

    private function admin(array $permissions): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    private function receipt(): UploadedFile
    {
        return UploadedFile::fake()->create('receipt.pdf', 50, 'application/pdf');
    }

    // ----------------------------------------------------------------- methods

    public function test_adding_a_method_needs_the_pin(): void
    {
        $this->postJson('/api/provider/v1/wallet/withdrawal-methods', $this->bank(), ['X-Country' => 'SA'])
            ->assertStatus(422)->assertJsonPath('error_code', 'wallet_pin_required');
        $this->postJson('/api/provider/v1/wallet/withdrawal-methods', $this->bank(), $this->headers(['X-Wallet-Pin' => '9999']))
            ->assertStatus(422)->assertJsonPath('error_code', 'wallet_pin_invalid');

        $this->assertSame(0, WithdrawalMethod::query()->count());
    }

    public function test_a_method_is_stored_encrypted_and_only_returned_masked(): void
    {
        $response = $this->postJson('/api/provider/v1/wallet/withdrawal-methods', $this->bank(), $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.display', 'Al Rajhi ••7519')
            ->assertJsonPath('data.is_favorite', true); // the first one is the favourite

        $this->assertStringNotContainsString(self::IBAN, $response->getContent());
        $this->assertStringNotContainsString(self::IBAN, (string) DB::table('withdrawal_methods')->value('data'));
        $this->assertSame(self::IBAN, WithdrawalMethod::query()->firstOrFail()->data['iban']);

        $this->getJson('/api/provider/v1/wallet/withdrawal-methods', ['X-Country' => 'SA'])
            ->assertOk()->assertJsonMissingExact(['iban' => self::IBAN]);
    }

    public function test_each_type_validates_its_own_fields(): void
    {
        $url = '/api/provider/v1/wallet/withdrawal-methods';

        $this->postJson($url, $this->bank(['data' => ['bank_name' => 'X', 'account_holder' => 'Y', 'iban' => 'short']]), $this->headers())
            ->assertJsonValidationErrors('data.iban');
        $this->postJson($url, ['type' => 'mobile_wallet', 'data' => ['provider_name' => 'STC Pay', 'number' => 'abc']], $this->headers())
            ->assertJsonValidationErrors('data.number');
        $this->postJson($url, ['type' => 'mobile_wallet', 'data' => ['provider_name' => 'STC Pay', 'number' => '0501234567']], $this->headers())
            ->assertCreated()->assertJsonPath('data.display', 'STC Pay ••4567');
    }

    public function test_editing_needs_the_pin_and_only_works_on_my_own_methods(): void
    {
        $mine = $this->makeMethod();
        $theirs = $this->makeMethod($this->other);

        $this->putJson("/api/provider/v1/wallet/withdrawal-methods/{$mine->id}", $this->bank(), ['X-Country' => 'SA'])->assertStatus(422);
        $this->putJson("/api/provider/v1/wallet/withdrawal-methods/{$theirs->id}", $this->bank(), $this->headers())->assertNotFound();
        $this->putJson("/api/provider/v1/wallet/withdrawal-methods/{$mine->id}", $this->bank(['label' => 'Renamed']), $this->headers())
            ->assertOk()->assertJsonPath('data.label', 'Renamed');
    }

    public function test_favorite_moves_and_delete_is_soft(): void
    {
        $first = $this->makeMethod();
        $second = WithdrawalMethod::create(['owner_type' => 'provider', 'owner_id' => $this->me->id, 'type' => 'bank', 'data' => $this->bank()['data']]);

        $this->patchJson("/api/provider/v1/wallet/withdrawal-methods/{$second->id}/favorite", [], ['X-Country' => 'SA'])->assertOk();
        $this->assertFalse($first->fresh()->is_favorite);
        $this->assertTrue($second->fresh()->is_favorite);

        $this->deleteJson("/api/provider/v1/wallet/withdrawal-methods/{$first->id}", [], ['X-Country' => 'SA'])->assertStatus(204);
        $this->assertSoftDeleted('withdrawal_methods', ['id' => $first->id]);
    }

    // --------------------------------------------------------------- requesting

    public function test_a_request_holds_the_money_immediately_without_moving_it(): void
    {
        $this->withdraw(6000)->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.method.display', 'Al Rajhi ••7519');

        $wallet = $this->wallet->fresh();
        $this->assertSame(10000, $wallet->withdrawable_minor, 'balance untouched until approval');
        $this->assertSame(6000, $wallet->held_withdrawable_minor);
        $this->assertSame(4000, $wallet->availableMinor(WalletBucket::Withdrawable));
        $this->assertSame(2, WalletTransaction::query()->count(), 'no withdrawal row yet');

        $hold = WalletHold::query()->firstOrFail();
        $this->assertSame(WalletHoldStatus::Active, $hold->status);
        $this->assertSame('withdrawal_request', $hold->reference_type);
        $this->assertSame(WithdrawalRequest::query()->firstOrFail()->id, $hold->reference_id);
        $this->assertNull($hold->expires_at, 'an admin decides, not a timer');
    }

    public function test_a_request_needs_the_pin(): void
    {
        $method = $this->makeMethod();

        $this->postJson('/api/provider/v1/wallet/withdrawals', ['withdrawal_method_id' => $method->id, 'amount_minor' => 1000], ['X-Country' => 'SA', 'Idempotency-Key' => 'key-12345678'])
            ->assertStatus(422)->assertJsonPath('error_code', 'wallet_pin_required');

        $this->assertSame(0, WithdrawalRequest::query()->count());
        $this->assertSame(0, WalletHold::query()->count());
    }

    public function test_a_second_request_while_one_is_pending_is_refused(): void
    {
        $method = $this->makeMethod();
        $this->withdraw(1000, $method)->assertCreated();

        $this->withdraw(1000, $method, 'another-key-1')->assertStatus(409)->assertJsonPath('error_code', 'withdrawal_pending_exists');
        $this->assertSame(1, WalletHold::query()->count());
    }

    public function test_spend_only_money_can_never_be_withdrawn(): void
    {
        // 10,000 withdrawable + 500 spend_only: asking for 10,200 must fail even
        // though the wallet's total (10,500) would cover it.
        $this->withdraw(10200)->assertStatus(422)->assertJsonPath('error_code', 'insufficient_balance');

        $this->assertSame(0, WalletHold::query()->count());
    }

    public function test_amount_limits_come_from_the_countrys_settings(): void
    {
        WalletSetting::query()->where('country_id', $this->saudi->id)->update(['min_withdrawal_minor' => 2000, 'max_withdrawal_minor' => 8000]);
        $method = $this->makeMethod();

        $this->withdraw(1000, $method)->assertStatus(422)->assertJsonPath('error_code', 'withdrawal_amount_out_of_range');
        $this->withdraw(9000, $method, 'key-87654321')->assertStatus(422)->assertJsonPath('error_code', 'withdrawal_amount_out_of_range');
        $this->withdraw(5000, $method, 'key-00000001')->assertCreated();
    }

    public function test_a_country_without_settings_fails_closed(): void
    {
        WalletSetting::query()->where('country_id', $this->saudi->id)->delete();

        $this->withdraw(1000)->assertStatus(422)->assertJsonPath('error_code', 'wallet_settings_missing');
    }

    public function test_the_same_key_replays_and_a_different_amount_conflicts(): void
    {
        $method = $this->makeMethod();

        $first = $this->withdraw(1000, $method)->assertCreated()->json('data.id');
        $second = $this->withdraw(1000, $method)->assertCreated()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, WithdrawalRequest::query()->count());
        $this->assertSame(1, WalletHold::query()->count());

        $this->withdraw(2000, $method)->assertStatus(409)->assertJsonPath('error_code', 'idempotency_conflict');
    }

    public function test_someone_elses_or_an_inactive_method_cannot_be_used(): void
    {
        $theirs = $this->makeMethod($this->other);
        $this->withdraw(1000, $theirs)->assertNotFound();

        $mine = $this->makeMethod();
        $mine->update(['status' => false]);
        $this->withdraw(1000, $mine, 'key-99999999')->assertStatus(422)->assertJsonPath('error_code', 'withdrawal_method_unavailable');
    }

    // ------------------------------------------------------------------ review

    private function pendingRequest(int $amount = 6000): WithdrawalRequest
    {
        $id = $this->withdraw($amount)->assertCreated()->json('data.id');

        return WithdrawalRequest::query()->findOrFail($id);
    }

    public function test_a_request_tells_the_provider_and_whoever_can_approve_it(): void
    {
        // Created before the request, like real staff who already exist; only approvers hear about it.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('withdrawal-requests.approve', 'admin_api');
        Permission::findOrCreate('withdrawal-requests.view', 'admin_api');
        $approver = Admin::create(['name' => 'Approver', 'email' => 'approver@example.com', 'password' => 'secret123', 'status' => 'active']);
        $approver->givePermissionTo('withdrawal-requests.approve');
        $viewer = Admin::create(['name' => 'Viewer', 'email' => 'viewer@example.com', 'password' => 'secret123', 'status' => 'active']);
        $viewer->givePermissionTo('withdrawal-requests.view');

        $this->withdraw(6000)->assertCreated();

        $mine = $this->me->notifications()->firstOrFail();
        $this->assertSame('wallet_withdrawal_requested_title', $mine->data['title']);
        $this->assertSame('60.00 SAR', $mine->data['variables']['amount']);

        $review = $approver->notifications()->firstOrFail();
        $this->assertSame('wallet_withdrawal_review_title', $review->data['title']);
        $this->assertSame(0, $viewer->notifications()->count(), 'a viewer cannot act on it, so is not pinged');
        $this->assertStringNotContainsString(self::IBAN, json_encode($review->data), 'payout details never travel in a notification');
    }

    public function test_approval_and_rejection_tell_the_provider(): void
    {
        $approved = $this->pendingRequest();
        $admin = $this->admin(['withdrawal-requests.approve', 'withdrawal-requests.view', 'withdrawal-requests.reject']);
        $this->post("/api/admin/v1/withdrawal-requests/{$approved->id}/approve", ['receipt' => $this->receipt()], ['Accept' => 'application/json'])->assertOk();

        $this->assertContains('wallet_withdrawal_paid_title', $this->me->notifications()->pluck('data')->pluck('title')->all());

        Sanctum::actingAs($this->me, [], 'provider_api');
        $rejected = WithdrawalRequest::query()->findOrFail($this->withdraw(2000, null, 'key-second-req')->assertCreated()->json('data.id'));

        Sanctum::actingAs($admin, [], 'admin_api');
        $this->postJson("/api/admin/v1/withdrawal-requests/{$rejected->id}/reject", ['rejection_reason' => 'IBAN does not match'])->assertOk();

        $last = $this->me->notifications()->get()->firstWhere(fn ($n) => $n->data['title'] === 'wallet_withdrawal_rejected_title');
        $this->assertNotNull($last);
        $this->assertSame('IBAN does not match', $last->data['variables']['reason']);
    }

    public function test_approval_captures_the_hold_into_a_withdrawal_row_and_stores_the_receipt(): void
    {
        $request = $this->pendingRequest();
        $admin = $this->admin(['withdrawal-requests.approve', 'withdrawal-requests.view']);

        $this->post("/api/admin/v1/withdrawal-requests/{$request->id}/approve", ['receipt' => $this->receipt(), 'note' => 'Paid via SARIE'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.has_receipt', true);

        $wallet = $this->wallet->fresh();
        $this->assertSame(4000, $wallet->withdrawable_minor);
        $this->assertSame(0, $wallet->held_withdrawable_minor);
        $this->assertSame(500, $wallet->spend_only_minor);

        $tx = WalletTransaction::query()->where('type', WalletTransactionType::Withdrawal)->firstOrFail();
        $this->assertSame(6000, $tx->amount_minor);
        $this->assertSame('withdrawable', $tx->bucket->value);
        $this->assertSame($admin->id, $tx->created_by_id);
        $this->assertSame('withdrawal_request', $tx->reference_type);
        $this->assertSame($request->id, $tx->reference_id);

        $this->assertSame(WalletHoldStatus::Captured, $request->hold->fresh()->status);
        $this->assertSame($admin->id, $request->fresh()->reviewed_by);
    }

    public function test_approval_without_a_receipt_is_refused_and_changes_nothing(): void
    {
        $request = $this->pendingRequest();
        $this->admin(['withdrawal-requests.approve']);

        $this->postJson("/api/admin/v1/withdrawal-requests/{$request->id}/approve", ['note' => 'no file'])
            ->assertStatus(422)->assertJsonValidationErrors('receipt');

        $this->assertSame('pending', $request->fresh()->status->value);
        $this->assertSame(6000, $this->wallet->fresh()->held_withdrawable_minor);
    }

    public function test_rejection_needs_a_reason_and_releases_the_money(): void
    {
        $request = $this->pendingRequest();
        $this->admin(['withdrawal-requests.reject', 'withdrawal-requests.view']);

        $this->postJson("/api/admin/v1/withdrawal-requests/{$request->id}/reject", [])->assertStatus(422)->assertJsonValidationErrors('rejection_reason');
        $this->postJson("/api/admin/v1/withdrawal-requests/{$request->id}/reject", ['rejection_reason' => 'IBAN does not match the account name'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $wallet = $this->wallet->fresh();
        $this->assertSame(10000, $wallet->withdrawable_minor);
        $this->assertSame(0, $wallet->held_withdrawable_minor);
        $this->assertSame(WalletHoldStatus::Released, $request->hold->fresh()->status);
        $this->assertSame(0, WalletTransaction::query()->where('type', WalletTransactionType::Withdrawal)->count());
    }

    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        $request = $this->pendingRequest();
        $this->admin(['withdrawal-requests.approve', 'withdrawal-requests.reject']);

        $this->postJson("/api/admin/v1/withdrawal-requests/{$request->id}/reject", ['rejection_reason' => 'first decision'])->assertOk();

        $this->post("/api/admin/v1/withdrawal-requests/{$request->id}/approve", ['receipt' => $this->receipt()], ['Accept' => 'application/json'])
            ->assertStatus(409)->assertJsonPath('error_code', 'withdrawal_not_pending');
        $this->assertSame(10000, $this->wallet->fresh()->withdrawable_minor);
    }

    public function test_after_a_rejection_the_provider_can_request_again(): void
    {
        $request = $this->pendingRequest();
        $this->admin(['withdrawal-requests.reject']);
        $this->postJson("/api/admin/v1/withdrawal-requests/{$request->id}/reject", ['rejection_reason' => 'wrong details'])->assertOk();

        Sanctum::actingAs($this->me, [], 'provider_api');
        $this->withdraw(3000, null, 'brand-new-key-1')->assertCreated();
    }

    public function test_review_endpoints_need_their_permissions(): void
    {
        $request = $this->pendingRequest();
        $this->admin(['withdrawal-requests.view']);

        $this->getJson('/api/admin/v1/withdrawal-requests')->assertOk();
        $this->postJson("/api/admin/v1/withdrawal-requests/{$request->id}/reject", ['rejection_reason' => 'nope nope'])->assertForbidden();
        $this->post("/api/admin/v1/withdrawal-requests/{$request->id}/approve", ['receipt' => $this->receipt()], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_the_admin_sees_full_payout_details_only_on_the_single_request(): void
    {
        $request = $this->pendingRequest();
        $this->admin(['withdrawal-requests.view']);

        $list = $this->getJson('/api/admin/v1/withdrawal-requests?status=pending')->assertOk();
        $this->assertStringNotContainsString(self::IBAN, $list->getContent());
        $list->assertJsonPath('data.0.owner.name', 'Mona');

        $this->getJson("/api/admin/v1/withdrawal-requests/{$request->id}")
            ->assertOk()->assertJsonPath('data.payout_details.iban', self::IBAN);
    }

    public function test_the_owner_never_receives_the_full_iban(): void
    {
        $request = $this->pendingRequest();

        $body = $this->getJson("/api/provider/v1/wallet/withdrawals/{$request->id}", ['X-Country' => 'SA'])->assertOk()->getContent();
        $list = $this->getJson('/api/provider/v1/wallet/withdrawals', ['X-Country' => 'SA'])->assertOk()->getContent();

        $this->assertStringNotContainsString(self::IBAN, $body);
        $this->assertStringNotContainsString(self::IBAN, $list);
        $this->assertStringNotContainsString('payout_details', $body);
    }

    public function test_only_the_owner_can_see_a_request_and_download_its_receipt(): void
    {
        $request = $this->pendingRequest();
        $this->admin(['withdrawal-requests.approve']);
        $this->post("/api/admin/v1/withdrawal-requests/{$request->id}/approve", ['receipt' => $this->receipt()], ['Accept' => 'application/json'])->assertOk();

        Sanctum::actingAs($this->me, [], 'provider_api');
        $this->get("/api/provider/v1/wallet/withdrawals/{$request->id}/receipt", ['X-Country' => 'SA'])->assertOk();

        Sanctum::actingAs($this->other, [], 'provider_api');
        $this->getJson("/api/provider/v1/wallet/withdrawals/{$request->id}", ['X-Country' => 'SA'])->assertNotFound();
        $this->getJson("/api/provider/v1/wallet/withdrawals/{$request->id}/receipt", ['X-Country' => 'SA'])->assertNotFound();
    }

    public function test_the_receipt_is_stored_on_the_private_disk(): void
    {
        $request = $this->pendingRequest();
        $this->admin(['withdrawal-requests.approve']);
        $this->post("/api/admin/v1/withdrawal-requests/{$request->id}/approve", ['receipt' => $this->receipt()], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame('local', $request->fresh()->getFirstMedia('receipt')->disk);
    }
}
