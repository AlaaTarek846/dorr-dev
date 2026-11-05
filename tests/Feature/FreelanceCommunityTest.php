<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Models\FreelanceCommunityPost;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerPortfolioItem;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FL-L12: the professional community. Freelancers of one country share posts, questions and finished works, comment with one level of
 * replies, react, save and follow; contact details are refused, writing has a daily limit, reports hide what enough members flag and the
 * staff uphold (and may mute) or dismiss. Clients never reach it.
 */
class FreelanceCommunityTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1/community';

    private const ADMIN = '/api/admin/v1/freelance-community';

    private Country $saudi;

    private Freelancer $sara;

    private Freelancer $omar;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-11-02 06:00:00', 'UTC'));
        $this->saudi = $this->country('SA', '+966', 'SAR');
        $this->enable($this->saudi);

        $this->sara = $this->freelancer('Sara', '+966500000091', $this->saudi);
        $this->omar = $this->freelancer('Omar', '+966500000092', $this->saudi);
    }

    // -------------------------------------------------------------- helpers

    private function country(string $code, string $dial, string $currency): Country
    {
        return Country::create(['code' => $code, 'dial_code' => $dial, 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => strtolower($code)])->id, 'currency_id' => Currency::create(['code' => $currency, 'symbol' => $currency])->id]);
    }

    private function enable(Country $c): void
    {
        foreach (['freelance', 'community'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $c->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
    }

    private function freelancer(string $name, string $phone, Country $c, string $status = 'verified'): Freelancer
    {
        return Freelancer::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => $c->id, 'phone_verified_at' => now(), 'status' => $status]);
    }

    private function as(Freelancer $f): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($f->refresh(), [], 'freelance_api');
    }

    private function asAdmin(array $permissions = ['freelance-community.view', 'freelance-community.update']): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'Staff', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'admin_api');
            $admin->givePermissionTo($permission);
        }
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    private function publish(Freelancer $by, string $body = 'How do you price a logo for a small shop?', string $kind = 'question'): string
    {
        $this->as($by);

        return $this->postJson(self::API.'/posts', ['kind' => $kind, 'body' => $body])->assertCreated()->json('data.uuid');
    }

    // -------------------------------------------------------------- tests

    public function test_a_member_posts_with_images_and_members_of_every_open_country_see_it(): void
    {
        $this->as($this->sara);
        $home = $this->getJson(self::API)->assertOk();
        $this->assertTrue($home->json('data.can_write'));
        $this->assertSame(5, $home->json('data.limits.posts_per_day'));
        $this->assertNotEmpty($home->json('data.rules'));

        $res = $this->post(self::API.'/posts', [
            'kind' => 'post', 'body' => 'Finished a brand identity this week. Lessons learned below.',
            'images' => [UploadedFile::fake()->image('a.jpg', 600, 400), UploadedFile::fake()->image('b.png', 600, 400)],
        ])->assertCreated();
        $uuid = $res->json('data.uuid');
        $this->assertCount(2, $res->json('data.images'));
        $this->assertTrue($res->json('data.author.is_me'));

        // The image link is signed and works without sign-in; unsigned it does not.
        $url = $res->json('data.images.0.url');
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertOk();
        $this->get(strtok($url, '?'))->assertForbidden();

        // Omar in the same country sees it, and so does a freelancer of another open country (open across countries, owner 2026-11-04),
        // with where it was posted from.
        $this->as($this->omar);
        $this->assertSame([$uuid], array_column($this->getJson(self::API.'/feed')->assertOk()->json('data'), 'uuid'));
        $egypt = $this->country('EG', '+20', 'EGP');
        $this->enable($egypt);
        $this->as($this->freelancer('Hany', '+201000000001', $egypt));
        $this->getJson(self::API.'/feed')->assertOk()->assertJsonPath('data.0.uuid', $uuid)->assertJsonPath('data.0.country.code', 'SA');
        $this->getJson(self::API.'/posts/'.$uuid)->assertOk();
        // Once the community is switched off in Saudi, what was posted there is no longer shown elsewhere.
        FreelanceFeatureRule::query()->where('country_id', $this->saudi->id)->where('feature', 'community')->update(['enabled' => false]);
        FreelanceFeatures::forget();
        $this->assertSame([], $this->getJson(self::API.'/feed')->assertOk()->json('data'));
        $this->getJson(self::API.'/posts/'.$uuid)->assertNotFound();
    }

    public function test_clients_and_switched_off_countries_never_reach_it(): void
    {
        $client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        Sanctum::actingAs($client, [], 'user_api');
        $this->getJson(self::API.'/feed')->assertUnauthorized();

        FreelanceFeatureRule::query()->where('feature', 'community')->update(['enabled' => false]);
        FreelanceFeatures::forget();
        $this->as($this->sara);
        $this->getJson(self::API.'/feed')->assertForbidden();
    }

    public function test_contact_details_are_refused_and_unverified_members_only_read(): void
    {
        $this->as($this->sara);
        $this->postJson(self::API.'/posts', ['body' => 'Call me on 0501234567 for the work please'])
            ->assertStatus(422)->assertJsonPath('error_code', 'freelance_community_contact_blocked');
        $this->postJson(self::API.'/posts', ['body' => 'See my work at www.mysite.com today'])
            ->assertStatus(422)->assertJsonPath('error_code', 'freelance_community_contact_blocked');
        $uuid = $this->publish($this->sara);

        $this->as($this->omar);
        $this->postJson(self::API.'/posts/'.$uuid.'/comments', ['body' => 'mail me omar@gmail.com'])
            ->assertStatus(422)->assertJsonPath('error_code', 'freelance_community_contact_blocked');

        $new = $this->freelancer('New', '+966500000099', $this->saudi, 'profile_ready');
        $this->as($new);
        $this->getJson(self::API.'/feed')->assertOk();
        $this->assertFalse($this->getJson(self::API)->json('data.can_write'));
        $this->postJson(self::API.'/posts', ['body' => 'Hello everyone, glad to be here'])->assertForbidden()
            ->assertJsonPath('error_code', 'freelance_community_not_verified');
    }

    public function test_the_daily_limit_stops_a_sixth_post(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->publish($this->sara, "Tip number {$i}: keep your files organised.", 'post');
        }
        $this->as($this->sara);
        $this->postJson(self::API.'/posts', ['body' => 'Tip number 6: one more tip for today.'])->assertStatus(429)
            ->assertJsonPath('error_code', 'freelance_community_daily_posts');

        $this->travel(25)->hours();
        $this->publish($this->sara, 'A new day, a new tip for everyone.', 'post');
    }

    public function test_comments_replies_best_answer_and_notices(): void
    {
        $uuid = $this->publish($this->sara);

        $this->as($this->omar);
        $c = $this->postJson(self::API.'/posts/'.$uuid.'/comments', ['body' => 'Start from the hours it takes and the client size.'])->assertCreated()->json('data.uuid');

        $this->as($this->sara);
        $reply = $this->postJson(self::API.'/posts/'.$uuid.'/comments', ['body' => 'Thank you, that helps.', 'parent' => $c])->assertCreated();
        $this->assertSame($c, $reply->json('data.parent'));
        // A reply to a reply goes under the same comment.
        $this->as($this->omar);
        $this->postJson(self::API.'/posts/'.$uuid.'/comments', ['body' => 'You are welcome!', 'parent' => $reply->json('data.uuid')])->assertCreated()->assertJsonPath('data.parent', $c);

        // Only the question's author marks the best answer.
        $this->postJson(self::API.'/posts/'.$uuid.'/accept', ['comment' => $c])->assertForbidden();
        $this->as($this->sara);
        $this->postJson(self::API.'/posts/'.$uuid.'/accept', ['comment' => $c])->assertOk()->assertJsonPath('data.accepted_comment', $c);

        $show = $this->getJson(self::API.'/posts/'.$uuid)->assertOk();
        $this->assertSame(3, $show->json('data.post.comments'));
        $this->assertTrue($show->json('data.comments.0.accepted'));
        $this->assertCount(2, $show->json('data.comments.0.first_replies'));

        // Sara heard of Omar's comment, Omar of the reply and of the best answer.
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.community.commented')->count());
        $this->assertSame(1, $this->omar->notifications()->where('data->event', 'freelance.community.replied')->count());
        $this->assertSame(1, $this->omar->notifications()->where('data->event', 'freelance.community.answer_accepted')->count());

        // Deleting a comment takes its replies and the count with it.
        $this->as($this->omar);
        $this->deleteJson(self::API.'/comments/'.$c)->assertOk();
        $this->as($this->sara);
        $this->assertSame(0, $this->getJson(self::API.'/posts/'.$uuid)->json('data.post.comments'));
        $this->assertNull($this->getJson(self::API.'/posts/'.$uuid)->json('data.post.accepted_comment'));
    }

    public function test_reactions_saves_and_follows(): void
    {
        $uuid = $this->publish($this->sara);

        $this->as($this->omar);
        $this->putJson(self::API.'/posts/'.$uuid.'/reaction', ['kind' => 'like'])->assertOk()->assertJsonPath('data.reactions', 1);
        $this->putJson(self::API.'/posts/'.$uuid.'/reaction', ['kind' => 'insightful'])->assertOk()->assertJsonPath('data.reactions', 1)->assertJsonPath('data.top.0', 'insightful');
        $this->putJson(self::API.'/posts/'.$uuid.'/reaction', ['kind' => null])->assertOk()->assertJsonPath('data.reactions', 0);
        $this->putJson(self::API.'/posts/'.$uuid.'/reaction', ['kind' => 'celebrate'])->assertOk();

        $this->postJson(self::API.'/posts/'.$uuid.'/save')->assertOk();
        $saved = $this->getJson(self::API.'/feed?tab=saved')->assertOk();
        $this->assertSame($uuid, $saved->json('data.0.uuid'));
        $this->assertTrue($saved->json('data.0.saved'));
        $this->assertSame('celebrate', $saved->json('data.0.my_reaction'));

        // Following: the feed tab, the card and a notice.
        $this->assertSame([], $this->getJson(self::API.'/feed?tab=following')->json('data'));
        $this->postJson(self::API.'/members/'.$this->sara->uuid.'/follow')->assertOk()->assertJsonPath('data.is_following', true)->assertJsonPath('data.followers', 1);
        $this->assertSame($uuid, $this->getJson(self::API.'/feed?tab=following')->json('data.0.uuid'));
        $this->assertSame($this->omar->uuid, $this->getJson(self::API.'/members/'.$this->sara->uuid.'/followers')->json('data.0.uuid'));
        $this->postJson(self::API.'/members/'.$this->omar->uuid.'/follow')->assertStatus(422);
        $this->deleteJson(self::API.'/members/'.$this->sara->uuid.'/follow')->assertOk()->assertJsonPath('data.is_following', false);
    }

    public function test_a_finished_work_is_shared_only_from_my_own_visible_portfolio(): void
    {
        $mine = FreelancerPortfolioItem::query()->create(['freelancer_id' => $this->sara->id, 'title' => 'Shop logo', 'visibility' => 'public', 'rights_confirmed_at' => now()]);
        $private = FreelancerPortfolioItem::query()->create(['freelancer_id' => $this->sara->id, 'title' => 'Secret', 'visibility' => 'private', 'rights_confirmed_at' => now()]);
        $omars = FreelancerPortfolioItem::query()->create(['freelancer_id' => $this->omar->id, 'title' => 'Not mine', 'visibility' => 'public', 'rights_confirmed_at' => now()]);

        $this->as($this->sara);
        foreach ([$private, $omars] as $item) {
            $this->postJson(self::API.'/posts', ['kind' => 'work', 'body' => 'Happy to share this finished work.', 'portfolio_item' => $item->uuid])
                ->assertStatus(422)->assertJsonPath('error_code', 'freelance_community_work_invalid');
        }
        $this->postJson(self::API.'/posts', ['kind' => 'work', 'body' => 'Happy to share this finished work.', 'portfolio_item' => $mine->uuid])
            ->assertCreated()->assertJsonPath('data.work.title', 'Shop logo')->assertJsonPath('data.kind', 'work');
    }

    public function test_three_reports_hide_a_post_until_the_staff_decide_and_a_mute_stops_writing(): void
    {
        $uuid = $this->publish($this->sara, 'Buy my course now, best price in town for everyone', 'post');

        $this->as($this->sara);
        $this->postJson(self::API.'/reports', ['subject_type' => 'post', 'subject' => $uuid, 'reason' => 'spam'])->assertStatus(422);

        $this->as($this->omar);
        $this->postJson(self::API.'/reports', ['subject_type' => 'post', 'subject' => $uuid, 'reason' => 'spam'])->assertCreated();
        $this->postJson(self::API.'/reports', ['subject_type' => 'post', 'subject' => $uuid, 'reason' => 'spam'])->assertStatus(409);
        foreach (['Ali' => '+966500000093', 'Huda' => '+966500000094'] as $name => $phone) {
            $this->as($this->freelancer($name, $phone, $this->saudi));
            $this->postJson(self::API.'/reports', ['subject_type' => 'post', 'subject' => $uuid, 'reason' => 'spam'])->assertCreated();
        }

        // Hidden for everyone but its author, who sees why.
        $this->as($this->omar);
        $this->getJson(self::API.'/posts/'.$uuid)->assertNotFound();
        $this->as($this->sara);
        $this->assertTrue($this->getJson(self::API.'/posts/'.$uuid)->assertOk()->json('data.post.hidden.under_review'));

        // The staff uphold one report: the others close with it and Sara is muted for 3 days.
        $this->asAdmin();
        $this->getJson(self::ADMIN.'/overview')->assertOk()->assertJsonPath('data.reports.open', 3);
        $reports = $this->getJson(self::ADMIN.'/reports?status=open')->assertOk();
        $this->assertSame(3, $reports->json('data.0.subject.reports'));
        $this->postJson(self::ADMIN.'/reports/'.$reports->json('data.0.uuid').'/decide', ['decision' => 'uphold', 'note' => 'Advertising', 'mute_days' => 3])->assertOk();
        $this->getJson(self::ADMIN.'/overview')->assertJsonPath('data.reports.open', 0)->assertJsonPath('data.reports.upheld', 3)->assertJsonPath('data.members.muted', 1);
        $this->postJson(self::ADMIN.'/reports/'.$reports->json('data.0.uuid').'/decide', ['decision' => 'dismiss'])->assertStatus(409);

        $this->as($this->sara);
        $this->assertFalse($this->getJson(self::API)->json('data.can_write'));
        $this->postJson(self::API.'/posts', ['body' => 'Another post while muted please'])->assertForbidden()->assertJsonPath('error_code', 'freelance_community_muted');
        $this->travel(4)->days();
        $this->publish($this->sara, 'Back after a short break, sorry everyone.', 'post');
    }

    public function test_a_dismissed_report_brings_back_what_reports_alone_hid_and_the_staff_hide_by_hand(): void
    {
        config(['freelance.community.auto_hide_reports' => 1]);
        $uuid = $this->publish($this->sara, 'A real question about client briefs and scope.', 'question');
        $this->as($this->omar);
        $this->postJson(self::API.'/reports', ['subject_type' => 'post', 'subject' => $uuid, 'reason' => 'off_topic'])->assertCreated();
        $this->assertSame(FreelanceCommunityPost::HIDDEN, FreelanceCommunityPost::query()->where('uuid', $uuid)->value('status'));

        $this->asAdmin();
        $report = $this->getJson(self::ADMIN.'/reports')->json('data.0.uuid');
        $this->postJson(self::ADMIN.'/reports/'.$report.'/decide', ['decision' => 'dismiss'])->assertOk();
        $this->assertSame(FreelanceCommunityPost::PUBLISHED, FreelanceCommunityPost::query()->where('uuid', $uuid)->value('status'));

        $this->as($this->omar);
        $this->postJson(self::API.'/posts/'.$uuid.'/comments', ['body' => 'Write the scope in the agreement.'])->assertCreated();
        $this->asAdmin();
        $this->assertSame('Write the scope in the agreement.', $this->getJson(self::ADMIN.'/posts/'.$uuid)->assertOk()->json('data.comments.0.body'));

        $this->postJson(self::ADMIN.'/posts/'.$uuid.'/hide', ['reason' => 'Off topic'])->assertOk();
        $this->assertSame('hidden', $this->getJson(self::ADMIN.'/posts?status=hidden')->json('data.0.status'));
        $this->postJson(self::ADMIN.'/posts/'.$uuid.'/unhide')->assertOk();

        // Read-only staff can not decide.
        $this->asAdmin(['freelance-community.view']);
        $this->getJson(self::ADMIN.'/reports')->assertOk();
        $this->postJson(self::ADMIN.'/posts/'.$uuid.'/hide', ['reason' => 'Nope'])->assertForbidden();
    }

    public function test_only_the_author_edits_or_deletes(): void
    {
        $uuid = $this->publish($this->sara);
        $this->as($this->omar);
        $this->putJson(self::API.'/posts/'.$uuid, ['body' => 'Changed by someone else here'])->assertForbidden();
        $this->deleteJson(self::API.'/posts/'.$uuid)->assertForbidden();

        $this->as($this->sara);
        $this->putJson(self::API.'/posts/'.$uuid, ['body' => 'How do you price a logo for a big shop?'])->assertOk()->assertJsonPath('data.edited', true);
        $this->deleteJson(self::API.'/posts/'.$uuid)->assertOk();
        $this->getJson(self::API.'/posts/'.$uuid)->assertNotFound();
    }
}
