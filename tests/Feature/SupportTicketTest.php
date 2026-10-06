<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\User\Models\SupportTicket;
use Modules\User\Models\User;
use Tests\TestCase;

class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $flag = Flag::create(['code' => 'sa']);
        $currency = Currency::create(['code' => 'SAR', 'symbol' => 'ر.س']);

        Country::create([
            'code' => 'SA',
            'code_alpha3' => 'SAU',
            'dial_code' => '+966',
            'phone_starts_with' => '5',
            'phone_length' => 9,
            'is_default' => true,
            'flag_id' => $flag->id,
            'currency_id' => $currency->id,
            'status' => true,
        ]);
    }

    public function test_authenticated_user_can_open_a_support_ticket(): void
    {
        Storage::fake('public');

        $user = User::query()->create([
            'phone' => '+966501234567',
            'phone_verified_at' => now(),
            'status' => UserStatus::Active,
        ]);

        $this->post('/api/mobile/v1/support-tickets', [
            'title' => 'Payment issue',
            'body' => 'The wallet top-up did not arrive.',
            'image' => UploadedFile::fake()->image('shot.jpg'),
        ], [
            'Authorization' => 'Bearer '.$user->createToken('mobile-app')->plainTextToken,
            'Accept' => 'application/json',
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Payment issue')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.body', 'The wallet top-up did not arrive.');

        $this->assertDatabaseHas('support_tickets', [
            'user_id' => $user->id,
            'title' => 'Payment issue',
            'status' => 'open',
        ]);

        $ticket = SupportTicket::query()->first();
        $this->assertNotNull($ticket?->image_path);
        Storage::disk('public')->assertExists($ticket->image_path);
    }

    public function test_authenticated_user_lists_only_own_tickets(): void
    {
        $user = User::query()->create([
            'phone' => '+966501234567',
            'phone_verified_at' => now(),
            'status' => UserStatus::Active,
        ]);
        $other = User::query()->create([
            'phone' => '+966509876543',
            'phone_verified_at' => now(),
            'status' => UserStatus::Active,
        ]);

        SupportTicket::query()->create([
            'user_id' => $user->id,
            'title' => 'Mine',
            'body' => 'My issue',
            'status' => 'open',
        ]);
        SupportTicket::query()->create([
            'user_id' => $other->id,
            'title' => 'Theirs',
            'body' => 'Someone else',
            'status' => 'open',
        ]);

        $this->getJson('/api/mobile/v1/support-tickets', [
            'Authorization' => 'Bearer '.$user->createToken('mobile-app')->plainTextToken,
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mine')
            ->assertJsonPath('data.0.body', 'My issue');
    }

    public function test_guest_cannot_open_a_support_ticket(): void
    {
        $this->postJson('/api/mobile/v1/support-tickets', [
            'title' => 'Payment issue',
            'body' => 'The wallet top-up did not arrive.',
        ])->assertUnauthorized();
    }

    public function test_guest_cannot_list_support_tickets(): void
    {
        $this->getJson('/api/mobile/v1/support-tickets')->assertUnauthorized();
    }

    public function test_user_can_send_and_list_support_chat_messages(): void
    {
        $user = User::query()->create([
            'phone' => '+966501234567',
            'phone_verified_at' => now(),
            'status' => UserStatus::Active,
        ]);
        $headers = [
            'Authorization' => 'Bearer '.$user->createToken('mobile-app')->plainTextToken,
        ];

        $this->postJson('/api/mobile/v1/support-chats', [
            'body' => 'I need help with my wallet.',
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.sender', 'user')
            ->assertJsonPath('data.body', 'I need help with my wallet.')
            ->assertJsonPath('data.ticket_id', null);

        $this->getJson('/api/mobile/v1/support-chats', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'I need help with my wallet.');
    }

    public function test_ticket_chat_is_isolated_from_general_support_chat(): void
    {
        $user = User::query()->create([
            'phone' => '+966501234567',
            'phone_verified_at' => now(),
            'status' => UserStatus::Active,
        ]);
        $headers = [
            'Authorization' => 'Bearer '.$user->createToken('mobile-app')->plainTextToken,
        ];
        $ticket = SupportTicket::query()->create([
            'user_id' => $user->id,
            'title' => 'Mine',
            'body' => 'My issue',
            'status' => 'open',
        ]);

        $this->postJson('/api/mobile/v1/support-chats', [
            'body' => 'General help',
        ], $headers)->assertCreated();

        $this->postJson('/api/mobile/v1/support-chats', [
            'body' => 'About this ticket',
            'ticket_id' => $ticket->id,
        ], $headers)->assertCreated();

        $this->getJson('/api/mobile/v1/support-chats', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'General help');

        $this->getJson('/api/mobile/v1/support-chats?ticket_id='.$ticket->id, $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'About this ticket')
            ->assertJsonPath('data.0.ticket_id', $ticket->id);
    }

    public function test_user_cannot_chat_on_someone_elses_ticket(): void
    {
        $user = User::query()->create([
            'phone' => '+966501234567',
            'phone_verified_at' => now(),
            'status' => UserStatus::Active,
        ]);
        $other = User::query()->create([
            'phone' => '+966509876543',
            'phone_verified_at' => now(),
            'status' => UserStatus::Active,
        ]);
        $ticket = SupportTicket::query()->create([
            'user_id' => $other->id,
            'title' => 'Theirs',
            'body' => 'Someone else',
            'status' => 'open',
        ]);

        $this->postJson('/api/mobile/v1/support-chats', [
            'body' => 'Hijack',
            'ticket_id' => $ticket->id,
        ], [
            'Authorization' => 'Bearer '.$user->createToken('mobile-app')->plainTextToken,
        ])->assertNotFound();
    }
}
