<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Chat\Models\ChatGroup;
use Modules\Chat\Models\ChatSetting;
use Modules\User\Models\User;
use Modules\Wallet\Database\Seeders\FinancialCategorySeeder;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Services\PinService;
use Modules\Wallet\Services\WalletService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Hidden members: a group setting paid once on the payment screen — members' numbers hidden from
 * each other and no private chats started from the group, names still shown. Priced per country
 * on the chat settings screen.
 */
class ChatHiddenMembersTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private User $owner;

    private User $amal;

    private User $badr;

    protected function setUp(): void
    {
        parent::setUp();

        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'sa']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $sar->id, 'status' => true]);
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $code => $direction) {
            Language::create(['code' => $code, 'direction' => $direction, 'is_default_website' => $code === 'en', 'is_default_dashboard' => $code === 'en', 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        }
        $this->seed(FinancialCategorySeeder::class);

        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->owner = $make('Owner', '+966500000001');
        $this->amal = $make('Amal', '+966500000002');
        $this->badr = $make('Badr', '+966500000003');
        app(PinService::class)->set($this->owner, '1234');
    }

    public function test_the_owner_pays_and_members_lose_each_others_numbers_and_private_chats(): void
    {
        $group = $this->createGroup($this->owner, 'Neighbours', [$this->amal, $this->badr]);

        // Not sold until the admin prices it for the country.
        $this->as($this->owner);
        $this->getJson('/api/mobile/v1/chat/groups/hidden-members', $this->headers())->assertOk()->assertJsonPath('data.available', false);
        $this->checkout($group)->assertStatus(422);

        ChatSetting::current()->update(['hidden_members_prices' => [['country_id' => $this->saudi->id, 'amount_minor' => 1500]]]);
        $this->getJson('/api/mobile/v1/chat/groups/hidden-members', $this->headers())->assertOk()
            ->assertJsonPath('data.available', true)->assertJsonPath('data.amount_minor', 1500)->assertJsonPath('data.currency_code', 'SAR');

        // An ordinary group so far: Amal sees Badr's number.
        $this->assertSame('+966500000003', $this->memberOf($this->amal, $group, 'Badr')['phone']);

        // Only an admin buys it.
        $this->as($this->amal);
        $this->checkout($group)->assertForbidden();

        // A failed payment (no money) leaves an ordinary group.
        $this->as($this->owner);
        $unpaid = $this->checkout($group)->assertCreated()->json('data.id');
        $this->postJson("/api/mobile/v1/wallet/checkouts/{$unpaid}/pay", ['method' => 'wallet'], $this->headers() + ['X-Wallet-Pin' => '1234'])->assertStatus(422);
        $this->assertNull(ChatGroup::query()->firstOrFail()->members_hidden_at);

        $this->fund($this->owner, 5000);
        $paid = $this->checkout($group)->assertCreated()->assertJsonPath('data.amount_minor', 1500)->json('data.id');
        $this->postJson("/api/mobile/v1/wallet/checkouts/{$paid}/pay", ['method' => 'wallet'], $this->headers() + ['X-Wallet-Pin' => '1234'])->assertOk();

        $this->getJson("/api/mobile/v1/chat/conversations/{$group}", $this->headers())->assertOk()->assertJsonPath('data.group.members_hidden', true);
        $this->checkout($group)->assertStatus(409);

        // Names still show; numbers and @usernames don't; no private chat from here.
        $badr = $this->memberOf($this->amal, $group, 'Badr');
        $this->assertNull($badr['phone']);
        $this->assertTrue($badr['is_hidden']);
        $this->assertSame('Badr', $badr['name']);
        $this->assertFalse($this->memberOf($this->amal, $group, 'Amal')['is_hidden']);

        $this->as($this->amal);
        $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->badr->id], $this->headers())
            ->assertForbidden()->assertJsonPath('error_code', 'chat_hidden_member');

        // Known another way (an ordinary group together): the number shows and a private chat opens.
        $this->createGroup($this->badr, 'Football', [$this->amal]);
        $this->assertSame('+966500000003', $this->memberOf($this->amal, $group, 'Badr')['phone']);
        $this->as($this->amal);
        $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->badr->id], $this->headers())->assertOk();
    }

    public function test_the_admin_prices_it_per_country_on_the_chat_settings_screen(): void
    {
        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach (['chat-settings.view', 'chat-settings.update'] as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo(['chat-settings.view', 'chat-settings.update']);
        Sanctum::actingAs($admin, [], 'admin_api');

        $this->putJson('/api/admin/v1/chat-settings', ['hidden_members_prices' => [['country_id' => $this->saudi->id, 'amount_minor' => 0]]])
            ->assertUnprocessable();
        $this->putJson('/api/admin/v1/chat-settings', [
            'hidden_members_enabled' => true,
            'hidden_members_prices' => [['country_id' => $this->saudi->id, 'amount_minor' => 2500]],
        ])->assertOk()->assertJsonPath('data.hidden_members_prices.0.amount_minor', 2500);

        $this->assertSame(2500, ChatSetting::current()->hiddenMembersPriceIn($this->saudi->id));

        // Switched off: not sold anywhere, the prices are kept.
        $this->putJson('/api/admin/v1/chat-settings', ['hidden_members_enabled' => false])->assertOk();
        $this->assertNull(ChatSetting::current()->hiddenMembersPriceIn($this->saudi->id));
    }

    /**
     * @param  list<User>  $members
     */
    private function createGroup(User $by, string $name, array $members): string
    {
        $this->as($by);

        return $this->postJson('/api/mobile/v1/chat/groups', ['name' => $name, 'members' => array_map(fn (User $u) => $u->id, $members)], $this->headers())
            ->assertCreated()->json('data.conversation.id');
    }

    /**
     * @return array<string, mixed>
     */
    private function memberOf(User $viewer, string $group, string $accountName): array
    {
        $this->as($viewer);
        $rows = $this->getJson("/api/mobile/v1/chat/groups/{$group}/members", $this->headers())->assertOk()->json('data');

        return collect($rows)->pluck('profile')->firstWhere('account_name', $accountName);
    }

    private function checkout(string $group): TestResponse
    {
        return $this->postJson('/api/mobile/v1/wallet/checkouts', ['purpose' => 'chat_group_hidden_members', 'reference' => ['group_id' => $group]], $this->headers());
    }

    private function fund(User $user, int $withdrawable): void
    {
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->firstOrCreateWallet($user, $this->saudi), $withdrawable, WalletBucket::Withdrawable, WalletTransactionType::Topup);
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
}
