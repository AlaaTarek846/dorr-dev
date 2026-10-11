<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Rating;
use App\Models\ServiceCategory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Ratings: the mobile side (one row per person per thing; later POSTs update it; 1–3 stars stay internal feedback,
 * 4–5 may prompt the store's in-app review) and the dashboard side (list / show / delete behind `ratings.*` permissions).
 */
class RatingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $flag = Flag::create(['code' => 'sa']);
        $currency = Currency::create(['code' => 'SAR', 'symbol' => 'ر.س']);

        Country::create([
            'code' => 'SA', 'code_alpha3' => 'SAU', 'dial_code' => '+966', 'phone_starts_with' => '5',
            'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $currency->id, 'status' => true,
        ]);

        $this->user = $this->makeUser('+966501234567');
    }

    private function makeUser(string $phone): User
    {
        return User::query()->create([
            'phone' => $phone,
            'phone_verified_at' => now(),
            'status' => UserStatus::Active,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function asUser(?User $user = null): array
    {
        // The guard remembers the user of the previous request inside one test; start every request fresh.
        $this->app['auth']->forgetGuards();

        return [
            'Authorization' => 'Bearer '.($user ?? $this->user)->createToken('mobile-app')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    private function adminWith(array $actions): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::create(['name' => 'A', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret123', 'status' => 'active']);

        foreach ($actions as $action) {
            Permission::findOrCreate("ratings.$action", 'admin_api');
        }

        $admin->givePermissionTo(array_map(fn ($a) => "ratings.$a", $actions));
        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    // ------------------------------------------------------------------ mobile

    public function test_four_or_five_stars_are_saved_as_a_review_and_prompt_the_store_review(): void
    {
        $this->postJson('/api/mobile/v1/ratings', ['stars' => 5, 'comment' => '  Love it  '], $this->asUser())
            ->assertCreated()
            ->assertJsonPath('data.stars', 5)
            ->assertJsonPath('data.type', 'review')
            ->assertJsonPath('data.comment', 'Love it')
            ->assertJsonPath('data.prompt_store_review', true)
            ->assertJsonPath('data.rateable_type', null);

        $this->assertDatabaseHas('ratings', [
            'author_type' => $this->user->getMorphClass(),
            'author_id' => $this->user->id,
            'rateable_type' => null,
            'stars' => 5,
            'type' => 'review',
        ]);
    }

    public function test_quarter_and_half_stars_are_saved_and_the_threshold_uses_the_decimal(): void
    {
        $this->postJson('/api/mobile/v1/ratings', ['stars' => 3.75], $this->asUser())
            ->assertCreated()
            ->assertJsonPath('data.stars', 3.75)
            ->assertJsonPath('data.type', 'feedback')
            ->assertJsonPath('data.prompt_store_review', false);

        $user = $this->makeUser('+966509990001');
        $this->postJson('/api/mobile/v1/ratings', ['stars' => 4.25], $this->asUser($user))
            ->assertCreated()
            ->assertJsonPath('data.stars', 4.25)
            ->assertJsonPath('data.type', 'review')
            ->assertJsonPath('data.prompt_store_review', true);

        $this->assertDatabaseHas('ratings', ['stars' => 3.75, 'type' => 'feedback']);
    }

    public function test_one_to_three_stars_stay_internal_feedback_and_never_prompt_the_store(): void
    {
        foreach ([1, 2, 3] as $stars) {
            $user = $this->makeUser('+96650123456'.$stars);

            $this->postJson('/api/mobile/v1/ratings', ['stars' => $stars, 'comment' => 'Needs work'], $this->asUser($user))
                ->assertCreated()
                ->assertJsonPath('data.type', 'feedback')
                ->assertJsonPath('data.prompt_store_review', false);
        }

        $this->assertSame(3, Rating::query()->where('type', Rating::TYPE_FEEDBACK)->count());
    }

    public function test_a_second_rating_for_the_same_thing_updates_it(): void
    {
        $this->postJson('/api/mobile/v1/ratings', ['stars' => 4, 'comment' => 'Great'], $this->asUser())->assertCreated();

        $this->postJson('/api/mobile/v1/ratings', ['stars' => 2.5, 'comment' => 'Changed my mind'], $this->asUser())
            ->assertOk()
            ->assertJsonPath('data.stars', 2.5)
            ->assertJsonPath('data.type', 'feedback')
            ->assertJsonPath('data.comment', 'Changed my mind')
            ->assertJsonPath('data.prompt_store_review', false);

        $this->assertSame(1, Rating::query()->count());
        $this->assertEquals(2.5, (float) Rating::query()->first()->stars);
        $this->assertSame('Changed my mind', Rating::query()->first()->comment);

        $this->postJson('/api/mobile/v1/ratings', ['stars' => 3, 'comment' => ''], $this->asUser())
            ->assertOk()
            ->assertJsonPath('data.comment', null);

        $this->assertNull(Rating::query()->first()->comment);
    }

    public function test_the_same_person_can_rate_different_things_once_each(): void
    {
        $service = ServiceCategory::create(['module_name' => 'ride', 'status' => true, 'sort_order' => 0]);

        $this->postJson('/api/mobile/v1/ratings', ['stars' => 5], $this->asUser())->assertCreated();

        $this->postJson('/api/mobile/v1/ratings', ['stars' => 3, 'rateable_type' => 'service', 'rateable_id' => $service->id], $this->asUser())
            ->assertCreated()
            ->assertJsonPath('data.rateable_type', 'service')
            ->assertJsonPath('data.rateable_id', $service->id);

        $this->postJson('/api/mobile/v1/ratings', ['stars' => 5, 'rateable_type' => 'service', 'rateable_id' => $service->id], $this->asUser())
            ->assertOk()
            ->assertJsonPath('data.stars', 5);

        $this->assertDatabaseHas('ratings', ['rateable_type' => ServiceCategory::class, 'rateable_id' => $service->id, 'stars' => 5]);
        $this->assertSame(2, Rating::query()->count());
    }

    public function test_two_people_can_both_rate_the_app(): void
    {
        $other = $this->makeUser('+966509876543');

        $this->postJson('/api/mobile/v1/ratings', ['stars' => 5], $this->asUser())->assertCreated();
        $this->postJson('/api/mobile/v1/ratings', ['stars' => 2], $this->asUser($other))->assertCreated();

        $this->assertSame(2, Rating::query()->count());
    }

    public function test_mine_returns_null_before_and_the_rating_after(): void
    {
        $this->getJson('/api/mobile/v1/ratings/mine', $this->asUser())
            ->assertOk()
            ->assertJsonPath('data.rated', false)
            ->assertJsonPath('data.rating', null);

        $this->postJson('/api/mobile/v1/ratings', ['stars' => 4, 'comment' => 'Great'], $this->asUser())->assertCreated();

        $this->getJson('/api/mobile/v1/ratings/mine', $this->asUser())
            ->assertOk()
            ->assertJsonPath('data.rated', true)
            ->assertJsonPath('data.rating.stars', 4)
            ->assertJsonPath('data.rating.comment', 'Great');

        // Another person has not rated yet.
        $this->getJson('/api/mobile/v1/ratings/mine', $this->asUser($this->makeUser('+966509998877')))
            ->assertJsonPath('data.rated', false);
    }

    public function test_rating_validation(): void
    {
        // Twelve bad requests in a row would trip the 10-per-minute limit that protects the real endpoint.
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->postJson('/api/mobile/v1/ratings', [], $this->asUser())->assertUnprocessable()->assertJsonValidationErrors('stars');
        $this->postJson('/api/mobile/v1/ratings', ['stars' => 0], $this->asUser())->assertUnprocessable()->assertJsonValidationErrors('stars');
        $this->postJson('/api/mobile/v1/ratings', ['stars' => 6], $this->asUser())->assertUnprocessable()->assertJsonValidationErrors('stars');
        $this->postJson('/api/mobile/v1/ratings', ['stars' => 4.1], $this->asUser())->assertUnprocessable()->assertJsonValidationErrors('stars');
        $this->postJson('/api/mobile/v1/ratings', ['stars' => 0.75], $this->asUser())->assertUnprocessable()->assertJsonValidationErrors('stars');
        $this->postJson('/api/mobile/v1/ratings', ['stars' => 'five'], $this->asUser())->assertUnprocessable()->assertJsonValidationErrors('stars');
        $this->postJson('/api/mobile/v1/ratings', ['stars' => 4, 'comment' => 'abcd'], $this->asUser())->assertUnprocessable()->assertJsonValidationErrors('comment');
        $this->postJson('/api/mobile/v1/ratings', ['stars' => 4, 'comment' => str_repeat('x', 301)], $this->asUser())->assertUnprocessable()->assertJsonValidationErrors('comment');
        $this->postJson('/api/mobile/v1/ratings', ['stars' => 4, 'rateable_type' => 'galaxy', 'rateable_id' => 1], $this->asUser())->assertUnprocessable()->assertJsonValidationErrors('rateable_type');
        $this->postJson('/api/mobile/v1/ratings', ['stars' => 4, 'rateable_type' => 'service'], $this->asUser())->assertUnprocessable()->assertJsonValidationErrors('rateable_id');
        $this->postJson('/api/mobile/v1/ratings', ['stars' => 4, 'rateable_type' => 'service', 'rateable_id' => 9999], $this->asUser())->assertUnprocessable()->assertJsonValidationErrors('rateable_id');
        $this->postJson('/api/mobile/v1/ratings', ['stars' => 4, 'rateable_id' => 1], $this->asUser())->assertUnprocessable()->assertJsonValidationErrors('rateable_id');

        $this->assertSame(0, Rating::query()->count());
    }

    public function test_guests_cannot_rate(): void
    {
        $this->postJson('/api/mobile/v1/ratings', ['stars' => 5])->assertUnauthorized();
        $this->getJson('/api/mobile/v1/ratings/mine')->assertUnauthorized();
    }

    public function test_the_database_key_stops_a_duplicate_even_if_the_check_is_bypassed(): void
    {
        $key = Rating::uniqueKeyFor($this->user, null);
        $row = [
            'author_type' => $this->user->getMorphClass(), 'author_id' => $this->user->id,
            'unique_key' => $key, 'stars' => 5, 'type' => 'review',
        ];

        Rating::query()->create($row);

        $this->expectException(UniqueConstraintViolationException::class);
        Rating::query()->create($row);
    }

    // ------------------------------------------------------------------ dashboard

    private function seedRatings(): void
    {
        $service = ServiceCategory::create(['module_name' => 'ride', 'status' => true, 'sort_order' => 0]);

        $this->postJson('/api/mobile/v1/ratings', ['stars' => 5, 'comment' => 'Great'], $this->asUser())->assertCreated();
        $this->postJson('/api/mobile/v1/ratings', ['stars' => 2, 'comment' => 'Too slow', 'rateable_type' => 'service', 'rateable_id' => $service->id], $this->asUser())->assertCreated();
    }

    public function test_admin_lists_and_shows_ratings_with_their_author_and_target(): void
    {
        $this->seedRatings();
        $this->adminWith(['view']);

        $response = $this->getJson('/api/admin/v1/ratings')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $rows = collect($response->json('data'))->keyBy('stars');

        $this->assertSame('Great', $rows[5]['comment']);
        $this->assertNull($rows[5]['rateable'], 'the app itself has no target');
        $this->assertSame('+966501234567', $rows[5]['author']['phone']);
        $this->assertSame('user', $rows[5]['author']['type']);
        $this->assertSame('service', $rows[2]['rateable']['type']);
        $this->assertSame('feedback', $rows[2]['type']);

        $this->getJson('/api/admin/v1/ratings/'.$rows[2]['id'])
            ->assertOk()
            ->assertJsonPath('data.comment', 'Too slow');
    }

    public function test_admin_ratings_need_the_view_permission(): void
    {
        $this->seedRatings();
        $this->adminWith(['delete']);

        $this->getJson('/api/admin/v1/ratings')->assertForbidden();
    }

    public function test_admin_can_delete_one_and_many_with_the_right_permissions(): void
    {
        $this->seedRatings();
        $this->adminWith(['view', 'delete', 'multiple-delete']);

        $ids = Rating::query()->pluck('id');

        $this->deleteJson('/api/admin/v1/ratings/'.$ids[0])->assertSuccessful();
        $this->assertSame(1, Rating::query()->count());

        $this->postJson('/api/admin/v1/ratings/delete-multiple', ['ids' => [$ids[1]]])->assertSuccessful();
        $this->assertSame(0, Rating::query()->count());

        // The person can rate again once their rating was removed.
        $this->postJson('/api/mobile/v1/ratings', ['stars' => 4], $this->asUser())->assertCreated();
    }

    public function test_admin_cannot_delete_without_the_permission(): void
    {
        $this->seedRatings();
        $this->adminWith(['view']);

        $this->deleteJson('/api/admin/v1/ratings/'.Rating::query()->value('id'))->assertForbidden();
        $this->assertSame(2, Rating::query()->count());
    }

    public function test_bulk_delete_validates_the_ids(): void
    {
        $this->adminWith(['multiple-delete']);

        $this->postJson('/api/admin/v1/ratings/delete-multiple', ['ids' => [99999]])->assertUnprocessable()->assertJsonValidationErrors('ids.0');
        $this->postJson('/api/admin/v1/ratings/delete-multiple', [])->assertUnprocessable()->assertJsonValidationErrors('ids');
    }
}
