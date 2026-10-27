<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerPortfolioItem;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** FL-105: the portfolio — works with private images, a video link, visibility, the rights confirmation, and the admin's hide. */
class FreelancerPortfolioTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private Freelancer $me;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
        Storage::fake('public');
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        FreelanceFeatureRule::query()->create(['feature' => 'freelance', 'country_id' => $this->saudi->id, 'enabled' => true]);
        FreelanceFeatures::forget();
        $this->me = $this->freelancer('+966500000077');
    }

    private function freelancer(string $phone, string $status = 'verified'): Freelancer
    {
        $f = Freelancer::query()->create(['name' => 'Sara', 'phone' => $phone, 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => $status]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($f, [], 'freelance_api');

        return $f;
    }

    private function asClient(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]), [], 'user_api');
    }

    private function asGuest(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeaders([]);
    }

    private function work(array $extra = []): array
    {
        return $this->postJson('/api/freelance/v1/profile/portfolio', $extra + ['title' => 'Logo for a bakery', 'description' => 'Brand identity', 'visibility' => 'private'])->assertCreated()->json('data');
    }

    private function image(int $id, string $name = 'work.jpg'): array
    {
        return $this->post("/api/freelance/v1/profile/portfolio/{$id}/images", ['image' => UploadedFile::fake()->image($name, 600, 400)], ['Accept' => 'application/json'])->assertCreated()->json('data');
    }

    // -------------------------------------------------------------- the owner

    public function test_a_work_is_added_edited_ordered_and_deleted(): void
    {
        $skill = FreelanceSkill::query()->create(['slug' => 'figma', 'status' => 'active']);
        $skill->translations()->create(['locale' => 'en', 'name' => 'Figma', 'normalized' => 'figma']);
        $spec = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $spec->translations()->create(['locale' => 'en', 'name' => 'Design']);

        $first = $this->work(['specialty_id' => $spec->id, 'skill_ids' => [$skill->id], 'completed_on' => '2024-05-01']);
        $second = $this->work(['title' => 'Second']);
        $this->assertSame('Design', $first['specialty']['name']);
        $this->assertSame('Figma', $first['skills'][0]['name']);

        $this->putJson('/api/freelance/v1/profile/portfolio/order', ['ids' => [$second['id'], $first['id']]])->assertOk()->assertJsonPath('data.0.title', 'Second');
        $this->putJson('/api/freelance/v1/profile/portfolio/order', ['ids' => [$second['id']]])->assertOk(); // a partial order is fine for the ones named
        $this->putJson('/api/freelance/v1/profile/portfolio/order', ['ids' => [$second['id'], 9999]])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_portfolio_order_invalid');

        $this->putJson("/api/freelance/v1/profile/portfolio/{$first['id']}", ['title' => 'Renamed', 'skill_ids' => []])->assertOk()->assertJsonPath('data.title', 'Renamed')->assertJsonCount(0, 'data.skills');
        $this->deleteJson("/api/freelance/v1/profile/portfolio/{$first['id']}")->assertOk();
        $this->getJson('/api/freelance/v1/profile/portfolio')->assertJsonCount(1, 'data');
    }

    public function test_the_description_is_plain_text_and_bad_values_are_refused(): void
    {
        $item = $this->work(['description' => '<b>Bold</b><script>alert(1)</script> text', 'title' => '<i>Logo</i>']);
        $this->assertStringNotContainsString('<', $item['description'].$item['title']);

        foreach ([['title' => 'x'], ['external_url' => 'javascript:alert(1)'], ['external_url' => 'ftp://x.com/a'], ['completed_on' => now()->addDay()->toDateString()], ['visibility' => 'everyone'], ['skill_ids' => [9999]]] as $bad) {
            $this->postJson('/api/freelance/v1/profile/portfolio', $bad + ['title' => 'Work'])->assertUnprocessable();
        }
    }

    public function test_a_work_shown_to_others_needs_the_rights_confirmed(): void
    {
        $this->postJson('/api/freelance/v1/profile/portfolio', ['title' => 'Shop', 'visibility' => 'public'])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_portfolio_rights_required');
        $this->postJson('/api/freelance/v1/profile/portfolio', ['title' => 'Shop', 'visibility' => 'clients_only', 'rights_confirmed' => false])->assertUnprocessable();

        $ok = $this->work(['title' => 'Shop', 'visibility' => 'public', 'rights_confirmed' => true]);
        $this->assertTrue($ok['rights_confirmed']);
        $this->assertNotNull(FreelancerPortfolioItem::query()->find($ok['id'])->rights_confirmed_at);

        // Taking the confirmation back needs the work to go private first.
        $this->putJson("/api/freelance/v1/profile/portfolio/{$ok['id']}", ['rights_confirmed' => false])->assertUnprocessable();
        $this->putJson("/api/freelance/v1/profile/portfolio/{$ok['id']}", ['rights_confirmed' => false, 'visibility' => 'private'])->assertOk()->assertJsonPath('data.visibility', 'private');
    }

    public function test_the_limits_hold(): void
    {
        config(['freelance.profile.max_portfolio' => 2, 'freelance.profile.max_portfolio_images' => 2]);
        $a = $this->work();
        $this->work(['title' => 'Two']);
        $this->postJson('/api/freelance/v1/profile/portfolio', ['title' => 'Three'])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_profile_limit');

        $this->image($a['id']);
        $this->image($a['id'], 'b.jpg');
        $this->post("/api/freelance/v1/profile/portfolio/{$a['id']}/images", ['image' => UploadedFile::fake()->image('c.jpg', 600, 400)], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_profile_limit');
    }

    // -------------------------------------------------------------- videos and links

    public function test_only_youtube_and_vimeo_links_are_taken_and_shown_as_embeds(): void
    {
        foreach ([
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'https://youtube.com/shorts/dQw4w9WgXcQ' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'https://vimeo.com/123456789' => 'https://player.vimeo.com/video/123456789',
        ] as $link => $embed) {
            $this->postJson('/api/freelance/v1/profile/portfolio', ['title' => 'Video', 'video_url' => $link])->assertCreated()->assertJsonPath('data.video.embed_url', $embed);
        }
        foreach (['https://evil.com/watch?v=dQw4w9WgXcQ', 'https://youtube.com.evil.com/watch?v=dQw4w9WgXcQ', 'javascript:alert(1)', 'https://www.youtube.com/watch?v=short', 'https://vimeo.com/abc', 'not a url'] as $bad) {
            $this->postJson('/api/freelance/v1/profile/portfolio', ['title' => 'Video', 'video_url' => $bad])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_portfolio_video_invalid');
        }
        // An empty link clears it.
        $id = $this->postJson('/api/freelance/v1/profile/portfolio', ['title' => 'Video', 'video_url' => 'https://vimeo.com/123456789'])->json('data.id');
        $this->putJson("/api/freelance/v1/profile/portfolio/{$id}", ['video_url' => ''])->assertOk()->assertJsonPath('data.video', null);
    }

    // -------------------------------------------------------------- images

    public function test_images_are_private_and_checked(): void
    {
        $work = $this->work();
        $withImage = $this->image($work['id']);
        $image = $withImage['images'][0];

        $this->assertNull($image['url'], 'the owner gets a signed-in path, not a link');
        $media = FreelancerPortfolioItem::query()->find($work['id'])->getFirstMedia('images');
        $this->assertSame('local', $media->disk);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $res = $this->get("/api/freelance/v1/{$image['path']}")->assertOk();
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));

        foreach ([UploadedFile::fake()->create('x.exe', 10), UploadedFile::fake()->create('big.jpg', 5121, 'image/jpeg'), UploadedFile::fake()->image('tiny.jpg', 50, 50), UploadedFile::fake()->createWithContent('doc.pdf', "%PDF-1.4\n%%EOF")] as $bad) {
            $this->post("/api/freelance/v1/profile/portfolio/{$work['id']}/images", ['image' => $bad], ['Accept' => 'application/json'])->assertUnprocessable();
        }

        $this->deleteJson("/api/freelance/v1/profile/portfolio/{$work['id']}/images/{$image['id']}")->assertOk()->assertJsonCount(0, 'data.images');
        $this->deleteJson("/api/freelance/v1/profile/portfolio/{$work['id']}/images/9999")->assertNotFound();
    }

    public function test_another_freelancer_cannot_touch_my_works(): void
    {
        $work = $this->work();
        $image = $this->image($work['id'])['images'][0];
        $this->freelancer('+966500000078');

        $this->putJson("/api/freelance/v1/profile/portfolio/{$work['id']}", ['title' => 'Stolen'])->assertNotFound();
        $this->deleteJson("/api/freelance/v1/profile/portfolio/{$work['id']}")->assertNotFound();
        $this->get("/api/freelance/v1/{$image['path']}")->assertNotFound();
        $this->post("/api/freelance/v1/profile/portfolio/{$work['id']}/images", ['image' => UploadedFile::fake()->image('a.jpg', 600, 400)], ['Accept' => 'application/json'])->assertNotFound();
        $this->getJson('/api/freelance/v1/profile/portfolio')->assertJsonCount(0, 'data');
    }

    // -------------------------------------------------------------- what others see

    public function test_visibility_decides_who_sees_a_work(): void
    {
        $public = $this->work(['title' => 'Public', 'visibility' => 'public', 'rights_confirmed' => true]);
        $this->work(['title' => 'Clients', 'visibility' => 'clients_only', 'rights_confirmed' => true]);
        $this->work(['title' => 'Private']);
        $this->image($public['id']);
        $uuid = $this->me->uuid;

        // A client sees public + clients-only.
        $this->asClient();
        $client = $this->getJson("/api/mobile/v1/freelance/freelancers/{$uuid}/portfolio")->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['Public', 'Clients'], array_column($client, 'title'));
        $this->assertArrayNotHasKey('visibility', $client[0], 'the owner-only fields stay out');
        $this->assertNull($client[0]['id']);

        // A visitor sees the public ones only.
        $this->asGuest();
        $guest = $this->getJson("/api/freelance/v1/public/freelancers/{$uuid}/portfolio")->assertOk()->json('data');
        $this->assertSame(['Public'], array_column($guest, 'title'));
        $this->assertSame(['Public', 'Clients', 'Private'], array_column($this->asOwnerList(), 'title'));
    }

    private function asOwnerList(): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->me->fresh(), [], 'freelance_api');

        return $this->getJson('/api/freelance/v1/profile/portfolio')->json('data');
    }

    public function test_only_a_verified_freelancers_portfolio_is_shown(): void
    {
        $this->work(['visibility' => 'public', 'rights_confirmed' => true]);
        $this->me->forceFill(['status' => 'profile_ready'])->save();
        $this->asGuest();

        $this->getJson("/api/freelance/v1/public/freelancers/{$this->me->uuid}/portfolio")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/freelance/v1/public/freelancers/'.\Illuminate\Support\Str::uuid().'/portfolio')->assertNotFound();
    }

    public function test_an_image_is_shown_through_a_signed_link_that_expires_and_respects_visibility(): void
    {
        $public = $this->work(['visibility' => 'public', 'rights_confirmed' => true]);
        $private = $this->work(['title' => 'Private']);
        $this->image($public['id']);
        $this->image($private['id']);
        $this->asGuest();

        $url = $this->getJson("/api/freelance/v1/public/freelancers/{$this->me->uuid}/portfolio")->json('data.0.images.0.url');
        $this->assertStringContainsString('signature=', $url);

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        // Unsigned, tampered, or expired: refused.
        $this->get(strtok($url, '?'))->assertForbidden();
        $this->get($url.'x')->assertForbidden();
        $this->travel(2)->hours();
        $this->get($url)->assertForbidden();
        $this->travelBack();

        // A private work's image has no link, and its media id does not open through someone else's link.
        $privateMedia = FreelancerPortfolioItem::query()->find($private['id'])->getFirstMedia('images');
        $forged = \Illuminate\Support\Facades\URL::temporarySignedRoute('api.freelance.portfolio.image', now()->addMinutes(5), ['media' => $privateMedia->id]);
        $this->get($forged)->assertNotFound();
    }

    // -------------------------------------------------------------- the admin

    public function test_the_admin_hides_a_work_and_the_owner_is_told(): void
    {
        $work = $this->work(['visibility' => 'public', 'rights_confirmed' => true]);
        $image = $this->image($work['id'])['images'][0];
        $item = FreelancerPortfolioItem::query()->find($work['id']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);
        Permission::findOrCreate('freelancers.update', 'admin_api');
        $admin->givePermissionTo('freelancers.update');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');

        $this->postJson("/api/admin/v1/freelancers/{$this->me->id}/portfolio/{$item->id}/hide")->assertUnprocessable();
        $this->postJson("/api/admin/v1/freelancers/{$this->me->id}/portfolio/{$item->id}/hide", ['reason' => 'Not your work'])->assertOk()->assertJsonPath('data.hidden', true);
        $this->assertSame('freelance.portfolio.hidden', $this->me->notifications()->first()->data['event']);

        // Gone for everyone else, still there (marked) for its owner.
        $this->asGuest();
        $this->getJson("/api/freelance/v1/public/freelancers/{$this->me->uuid}/portfolio")->assertJsonCount(0, 'data');
        $this->get(\Illuminate\Support\Facades\URL::temporarySignedRoute('api.freelance.portfolio.image', now()->addMinutes(5), ['media' => $image['id']]))->assertNotFound();
        $owner = $this->asOwnerList();
        $this->assertTrue($owner[0]['hidden']);
        $this->assertSame('Not your work', $owner[0]['hidden_reason']);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');
        $this->postJson("/api/admin/v1/freelancers/{$this->me->id}/portfolio/{$item->id}/unhide")->assertOk()->assertJsonPath('data.hidden', false);
        $this->asGuest();
        $this->getJson("/api/freelance/v1/public/freelancers/{$this->me->uuid}/portfolio")->assertJsonCount(1, 'data');

        // The permission is needed.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $nobody = Admin::create(['name' => 'B', 'email' => 'b@example.com', 'password' => 'secret123', 'status' => 'active']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($nobody, [], 'admin_api');
        $this->postJson("/api/admin/v1/freelancers/{$this->me->id}/portfolio/{$item->id}/hide", ['reason' => 'x'])->assertForbidden();
    }

    // -------------------------------------------------------------- the rest

    public function test_the_portfolio_counts_in_the_profile_completeness(): void
    {
        $this->assertContains('portfolio', $this->getJson('/api/freelance/v1/auth/me')->json('data.completeness.missing'));
        $this->work();
        $this->assertNotContains('portfolio', $this->getJson('/api/freelance/v1/auth/me')->json('data.completeness.missing'));
    }

    public function test_deleting_the_account_removes_the_works_and_their_images(): void
    {
        $work = $this->work();
        $this->image($work['id']);
        $this->assertNotSame([], Storage::disk('local')->allFiles());

        $this->deleteJson('/api/freelance/v1/account', ['confirm' => true])->assertOk();

        $this->assertSame(0, FreelancerPortfolioItem::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_routes_need_a_signed_in_freelancer(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/freelance/v1/profile/portfolio')->assertUnauthorized();
        $this->postJson('/api/freelance/v1/profile/portfolio', ['title' => 'x'])->assertUnauthorized();
    }
}
