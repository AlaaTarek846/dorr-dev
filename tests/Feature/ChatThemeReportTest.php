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
use Modules\Admin\Models\Admin;
use Modules\Chat\Models\ChatBlock;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatReport;
use Modules\Chat\Models\ChatReportType;
use Modules\Chat\Models\ChatTheme;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Chat themes (admin catalogue → a person's pick per chat, default fallback) and reports
 * (reason + evidence copy + optional block; admin review).
 */
class ChatThemeReportTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'sa']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $sar->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);

        $this->alice = User::create(['name' => 'Alice', 'phone' => '+966500000001', 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->bob = User::create(['name' => 'Bob', 'phone' => '+966500000002', 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);

        Storage::fake('public');
    }

    public function test_admin_manages_themes_with_a_single_default(): void
    {
        $this->asAdmin(['chat-themes.view', 'chat-themes.create', 'chat-themes.update', 'chat-themes.delete']);

        $first = $this->post('/api/admin/v1/chat-themes', [
            'translations' => [['locale' => 'en', 'name' => 'Ocean']],
            'sender_color' => '#0EA5E9', 'receiver_color' => '#FFFFFF', 'background_color' => '#E0F2FE',
            'is_default' => true, 'wallpaper' => UploadedFile::fake()->image('w.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.name', 'Ocean')->json('data');
        $this->assertNotNull($first['wallpaper']);

        $second = $this->post('/api/admin/v1/chat-themes', [
            'translations' => [['locale' => 'en', 'name' => 'Night']],
            'sender_color' => '#7C3AED', 'receiver_color' => '#1F2937', 'is_dark' => true, 'is_default' => true,
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->assertFalse(ChatTheme::find($first['id'])->is_default);
        $this->assertTrue(ChatTheme::find($second['id'])->is_default);

        $this->post('/api/admin/v1/chat-themes', ['translations' => [['locale' => 'en', 'name' => 'Bad']], 'sender_color' => 'red', 'receiver_color' => '#FFF'], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->getJson('/api/admin/v1/chat-themes')->assertOk()->assertJsonCount(2, 'data');
        $this->deleteJson("/api/admin/v1/chat-themes/{$first['id']}")->assertOk();
        $this->assertNull(ChatTheme::find($first['id']));
    }

    public function test_a_person_picks_a_theme_and_falls_back_to_the_default(): void
    {
        $default = $this->theme('Classic', true);
        $ocean = $this->theme('Ocean');
        $hidden = $this->theme('Hidden');
        $hidden->update(['status' => false]);
        app(\Modules\Chat\Services\ChatThemeService::class)->flush();

        $this->as($this->alice);
        $this->getJson('/api/mobile/v1/chat/themes', $this->headers())->assertOk()->assertJsonCount(2, 'data');

        $chat = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->assertOk()->json('data');
        $this->assertSame($default->id, $chat['theme']['applied']['id']);

        $this->patchJson("/api/mobile/v1/chat/conversations/{$chat['id']}/settings", ['theme_id' => $ocean->id], $this->headers())
            ->assertOk()->assertJsonPath('data.theme.theme_id', $ocean->id)->assertJsonPath('data.theme.applied.name', 'Ocean');

        $this->patchJson("/api/mobile/v1/chat/conversations/{$chat['id']}/settings", ['theme_id' => $hidden->id], $this->headers())->assertStatus(422);

        // The admin removes the picked theme: the chat quietly goes back to the default.
        $ocean->delete();
        app(\Modules\Chat\Services\ChatThemeService::class)->flush();
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat['id']}", $this->headers())->assertOk()->assertJsonPath('data.theme.applied.id', $default->id);
    }

    public function test_reporting_a_chat_copies_the_last_messages_and_can_block(): void
    {
        $type = $this->reportType('Spam');
        ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $this->alice->id, 'name' => 'Bob', 'phone' => $this->bob->phone, 'contact_type' => 'user', 'contact_id' => $this->bob->id, 'source' => 'manual']);

        $this->as($this->alice);
        $chat = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->json('data');
        foreach (['one', 'two', 'three'] as $body) {
            $this->postJson("/api/mobile/v1/chat/conversations/{$chat['id']}/messages", ['type' => 'text', 'body' => $body], $this->headers())->assertCreated();
        }

        $this->getJson('/api/mobile/v1/chat/report-types', $this->headers())->assertOk()->assertJsonPath('data.0.name', 'Spam');

        $this->postJson("/api/mobile/v1/chat/conversations/{$chat['id']}/report", ['report_type_id' => $type->id, 'details' => 'Keeps sending ads', 'block' => true], $this->headers())
            ->assertCreated();

        $report = ChatReport::query()->with(['messages', 'reportedUsers'])->sole();
        $this->assertSame(['one', 'two', 'three'], $report->messages->pluck('body')->all());
        $this->assertSame($this->bob->id, (int) $report->reportedUsers->sole()->participant_id);
        $this->assertTrue(ChatBlock::query()->where('blocker_id', $this->alice->id)->where('blocked_id', $this->bob->id)->exists());

        // An inactive reason can't be used.
        $type->update(['status' => false]);
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat['id']}/report", ['report_type_id' => $type->id], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'chat_report_type_invalid');
    }

    public function test_someone_outside_the_chat_cannot_report_it(): void
    {
        $type = $this->reportType('Spam');
        $this->as($this->alice);
        $chat = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->json('data');

        $carol = User::create(['name' => 'Carol', 'phone' => '+966500000003', 'country_id' => $this->alice->country_id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->as($carol);
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat['id']}/report", ['report_type_id' => $type->id], $this->headers())->assertStatus(403);
    }

    public function test_admin_reviews_reports(): void
    {
        $type = $this->reportType('Harassment');
        $this->as($this->alice);
        $chat = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->json('data');
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat['id']}/messages", ['type' => 'text', 'body' => 'hello'], $this->headers());
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat['id']}/report", ['report_type_id' => $type->id], $this->headers())->assertCreated();
        $id = ChatReport::query()->value('id');

        $this->asAdmin(['chat-reports.view']);
        $this->getJson('/api/admin/v1/chat-reports?status=pending')->assertOk()
            ->assertJsonPath('data.0.reporter.name', 'Alice')
            ->assertJsonPath('data.0.reported.0.name', 'Bob')
            ->assertJsonPath('data.0.type.name', 'Harassment');
        $this->getJson("/api/admin/v1/chat-reports/{$id}")->assertOk()->assertJsonPath('data.messages.0.body', 'hello')->assertJsonPath('data.messages.0.is_reporter', true);
        $this->putJson("/api/admin/v1/chat-reports/{$id}", ['status' => 'resolved'])->assertForbidden();

        $this->asAdmin(['chat-reports.view', 'chat-reports.update'], 'b@example.com');
        $this->putJson("/api/admin/v1/chat-reports/{$id}", ['status' => 'resolved', 'admin_note' => 'Warned'])->assertOk()
            ->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.admin_note', 'Warned');
    }

    public function test_admin_manages_report_types(): void
    {
        $this->asAdmin(['chat-report-types.view', 'chat-report-types.create', 'chat-report-types.update', 'chat-report-types.delete']);

        $id = $this->postJson('/api/admin/v1/chat-report-types', ['translations' => [['locale' => 'en', 'name' => 'Fraud']]])->assertCreated()->json('data.id');
        $this->putJson("/api/admin/v1/chat-report-types/{$id}", ['translations' => [['locale' => 'en', 'name' => 'Scam']], 'status' => false])->assertOk()->assertJsonPath('data.name', 'Scam')->assertJsonPath('data.status', false);
        $this->getJson('/api/admin/v1/chat-report-types')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/admin/v1/chat-report-types/{$id}")->assertOk();
        $this->assertSame(0, ChatReportType::query()->count());
    }

    public function test_the_website_uses_the_same_chat_api(): void
    {
        $this->as($this->alice);
        $chat = $this->postJson('/api/user/v1/chat/conversations/direct', ['participant_id' => $this->bob->id])->assertOk()->json('data');
        $this->postJson("/api/user/v1/chat/conversations/{$chat['id']}/messages", ['type' => 'text', 'body' => 'from the web'])->assertCreated();

        // The same chat, seen from the app.
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat['id']}/messages", $this->headers())->assertOk()->assertJsonPath('data.messages.0.body', 'from the web');

        $this->as($this->bob);
        $this->getJson('/api/user/v1/chat/conversations?filter=requests')->assertOk()->assertJsonPath('data.0.id', $chat['id']);
    }

    // ================================================================ helpers

    private function theme(string $name, bool $default = false): ChatTheme
    {
        $theme = ChatTheme::query()->create(['sender_color' => '#111111', 'receiver_color' => '#EEEEEE', 'is_default' => $default]);
        $theme->translations()->create(['locale' => 'en', 'name' => $name]);
        app(\Modules\Chat\Services\ChatThemeService::class)->flush();

        return $theme;
    }

    private function reportType(string $name): ChatReportType
    {
        $type = ChatReportType::query()->create([]);
        $type->translations()->create(['locale' => 'en', 'name' => $name]);

        return $type->refresh();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function asAdmin(array $permissions, string $email = 'a@example.com'): void
    {
        $admin = Admin::create(['name' => 'A', 'email' => $email, 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');
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
