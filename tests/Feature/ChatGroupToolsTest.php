<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Educational / work groups: files behind signed per-viewer links checked on every download and
 * announcements (GR-008), notice settings (GR-009), the activity log (GR-010).
 */
class ChatGroupToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $amal;

    private User $badr;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'sa']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $sar->id, 'status' => true]);
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $code => $direction) {
            Language::create(['code' => $code, 'direction' => $direction, 'is_default_website' => $code === 'en', 'is_default_dashboard' => $code === 'en', 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        }

        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->teacher = $make('Teacher', '+966500000001');
        $this->amal = $make('Amal', '+966500000002');
        $this->badr = $make('Badr', '+966500000003');
    }

    public function test_files_load_only_through_a_signed_link_for_a_member_and_stop_when_they_lose_access(): void
    {
        $group = $this->group('education');

        $this->as($this->teacher);
        $this->post("/api/mobile/v1/chat/conversations/{$group}/messages", [
            'type' => 'image', 'files' => [UploadedFile::fake()->image('board.jpg', 300, 200)],
        ], $this->headers() + ['Accept' => 'application/json'])->assertCreated();

        // Badr's own link: signed, through the app, not a public /storage address.
        $this->as($this->badr);
        $url = collect($this->getJson("/api/mobile/v1/chat/conversations/{$group}/messages", $this->headers())->json('data.messages'))->firstWhere('type', 'image')['attachments'][0]['url'];
        $this->assertStringContainsString('/chat/media/', $url);
        $this->assertStringNotContainsString('/storage/', $url);
        $this->get($url)->assertOk();

        // Tampered or another viewer's: refused.
        $this->get(str_replace('v=user%3A'.$this->badr->id, 'v=user%3A'.$this->amal->id, $url))->assertForbidden();

        // Removed from the group: the saved link stops working at once.
        $this->as($this->teacher);
        $badrRow = collect($this->getJson("/api/mobile/v1/chat/groups/{$group}/members", $this->headers())->json('data'))->firstWhere('profile.account_name', 'Badr')['participant_id'];
        $this->deleteJson("/api/mobile/v1/chat/groups/{$group}/members/{$badrRow}", [], $this->headers())->assertOk();
        $this->get($url)->assertNotFound();

        // An ordinary group keeps its public files.
        $plain = $this->postJson('/api/mobile/v1/chat/groups', ['name' => 'Friends', 'members' => [$this->amal->id]], $this->headers())->json('data.conversation.id');
        $this->post("/api/mobile/v1/chat/conversations/{$plain}/messages", [
            'type' => 'image', 'files' => [UploadedFile::fake()->image('cat.jpg', 300, 200)],
        ], $this->headers() + ['Accept' => 'application/json'])->assertCreated();
        $this->assertStringContainsString('/storage/', collect($this->getJson("/api/mobile/v1/chat/conversations/{$plain}/messages", $this->headers())->json('data.messages'))->firstWhere('type', 'image')['attachments'][0]['url']);
    }

    public function test_announcements_are_posted_by_those_allowed_pinned_and_marked(): void
    {
        $group = $this->group('work');

        // A team member (default role) may not announce.
        $this->as($this->amal);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/announcements", ['body' => 'Hi all'], $this->headers())->assertForbidden();

        $this->as($this->teacher);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/announcements", ['body' => 'Meeting at 10'], $this->headers())->assertCreated()
            ->assertJsonPath('data.meta.announcement', true);

        $this->as($this->amal);
        $this->getJson("/api/mobile/v1/chat/groups/{$group}/announcements", $this->headers())->assertOk()
            ->assertJsonPath('data.0.body', 'Meeting at 10');
        $this->getJson("/api/mobile/v1/chat/conversations/{$group}/pinned", $this->headers())->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_notice_settings_and_the_activity_log(): void
    {
        $group = $this->group('work');

        $this->as($this->amal);
        $this->getJson("/api/mobile/v1/chat/groups/{$group}/notification-prefs", $this->headers())->assertOk()->assertJsonPath('data.muted', []);
        $this->putJson("/api/mobile/v1/chat/groups/{$group}/notification-prefs", ['prefs' => ['roles' => false, 'spaces' => true]], $this->headers())
            ->assertOk()->assertJsonPath('data.muted', ['roles']);
        $this->putJson("/api/mobile/v1/chat/groups/{$group}/notification-prefs", ['prefs' => ['nope' => false]], $this->headers())->assertStatus(422);

        // The log: for audit.view (the manager), not for a team member.
        $this->getJson("/api/mobile/v1/chat/groups/{$group}/audit-log", $this->headers())->assertForbidden();
        $this->as($this->teacher);
        $this->patchJson("/api/mobile/v1/chat/groups/{$group}/settings", ['only_admins_send' => true], $this->headers())->assertOk();
        $this->getJson("/api/mobile/v1/chat/groups/{$group}/audit-log", $this->headers())->assertOk()
            ->assertJsonPath('data.items.0.action', 'settings_changed')
            ->assertJsonPath('data.items.0.actor.account_name', 'Teacher')
            ->assertJsonPath('data.items.0.after.only_admins_send', true);
    }

    private function group(string $kind): string
    {
        $this->as($this->teacher);

        return $this->postJson('/api/mobile/v1/chat/groups', [
            'name' => 'Class', 'kind' => $kind, 'members' => [$this->amal->id, $this->badr->id],
        ], $this->headers())->assertCreated()->json('data.conversation.id');
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
