<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Faq;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\User\Events\SupportRealtimeEvent;
use Modules\User\Models\SupportMessage;
use Modules\User\Models\SupportSetting;
use Modules\User\Models\SupportTicket;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Automatic replies in support tickets, built on the AI module: the acknowledgement, the away note outside
 * working hours, and the FAQ answer the AI picks — always marked automatic, never after a person answered,
 * never for money / fraud / complaints, and stopped when the customer asks for a person.
 */
class SupportAutoReplyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Faq $faq;

    /** What the fake OpenAI answers. */
    private string $aiReply = '';

    protected function setUp(): void
    {
        parent::setUp();

        // The FAQ answer runs "after the response"; here, right away.
        $this->withoutDefer();

        $flag = Flag::create(['code' => 'sa']);
        $currency = Currency::create(['code' => 'SAR', 'symbol' => 'ر.س']);
        Country::create([
            'code' => 'SA', 'code_alpha3' => 'SAU', 'dial_code' => '+966', 'phone_starts_with' => '5', 'phone_length' => 9,
            'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $currency->id, 'status' => true,
        ]);

        // Both languages are published, so a customer is answered in theirs.
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $code => $direction) {
            Language::create(['code' => $code, 'direction' => $direction, 'is_default_website' => $code === 'en', 'is_default_dashboard' => $code === 'en', 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        }

        $this->user = User::query()->create(['phone' => '+966501234567', 'phone_verified_at' => now(), 'status' => UserStatus::Active]);
        $this->user->forceFill(['locale' => 'en'])->save();

        $this->faq = Faq::query()->create(['status' => true, 'sort_order' => 1]);
        $this->faq->translations()->create(['locale' => 'en', 'question' => 'How do I add money to my wallet?', 'answer' => 'Open the wallet and tap Top up, then choose a payment method.']);
        $this->faq->translations()->create(['locale' => 'ar', 'question' => 'كيف أضيف رصيداً لمحفظتي؟', 'answer' => 'افتح المحفظة واضغط شحن ثم اختر طريقة الدفع.']);

        app(AiProviderRepository::class)->ensureDefaults();
        AiProvider::query()->where('key', 'openai')->firstOrFail()->update(['is_enabled' => true, 'is_default' => true, 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);

        Http::fake([
            'api.openai.com/v1/chat/completions' => fn () => Http::response(['choices' => [['message' => ['content' => $this->aiReply]]]]),
        ]);

        // Working hours that cover "now" unless a test moves the clock.
        $this->openAllWeek();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, string> */
    private function asUser(): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$this->user->createToken('mobile-app')->plainTextToken, 'Accept' => 'application/json'];
    }

    /** @param list<string> $permissions */
    private function admin(array $permissions): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app['auth']->forgetGuards();

        $admin = Admin::create(['name' => 'Agent '.uniqid(), 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    private function openAllWeek(): void
    {
        // from === to: open the whole day, every day.
        SupportSetting::current()->update(['hours' => array_fill(0, 7, ['open' => true, 'from' => '00:00', 'to' => '00:00'])]);
    }

    private function answerWithFaq(string $answer): void
    {
        $this->aiReply = json_encode(['faq_id' => $this->faq->id, 'answer' => $answer]);
    }

    private function open(string $title, string $body): SupportTicket
    {
        $this->postJson('/api/mobile/v1/support-tickets', ['title' => $title, 'body' => $body], $this->asUser())->assertCreated();

        return SupportTicket::query()->latest('id')->firstOrFail();
    }

    /** @return list<string> */
    private function autoKinds(SupportTicket $ticket): array
    {
        return $ticket->messages()->where('is_auto', true)->orderBy('id')->pluck('auto_kind')->all();
    }

    // ------------------------------------------------------------------ acknowledgement + FAQ answer

    public function test_a_new_ticket_gets_the_acknowledgement_then_the_ai_answer_from_the_faq(): void
    {
        Event::fake([SupportRealtimeEvent::class]);
        $this->answerWithFaq('Open your wallet, tap Top up and pick a payment method.');

        $ticket = $this->open('Top up', 'How can I put money in my wallet?');

        $this->assertSame(['ack', 'faq'], $this->autoKinds($ticket));

        $messages = $this->getJson("/api/mobile/v1/support-tickets/{$ticket->id}/messages", $this->asUser())->assertOk()->json('data');
        $this->assertSame(['user', 'system', 'system'], array_column($messages, 'sender'));
        $this->assertTrue($messages[1]['is_auto']);
        $this->assertStringContainsString('#'.$ticket->id, $messages[1]['body'], 'the acknowledgement names the ticket');
        $this->assertSame('faq', $messages[2]['auto_kind']);
        $this->assertSame('Open your wallet, tap Top up and pick a payment method.', $messages[2]['body']);
        $this->assertNull($messages[2]['agent_name'], 'never in an agent\'s name');

        // Built on the AI module: one chat call that carries the published FAQs and the ticket.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'chat/completions')
            && str_contains($r['messages'][0]['content'], 'How do I add money to my wallet?')
            && str_contains($r['messages'][1]['content'], 'How can I put money in my wallet?'));

        // Live, like any message — and the FAQ answer is pushed to the customer.
        Event::assertDispatched(SupportRealtimeEvent::class, fn ($e) => $e->event === 'support.message' && ($e->payload['message']['auto_kind'] ?? null) === 'faq');
        $this->assertContains('support_ticket_auto_title', $this->user->notifications->pluck('data.title')->all());
    }

    public function test_no_faq_answer_when_none_fits_or_the_ai_is_off_or_unavailable(): void
    {
        $this->aiReply = json_encode(['faq_id' => null, 'answer' => null]);
        $this->assertSame(['ack'], $this->autoKinds($this->open('Feature idea', 'Please add dark mode to the calendar')));

        // An id the model was not shown is never trusted.
        $this->aiReply = json_encode(['faq_id' => 999, 'answer' => 'made up']);
        $this->assertSame(['ack'], $this->autoKinds($this->open('Question', 'How do I add money?')));

        $this->answerWithFaq('From the FAQ');
        SupportSetting::current()->update(['ai_enabled' => false]);
        $this->assertSame(['ack'], $this->autoKinds($this->open('Question', 'How do I add money?')));

        SupportSetting::current()->update(['ai_enabled' => true]);
        AiProvider::query()->update(['is_enabled' => false]);
        $this->assertSame(['ack'], $this->autoKinds($this->open('Question', 'How do I add money?')));
    }

    public function test_money_fraud_complaints_and_high_stakes_questions_go_to_a_person(): void
    {
        $this->answerWithFaq('From the FAQ');

        foreach ([
            ['Refund', 'I was charged twice, I want a refund'],
            ['فلوس', 'اتخصم مني المبلغ ومحدش رد'],
            ['Scam', 'Someone scammed me and my account was hacked'],
            ['Help', 'I want to talk to a real person'],
            ['Medical', 'what medicine dose should I take for my fever'],
        ] as [$title, $body]) {
            $this->assertSame(['ack'], $this->autoKinds($this->open($title, $body)), $body);
        }

        // The AI was never asked.
        Http::assertNothingSent();
    }

    public function test_the_ai_answers_at_most_the_configured_number_of_times(): void
    {
        SupportSetting::current()->update(['ai_max_replies' => 1]);
        $this->answerWithFaq('From the FAQ');

        $ticket = $this->open('Top up', 'How do I add money?');
        $this->postJson("/api/mobile/v1/support-tickets/{$ticket->id}/messages", ['body' => 'And how do I top up again?'], $this->asUser())->assertCreated();

        $this->assertSame(['ack', 'faq'], $this->autoKinds($ticket));
    }

    // ------------------------------------------------------------------ away

    public function test_outside_working_hours_the_away_note_replaces_the_acknowledgement_once_per_window(): void
    {
        $this->aiReply = json_encode(['faq_id' => null, 'answer' => null]);
        // Sunday–Thursday 09:00–18:00 Riyadh; it is Friday evening.
        SupportSetting::current()->update(['hours' => SupportSetting::defaultHours(), 'timezone' => 'Asia/Riyadh', 'away_every_hours' => 6]);
        Carbon::setTestNow(Carbon::parse('2026-10-09 20:00', 'Asia/Riyadh'));

        $ticket = $this->open('Login', 'I cannot log in');
        $away = $ticket->messages()->where('auto_kind', 'away')->firstOrFail();
        $this->assertStringContainsString('Sun–Thu 09:00–18:00', $away->body, 'the working hours, in the customer\'s language');

        // Writing again an hour later: no second note.
        Carbon::setTestNow(now()->addHour());
        $this->postJson("/api/mobile/v1/support-tickets/{$ticket->id}/messages", ['body' => 'still waiting'], $this->asUser())->assertCreated();
        $this->assertSame(['away'], $this->autoKinds($ticket));

        // Seven hours later, still closed (Saturday): one more.
        Carbon::setTestNow(now()->addHours(7));
        $this->postJson("/api/mobile/v1/support-tickets/{$ticket->id}/messages", ['body' => 'hello?'], $this->asUser())->assertCreated();
        $this->assertSame(['away', 'away'], $this->autoKinds($ticket));
    }

    public function test_arabic_customers_get_arabic_texts_and_the_admin_can_write_their_own(): void
    {
        // The language the customer's app uses (RememberLocale keeps it on the account).
        $this->user->forceFill(['locale' => 'ar'])->save();
        $this->aiReply = json_encode(['faq_id' => null, 'answer' => null]);

        $ticket = $this->open('مشكلة', 'عندي مشكلة في الدخول');
        $this->assertStringContainsString('استلمنا تذكرتك', $ticket->messages()->where('auto_kind', 'ack')->value('body'));

        SupportSetting::current()->update(['ack_message' => ['ar' => 'أهلاً، تذكرتك رقم :id وصلتنا', 'en' => '']]);
        $second = $this->open('مشكلة', 'تاني');
        $this->assertSame('أهلاً، تذكرتك رقم '.$second->id.' وصلتنا', $second->messages()->where('auto_kind', 'ack')->value('body'));
    }

    // ------------------------------------------------------------------ never after a person, never when off

    public function test_no_automatic_reply_once_an_agent_answered_or_when_switched_off(): void
    {
        $this->answerWithFaq('From the FAQ');
        $ticket = $this->open('Top up', 'How do I add money?');

        $this->admin(['support-tickets.view', 'support-tickets.reply']);
        $this->postJson("/api/admin/v1/support-tickets/{$ticket->id}/messages", ['body' => 'Hi, I am on it'])->assertCreated();

        $this->postJson("/api/mobile/v1/support-tickets/{$ticket->id}/messages", ['body' => 'How do I add money again?'], $this->asUser())->assertCreated();
        $this->assertSame(['ack', 'faq'], $this->autoKinds($ticket), 'the conversation is the agent\'s now');

        // The automatic reply never took the ticket for anyone.
        $this->assertSame(1, SupportMessage::query()->where('sender', 'support')->count());

        SupportSetting::current()->update(['auto_reply_enabled' => false]);
        $this->assertSame([], $this->autoKinds($this->open('Top up', 'How do I add money?')));
    }

    // ------------------------------------------------------------------ the customer's answer

    public function test_still_need_a_person_stops_the_automatic_replies_and_tells_the_team(): void
    {
        $agent = $this->admin(['support-tickets.view']);
        $this->answerWithFaq('From the FAQ');
        $ticket = $this->open('Top up', 'How do I add money?');

        $this->postJson("/api/mobile/v1/support-tickets/{$ticket->id}/auto-reply-feedback", ['solved' => false], $this->asUser())
            ->assertOk()
            ->assertJsonPath('data.auto_reply_stopped', true)
            ->assertJsonPath('data.status', 'opened');

        $this->postJson("/api/mobile/v1/support-tickets/{$ticket->id}/messages", ['body' => 'How do I add money?'], $this->asUser())->assertCreated();
        $this->assertSame(['ack', 'faq'], $this->autoKinds($ticket));
        $this->assertContains('support_ticket_wants_agent_title', $agent->notifications->pluck('data.title')->all());
    }

    public function test_that_solved_it_closes_the_ticket(): void
    {
        $this->answerWithFaq('From the FAQ');
        $ticket = $this->open('Top up', 'How do I add money?');

        $this->postJson("/api/mobile/v1/support-tickets/{$ticket->id}/auto-reply-feedback", ['solved' => true], $this->asUser())
            ->assertOk()->assertJsonPath('data.status', 'closed');
        $this->postJson("/api/mobile/v1/support-tickets/{$ticket->id}/auto-reply-feedback", ['solved' => false], $this->asUser())->assertUnprocessable();
    }

    // ------------------------------------------------------------------ dashboard

    public function test_admin_reads_and_updates_the_settings(): void
    {
        $this->admin(['support-settings.view']);
        $this->getJson('/api/admin/v1/support-settings')->assertOk()
            ->assertJsonPath('data.auto_reply_enabled', true)
            ->assertJsonPath('data.ai_available', true)
            ->assertJsonPath('data.faqs_count', 1);
        $this->putJson('/api/admin/v1/support-settings', [])->assertForbidden();

        $this->admin(['support-settings.view', 'support-settings.update']);
        $payload = [
            'auto_reply_enabled' => true, 'ack_enabled' => false, 'ack_message' => ['ar' => '', 'en' => 'Got it'],
            'away_enabled' => true, 'away_message' => ['ar' => 'غير متاحين', 'en' => ''],
            'hours' => SupportSetting::defaultHours(), 'timezone' => 'Africa/Cairo', 'away_every_hours' => 12,
            'ai_enabled' => true, 'ai_max_replies' => 3,
        ];
        $this->putJson('/api/admin/v1/support-settings', $payload)->assertOk()
            ->assertJsonPath('data.ack_enabled', false)
            ->assertJsonPath('data.ack_message.en', 'Got it')
            ->assertJsonPath('data.timezone', 'Africa/Cairo')
            ->assertJsonPath('data.ai_max_replies', 3);

        $this->putJson('/api/admin/v1/support-settings', ['timezone' => 'Mars/Base'] + $payload)->assertUnprocessable()->assertJsonValidationErrors('timezone');
        $this->putJson('/api/admin/v1/support-settings', ['hours' => [['open' => true, 'from' => '25:00', 'to' => '1']]] + $payload)->assertUnprocessable();
    }

    public function test_quick_replies_are_managed_in_settings_and_listed_for_agents(): void
    {
        $this->admin(['support-settings.view', 'support-settings.update']);
        $id = $this->postJson('/api/admin/v1/support-settings/quick-replies', ['shortcut' => '/Refund', 'title' => 'Refund policy', 'body' => 'Refunds take 5 working days.'])
            ->assertCreated()->assertJsonPath('data.shortcut', 'refund')->json('data.id');
        $this->postJson('/api/admin/v1/support-settings/quick-replies', ['shortcut' => 'refund', 'title' => 'x', 'body' => 'y'])->assertUnprocessable();
        $this->postJson('/api/admin/v1/support-settings/quick-replies', ['shortcut' => 'off', 'title' => 'Hidden', 'body' => 'z', 'status' => false])->assertCreated();
        $this->putJson("/api/admin/v1/support-settings/quick-replies/{$id}", ['shortcut' => 'refund', 'title' => 'Refunds', 'body' => 'Refunds take 5 days.'])->assertOk();

        // Agents see the active ones only, for the "/" menu.
        $this->admin(['support-tickets.reply']);
        $this->getJson('/api/admin/v1/support-tickets/quick-replies')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Refunds');

        $this->admin(['support-tickets.view']);
        $this->getJson('/api/admin/v1/support-tickets/quick-replies')->assertForbidden();

        $this->admin(['support-settings.update']);
        $this->deleteJson("/api/admin/v1/support-settings/quick-replies/{$id}")->assertSuccessful();
    }
}
