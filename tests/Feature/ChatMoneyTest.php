<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatContact;
use Modules\User\Models\User;
use Modules\Wallet\Database\Seeders\FinancialCategorySeeder;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletSetting;
use Modules\Wallet\Services\PinService;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/**
 * Money requests (direct chats) and bill splits (groups): paid with the wallet PIN as a real
 * transfer to the requester — once only, whatever happens.
 */
class ChatMoneyTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private User $alice;

    private User $bob;

    private User $carol;

    protected function setUp(): void
    {
        parent::setUp();

        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'sa']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $sar->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        $this->seed(FinancialCategorySeeder::class);
        WalletSetting::query()->where('country_id', $this->saudi->id)->update(['transfers_enabled' => true]);

        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->alice = $make('Alice', '+966500000001');
        $this->bob = $make('Bob', '+966500000002');
        $this->carol = $make('Carol', '+966500000003');

        foreach ([$this->alice, $this->bob, $this->carol] as $user) {
            app(PinService::class)->set($user, '1234');
        }
        foreach ([[$this->alice, $this->bob], [$this->bob, $this->alice], [$this->alice, $this->carol], [$this->carol, $this->alice]] as [$owner, $contact]) {
            ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
        }
    }

    public function test_a_money_request_is_paid_once_with_the_pin(): void
    {
        $this->fund($this->bob, 10000);
        $chat = $this->direct($this->alice, $this->bob);

        $id = $this->send($this->alice, $chat, ['type' => 'money_request', 'amount_minor' => 2500, 'body' => 'Dinner 🍕'])
            ->assertCreated()
            ->assertJsonPath('data.payment.kind', 'request')
            ->assertJsonPath('data.payment.amount_minor', 2500)
            ->assertJsonPath('data.payment.currency', 'SAR')
            ->assertJsonPath('data.payment.can_pay', false)
            ->assertJsonPath('data.payment.can_cancel', true)
            ->json('data.id');

        // Alice can't pay her own request; Bob needs his PIN.
        $this->as($this->alice);
        $this->pay($id)->assertStatus(403);
        $this->as($this->bob);
        $this->pay($id, '0000')->assertStatus(422)->assertJsonPath('error_code', 'wallet_pin_invalid');
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/messages", $this->headers())->assertJsonPath('data.messages.0.payment.can_pay', true);

        $this->pay($id)->assertOk()->assertJsonPath('data.payment.status', 'paid')->assertJsonPath('data.payment.can_pay', false);
        $this->assertSame(7500, $this->walletOf($this->bob)->withdrawable_minor + $this->walletOf($this->bob)->spend_only_minor);
        $this->assertSame(2500, $this->walletOf($this->alice)->spend_only_minor);

        // Paying again changes nothing.
        $this->pay($id)->assertStatus(422)->assertJsonPath('error_code', 'chat_money_request_closed');
        $this->assertSame(2500, $this->walletOf($this->alice)->spend_only_minor);
    }

    public function test_a_request_can_be_declined_or_cancelled_and_is_never_forwarded(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $first = $this->send($this->alice, $chat, ['type' => 'money_request', 'amount_minor' => 1000])->json('data.id');
        $second = $this->send($this->alice, $chat, ['type' => 'money_request', 'amount_minor' => 1000])->json('data.id');

        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/messages/{$first}/decline-request", [], $this->headers())->assertOk()->assertJsonPath('data.payment.status', 'declined');
        $this->pay($first)->assertStatus(422);
        $this->postJson("/api/mobile/v1/chat/messages/{$second}/cancel-request", [], $this->headers())->assertStatus(403);

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/messages/{$second}/cancel-request", [], $this->headers())->assertOk()->assertJsonPath('data.payment.status', 'cancelled');
        $this->postJson('/api/mobile/v1/chat/messages/forward', ['messages' => [$second], 'conversations' => [$chat]], $this->headers())->assertStatus(422);
    }

    public function test_money_requests_are_for_direct_chats_and_amounts_must_be_real(): void
    {
        $group = $this->group($this->alice, [$this->bob, $this->carol]);
        $this->send($this->alice, $group, ['type' => 'money_request', 'amount_minor' => 100])->assertStatus(422)->assertJsonPath('error_code', 'chat_money_request_direct_only');

        $chat = $this->direct($this->alice, $this->bob);
        $this->send($this->alice, $chat, ['type' => 'money_request', 'amount_minor' => 0])->assertStatus(422);
    }

    public function test_a_bill_split_equally_is_paid_share_by_share(): void
    {
        $this->fund($this->bob, 10000);
        $this->fund($this->carol, 10000);
        $group = $this->group($this->alice, [$this->bob, $this->carol]);
        $members = $this->members($this->alice, $group);

        // 100.00 between three: 33.34 + 33.33 + 33.33 — always adds up.
        $split = $this->send($this->alice, $group, [
            'type' => 'bill_split', 'amount_minor' => 10000, 'body' => 'Groceries',
            'split_mode' => 'equal', 'split_participants' => array_values($members),
        ])->assertCreated()->assertJsonPath('data.payment.kind', 'split')->assertJsonPath('data.payment.paid_minor', 3334)->json('data');

        $this->assertSame([3334, 3333, 3333], array_column($split['payment']['shares'], 'amount_minor'));
        $this->assertSame('owner', $split['payment']['shares'][0]['status']);

        $this->as($this->bob);
        $this->pay($split['id'])->assertOk()->assertJsonPath('data.payment.my_share.status', 'paid')->assertJsonPath('data.payment.status', 'open');
        $this->as($this->carol);
        $this->pay($split['id'])->assertOk()->assertJsonPath('data.payment.status', 'settled')->assertJsonPath('data.payment.paid_minor', 10000);

        $this->assertSame(6666, $this->walletOf($this->alice)->spend_only_minor);
    }

    public function test_a_custom_split_must_add_up(): void
    {
        $group = $this->group($this->alice, [$this->bob, $this->carol]);
        $m = $this->members($this->alice, $group);

        $this->send($this->alice, $group, [
            'type' => 'bill_split', 'amount_minor' => 5000, 'split_mode' => 'custom',
            'split_shares' => [['participant_id' => $m['bob'], 'amount_minor' => 2000], ['participant_id' => $m['carol'], 'amount_minor' => 2000]],
        ])->assertStatus(422)->assertJsonPath('error_code', 'chat_split_invalid');

        $this->send($this->alice, $group, [
            'type' => 'bill_split', 'amount_minor' => 5000, 'split_mode' => 'custom',
            'split_shares' => [['participant_id' => $m['bob'], 'amount_minor' => 3000], ['participant_id' => $m['carol'], 'amount_minor' => 2000]],
        ])->assertCreated()->assertJsonPath('data.payment.shares.0.amount_minor', 3000);

        // Carol isn't in this one, so she has nothing to pay.
        $only = $this->send($this->alice, $group, [
            'type' => 'bill_split', 'amount_minor' => 1000, 'split_mode' => 'equal', 'split_participants' => [$m['alice'], $m['bob']],
        ])->json('data.id');
        $this->as($this->carol);
        $this->pay($only)->assertStatus(422);
    }

    public function test_not_enough_balance_leaves_the_share_unpaid(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $id = $this->send($this->alice, $chat, ['type' => 'money_request', 'amount_minor' => 5000])->json('data.id');

        $this->as($this->bob);
        $this->pay($id)->assertStatus(422);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/messages", $this->headers())->assertJsonPath('data.messages.0.payment.status', 'pending');
    }

    // ================================================================ helpers

    private function fund(User $user, int $minor): void
    {
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->firstOrCreateWallet($user, $this->saudi), $minor, WalletBucket::Withdrawable, WalletTransactionType::Topup);
    }

    private function walletOf(User $user): Wallet
    {
        return Wallet::query()->where('owner_type', 'user')->where('owner_id', $user->id)->firstOrFail()->refresh();
    }

    private function pay(string $messageId, string $pin = '1234'): TestResponse
    {
        return $this->postJson("/api/mobile/v1/chat/messages/{$messageId}/pay", [], $this->headers() + ['X-Wallet-Pin' => $pin]);
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user, [], 'user_api');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['X-Country' => 'SA'];
    }

    private function direct(User $me, User $other): string
    {
        $this->as($me);

        return $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $other->id], $this->headers())->assertOk()->json('data.id');
    }

    /**
     * @param  list<User>  $members
     */
    private function group(User $owner, array $members): string
    {
        $this->as($owner);

        return $this->postJson('/api/mobile/v1/chat/groups', ['name' => 'Flat', 'members' => array_map(fn (User $u) => $u->id, $members)], $this->headers())
            ->assertCreated()->json('data.conversation.id');
    }

    /**
     * @return array<string, int> lower-case name => participant id
     */
    private function members(User $viewer, string $group): array
    {
        $this->as($viewer);

        return collect($this->getJson("/api/mobile/v1/chat/groups/{$group}/members", $this->headers())->json('data'))
            ->mapWithKeys(fn ($row) => [strtolower($row['profile']['account_name']) => $row['participant_id']])
            ->sortBy(fn ($id, $name) => array_search($name, ['alice', 'bob', 'carol'], true))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(User $sender, string $conversation, array $data): TestResponse
    {
        $this->as($sender);

        return $this->postJson("/api/mobile/v1/chat/conversations/{$conversation}/messages", $data, $this->headers());
    }
}
