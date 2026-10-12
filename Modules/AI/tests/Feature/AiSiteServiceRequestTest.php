<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\AI\Exceptions\AiSiteException;
use Modules\AI\Models\AiSiteRequestStatus;
use Modules\AI\Models\AiSiteServiceRequest;
use Modules\AI\Models\AiSiteServiceSetting;
use Modules\AI\Services\Sites\AiSiteServiceRequestService;
use Modules\User\Models\User;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/** "Build it with a person": brief, quote, accept + pay, deliveries, instalments, handover. */
class AiSiteServiceRequestTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->currency = Currency::create(['code' => 'EGP', 'symbol' => 'ج.م', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'eg']);
        $this->country = Country::create([
            'code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10,
            'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $this->currency->id, 'status' => true,
        ]);
        app()->instance('resolved_country', $this->country);
    }

    private function user(): User
    {
        return User::query()->create([
            'name' => 'Req User', 'email' => 'req-'.uniqid().'@example.test',
            'password' => bcrypt('not-a-real-password'), 'status' => UserStatus::Active,
        ]);
    }

    private function admin(): void
    {
        Sanctum::actingAs(Admin::query()->create(['name' => 'A', 'email' => 'a-'.uniqid().'@example.test', 'password' => 'password', 'status' => true]), ['*'], 'admin_api');
    }

    private function wallet(User $user, int $minor): Wallet
    {
        $wallet = app(WalletService::class)->firstOrCreateWallet($user, $this->country);
        $wallet->update(['spend_only_minor' => $minor, 'withdrawable_minor' => 0]);

        return $wallet->fresh();
    }

    private function brief(): array
    {
        return ['business_name' => 'Acme Plumbing', 'site_type' => 'business', 'business_description' => 'We fix pipes.', 'contact_name' => 'Ali', 'contact_phone' => '+201001234567'];
    }

    private function service(): AiSiteServiceRequestService
    {
        return app(AiSiteServiceRequestService::class);
    }

    private function reason(callable $fn): ?string
    {
        try {
            $fn();
        } catch (AiSiteException $e) {
            return $e->reason;
        }

        return null;
    }

    private function quoteData(float $amount = 1000, ?array $installments = null): array
    {
        return [
            'amount' => $amount, 'duration_days' => 10, 'notes' => 'Includes 2 revisions',
            'milestones' => [['title' => 'Home page', 'due_days' => 3], ['title' => 'Full site', 'due_days' => 8]],
            'installments' => $installments ?? [
                ['title' => 'First payment', 'amount' => 400, 'trigger' => 'on_accept'],
                ['title' => 'Second payment', 'amount' => 300, 'trigger' => 'on_milestone', 'milestone_index' => 0],
                ['title' => 'Handover payment', 'amount' => 300, 'trigger' => 'on_delivery'],
            ],
        ];
    }

    private function quoted(User $user): AiSiteServiceRequest
    {
        $request = $this->service()->create($user, $this->brief());

        return $this->service()->sendQuote($request, $this->quoteData());
    }

    public function test_the_option_can_be_switched_off_from_the_dashboard(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user, ['*'], 'user_api');

        $this->assertTrue($this->getJson('/api/user/v1/ai-site-requests/info')->json('data.enabled'));

        AiSiteServiceSetting::current()->update(['is_enabled' => false]);

        $this->assertFalse($this->getJson('/api/user/v1/ai-site-requests/info')->json('data.enabled'));
        $this->postJson('/api/user/v1/ai-site-requests', $this->brief())->assertStatus(403);
    }

    public function test_a_customer_sends_a_brief_and_it_starts_as_received(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user, ['*'], 'user_api');

        $response = $this->postJson('/api/user/v1/ai-site-requests', $this->brief() + ['goals' => ['leads'], 'budget_max' => 5000])->assertStatus(201);

        $this->assertSame('submitted', $response->json('data.status.code'));
        $this->assertSame('EGP', $response->json('data.currency'));
        $this->assertStringStartsWith('WS-', $response->json('data.reference'));
        $this->postJson('/api/user/v1/ai-site-requests', ['business_name' => ''])->assertStatus(422);
    }

    public function test_the_whole_flow_from_quote_to_handover(): void
    {
        $user = $this->user();
        $wallet = $this->wallet($user, 100000);
        $request = $this->quoted($user);

        $this->assertSame('quote_sent', $request->status);
        $this->assertSame(3, $request->installments->count());

        $accepted = $this->service()->accept($request, $user);
        $this->assertSame('in_progress', $accepted->status);
        $this->assertSame(100000 - 40000, $wallet->fresh()->spend_only_minor);
        $this->assertEqualsWithDelta(10 * 86400, now()->diffInSeconds($accepted->due_at), 5);

        $second = $accepted->installments->firstWhere('trigger', 'on_milestone');
        $this->assertSame('installment_not_due', $this->reason(fn () => $this->service()->pay($accepted, $second->id, $user)));

        $milestone = $accepted->milestones->first();
        $this->service()->setMilestoneStatus($accepted, $milestone, 'delivered');
        $this->assertSame('due', $second->fresh()->status);

        $this->service()->pay($accepted->fresh(), $second->id, $user);
        $this->assertSame(100000 - 40000 - 30000, $wallet->fresh()->spend_only_minor);

        $this->service()->changeStatus($accepted->fresh(), 'delivered');
        $last = $accepted->fresh()->installments->firstWhere('trigger', 'on_delivery');
        $this->assertSame('due', $last->fresh()->status);
        $this->assertSame('unpaid_installments', $this->reason(fn () => $this->service()->changeStatus($accepted->fresh(), 'completed')));

        $this->service()->updateInternal($accepted->fresh(), ['delivery_url' => 'https://acme.example']);
        $done = $this->service()->pay($accepted->fresh(), $last->id, $user);

        $this->assertSame('completed', $done->status);
        $this->assertSame(0, $wallet->fresh()->spend_only_minor + $wallet->fresh()->withdrawable_minor);
        $this->assertSame('https://acme.example', $this->service()->present($done)['delivery']['url']);
    }

    public function test_instalments_must_add_up_and_include_a_payment_on_acceptance(): void
    {
        $request = $this->service()->create($this->user(), $this->brief());

        $bad = [['title' => 'Only', 'amount' => 500, 'trigger' => 'on_accept']];
        $this->assertSame('quote_invalid_total', $this->reason(fn () => $this->service()->sendQuote($request, $this->quoteData(1000, $bad))));

        $late = [['title' => 'Only', 'amount' => 1000, 'trigger' => 'on_delivery']];
        $this->assertSame('quote_needs_first_payment', $this->reason(fn () => $this->service()->sendQuote($request, $this->quoteData(1000, $late))));
    }

    public function test_accepting_without_enough_money_changes_nothing(): void
    {
        $user = $this->user();
        $wallet = $this->wallet($user, 1000);
        $request = $this->quoted($user);

        $this->assertSame('insufficient_balance', $this->reason(fn () => $this->service()->accept($request, $user)));
        $this->assertSame('quote_sent', $request->fresh()->status);
        $this->assertNull($request->fresh()->due_at);
        $this->assertSame(1000, $wallet->fresh()->spend_only_minor);
        $this->assertSame(0, $request->installments()->where('status', 'paid')->count());
    }

    public function test_an_expired_quote_cannot_be_accepted_and_a_declined_one_can_be_requoted(): void
    {
        $user = $this->user();
        $this->wallet($user, 100000);
        $request = $this->quoted($user);

        $request->forceFill(['quote_valid_until' => now()->subMinute()])->save();
        $this->assertSame('quote_expired', $this->reason(fn () => $this->service()->accept($request->fresh(), $user)));

        $declined = $this->service()->decline($request->fresh(), 'too expensive');
        $this->assertSame('quote_declined', $declined->status);

        $again = $this->service()->sendQuote($declined, $this->quoteData(800, [
            ['title' => 'First', 'amount' => 800, 'trigger' => 'on_accept'],
        ]));
        $this->assertSame('quote_sent', $again->status);
        $this->assertSame(1, $again->installments()->count());
    }

    public function test_customers_only_see_their_own_requests_and_can_chat(): void
    {
        $owner = $this->user();
        $request = $this->service()->create($owner, $this->brief());

        Sanctum::actingAs($this->user(), ['*'], 'user_api');
        $this->getJson('/api/user/v1/ai-site-requests/'.$request->id)->assertStatus(404);

        Sanctum::actingAs($owner, ['*'], 'user_api');
        $this->getJson('/api/user/v1/ai-site-requests/'.$request->id)->assertSuccessful();
        $this->postJson('/api/user/v1/ai-site-requests/'.$request->id.'/messages', ['body' => 'Any news?'])->assertStatus(201);

        $this->admin();
        $this->postJson('/api/admin/v1/ai-site-requests/'.$request->id.'/messages', ['body' => 'Reviewing it now'])->assertStatus(201);
        $messages = $this->getJson('/api/admin/v1/ai-site-requests/'.$request->id.'/messages')->json('data');
        $this->assertSame(['system', 'customer', 'admin'], collect($messages)->pluck('sender')->all());
    }

    public function test_the_admin_runs_the_request_from_the_dashboard_api(): void
    {
        $user = $this->user();
        $this->wallet($user, 100000);
        $request = $this->service()->create($user, $this->brief());

        $this->admin();
        $this->getJson('/api/admin/v1/ai-site-requests')->assertSuccessful()->assertJsonPath('data.0.id', $request->id);
        $this->postJson('/api/admin/v1/ai-site-requests/'.$request->id.'/quote', $this->quoteData())->assertSuccessful()->assertJsonPath('data.status.code', 'quote_sent');
        $this->postJson('/api/admin/v1/ai-site-requests/'.$request->id.'/status', ['status' => 'nope'])->assertStatus(422);
        $this->postJson('/api/admin/v1/ai-site-requests/'.$request->id.'/status', ['status' => 'completed'])->assertStatus(422);

        $this->service()->accept($request->fresh(), $user);
        $this->postJson('/api/admin/v1/ai-site-requests/'.$request->id.'/extend', ['due_at' => now()->addDays(20)->toDateTimeString()])->assertSuccessful();
        $this->postJson('/api/admin/v1/ai-site-requests/'.$request->id.'/status', ['status' => 'on_hold', 'note' => 'waiting for content'])->assertSuccessful()->assertJsonPath('data.status.code', 'on_hold');
    }

    public function test_statuses_are_editable_but_the_workflow_ones_are_protected(): void
    {
        $this->admin();
        $list = $this->getJson('/api/admin/v1/ai-site-requests/statuses')->assertSuccessful()->json('data');
        $this->assertCount(10, $list);

        $system = AiSiteRequestStatus::query()->where('code', 'in_progress')->firstOrFail();
        $this->putJson('/api/admin/v1/ai-site-requests/statuses/'.$system->id, ['name_ar' => 'شغالين عليه', 'name_en' => 'Building', 'color' => 'info', 'is_active' => false])->assertSuccessful();
        $this->assertTrue($system->fresh()->is_active);
        $this->assertSame('Building', $system->fresh()->name_en);
        $this->deleteJson('/api/admin/v1/ai-site-requests/statuses/'.$system->id)->assertStatus(409);

        $custom = $this->postJson('/api/admin/v1/ai-site-requests/statuses', ['code' => 'design_review', 'name_ar' => 'مراجعة التصميم', 'name_en' => 'Design review'])->assertStatus(201)->json('data');
        $this->deleteJson('/api/admin/v1/ai-site-requests/statuses/'.$custom['id'])->assertSuccessful();
    }

    public function test_the_switch_is_managed_from_the_admin_settings(): void
    {
        $this->admin();
        $this->putJson('/api/admin/v1/ai-site-requests/settings', ['is_enabled' => false, 'quote_valid_days' => 14, 'intro_ar' => 'x'])->assertSuccessful();

        $this->assertFalse($this->getJson('/api/admin/v1/ai-site-requests/settings')->json('data.is_enabled'));
        $this->assertSame(14, AiSiteServiceSetting::current()->quote_valid_days);
    }

    public function test_admins_are_notified_of_new_requests_and_customer_actions(): void
    {
        $admin = Admin::query()->create(['name' => 'Boss', 'email' => 'boss-'.uniqid().'@example.test', 'password' => 'password', 'status' => true]);
        $center = $this->spy(\App\Services\Notifications\NotificationCenter::class);
        $center->shouldReceive('adminsWith')->andReturn(collect([$admin]));
        $center->shouldReceive('send')->andReturnNull();

        $user = $this->user();
        $this->wallet($user, 100000);
        $request = $this->service()->create($user, $this->brief());
        $quoted = $this->service()->sendQuote($request, $this->quoteData());
        $this->service()->accept($quoted, $user);
        $this->service()->customerMessage($quoted->fresh(), 'hello');

        foreach (['ai_site_request.admin_new', 'ai_site_request.admin_accepted', 'ai_site_request.admin_message'] as $event) {
            $center->shouldHaveReceived('send')->withArgs(fn ($to, $e) => $e === $event && $to->contains($admin))->once();
        }
    }

    public function test_the_android_form_payload_is_accepted(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user, ['*'], 'user_api');

        // Exactly what the app sends: multipart text fields, arrays as key[0], booleans as 1/0.
        $response = $this->post('/api/user/v1/ai-site-requests', [
            'business_name' => 'Acme', 'site_type' => 'business', 'business_description' => 'We fix pipes fast and cheap.',
            'target_audience' => 'Homeowners', 'goals[0]' => 'Get enquiries', 'pages[0]' => 'Home', 'pages[1]' => 'Contact',
            'features[0]' => 'Contact form', 'languages[0]' => 'Arabic', 'style_notes' => 'modern', 'colors' => 'blue',
            'reference_links[0]' => 'https://example.com', 'has_logo' => '1', 'has_domain' => '0', 'content_ready' => 'partial',
            'budget_min' => '1000', 'budget_max' => '5000.5', 'deadline_days' => '30', 'contact_name' => 'Ali',
            'contact_phone' => '+201001234567', 'contact_method' => 'whatsapp', 'contact_time' => 'evenings', 'notes' => 'thanks',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201);
        $this->assertSame('Acme', AiSiteServiceRequest::query()->firstOrFail()->title);
        $this->assertSame('whatsapp', $response->json('data.brief.contact_method'));
    }
}
