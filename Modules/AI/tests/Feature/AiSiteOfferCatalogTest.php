<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\AI\Models\AiSiteOffer;
use Modules\AI\Models\AiSitePurchase;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Website-builder offers and edit packs as a catalog like countries: translated text per stored language, status
 * toggle, trash / restore, force delete blocked once sold, ai-site-offers.* permissions, the source-download row kept
 * out of this screen - and the apps' offers API in the caller's language with the same keys.
 */
class AiSiteOfferCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIONS = ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'];

    private Country $country;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $flag = Flag::create(['code' => 'sa']);

        foreach ([['ar', 'rtl', true], ['en', 'ltr', false]] as [$code, $direction, $default]) {
            Language::create([
                'code' => $code, 'direction' => $direction, 'is_default_website' => $default, 'is_default_dashboard' => $default,
                'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id,
            ]);
        }

        $this->currency = Currency::create(['code' => 'SAR', 'symbol' => 'ر.س', 'decimal_places' => 2]);
        $this->country = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9,
            'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $this->currency->id, 'status' => true,
        ]);
    }

    /**
     * @param  list<string>  $actions
     */
    private function actingAsAdmin(array $actions = self::ACTIONS): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ACTIONS as $action) {
            Permission::findOrCreate("ai-site-offers.{$action}", 'admin_api');
        }

        $admin = Admin::query()->create([
            'name' => 'Offers Admin', 'email' => 'offers-'.uniqid().'@example.test', 'password' => 'password', 'status' => true,
        ]);
        $admin->givePermissionTo(array_map(fn ($action) => "ai-site-offers.{$action}", $actions));

        Sanctum::actingAs($admin, ['*'], 'admin_api');

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return $extra + [
            'code' => 'portfolio-'.uniqid(),
            'kind' => 'site',
            'generations_included' => 20,
            'status' => true,
            'translations' => [
                ['locale' => 'ar', 'name' => 'موقع أعمال', 'description' => 'موقع لعرض أعمالك'],
                ['locale' => 'en', 'name' => 'Portfolio site', 'description' => 'A site to show your work'],
            ],
        ];
    }

    private function user(): User
    {
        return User::query()->create([
            'name' => 'App', 'email' => 'app-'.uniqid().'@example.test', 'password' => bcrypt('x-not-real'), 'status' => UserStatus::Active,
        ]);
    }

    public function test_admin_creates_an_offer_with_a_translation_per_language(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/v1/ai-site-offers', $this->payload(), ['X-Locale' => 'en'])->assertCreated();

        $offer = AiSiteOffer::query()->findOrFail($response->json('data.id'));
        $this->assertCount(2, $offer->translations);
        $this->assertSame('موقع أعمال', $offer->translations->firstWhere('locale', 'ar')->name);
        $this->assertSame('Portfolio site', $response->json('data.name'));
        $this->assertCount(2, $response->json('data.translations'));
        $this->assertTrue($response->json('data.status'));
        $this->assertTrue($response->json('data.is_active'));
    }

    public function test_every_stored_language_needs_a_name(): void
    {
        $this->actingAsAdmin();

        $payload = $this->payload();
        $payload['translations'] = [['locale' => 'ar', 'name' => 'موقع أعمال']];

        $this->postJson('/api/admin/v1/ai-site-offers', $payload)->assertUnprocessable()->assertJsonValidationErrors('translations');
    }

    public function test_the_kind_picks_the_screen_and_the_source_download_row_stays_out(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/admin/v1/ai-site-offers', $this->payload())->assertCreated();
        $this->postJson('/api/admin/v1/ai-site-offers', $this->payload(['kind' => 'edit_pack', 'generations_included' => 5]))->assertCreated();
        $download = AiSiteOffer::query()->create([
            'name' => 'Download', 'code' => 'source_download', 'kind' => 'source_download', 'generations_included' => 0, 'is_active' => true, 'is_free' => true,
        ]);

        $this->postJson('/api/admin/v1/ai-site-offers', $this->payload(['kind' => 'source_download']))->assertUnprocessable()->assertJsonValidationErrors('kind');

        $packs = $this->getJson('/api/admin/v1/ai-site-offers?kind=edit_pack&paginate=1&status_counts=1')->assertOk();
        $this->assertSame(['edit_pack'], collect($packs->json('data'))->pluck('kind')->unique()->values()->all());
        $this->assertSame(1, $packs->json('status_counts.total'));

        $all = collect($this->getJson('/api/admin/v1/ai-site-offers')->assertOk()->json('data'));
        $this->assertCount(2, $all);
        $this->assertNull($all->firstWhere('kind', 'source_download'));

        // Not this screen's to edit or delete.
        $this->deleteJson("/api/admin/v1/ai-site-offers/{$download->id}")->assertNotFound();
        $this->patchJson("/api/admin/v1/ai-site-offers/{$download->id}/status", ['status' => false])->assertNotFound();
        $this->assertTrue($download->fresh()->is_active);
    }

    public function test_status_toggle_and_counts(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/admin/v1/ai-site-offers', $this->payload())->json('data.id');

        $this->patchJson("/api/admin/v1/ai-site-offers/{$id}/status", ['status' => false])->assertOk();

        $this->assertFalse(AiSiteOffer::query()->findOrFail($id)->status);
        $this->assertFalse(AiSiteOffer::query()->findOrFail($id)->isAvailable());
        $this->getJson('/api/admin/v1/ai-site-offers?kind=site&status_counts=1')->assertOk()
            ->assertJsonPath('status_counts.inactive', 1);
    }

    public function test_trash_keeps_what_was_bought_stops_selling_and_force_delete_is_blocked_once_sold(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/admin/v1/ai-site-offers', $this->payload())->json('data.id');
        AiSiteOffer::query()->findOrFail($id)->prices()->create(['country_id' => $this->country->id, 'currency_id' => $this->currency->id, 'price' => 199]);

        $user = $this->user();
        $purchase = AiSitePurchase::query()->create([
            'owner_type' => $user->getMorphClass(), 'owner_id' => $user->id, 'offer_id' => $id,
            'amount' => 199, 'currency' => 'SAR', 'generations_included' => 20, 'generations_used' => 0, 'status' => 'active',
        ]);

        $this->deleteJson("/api/admin/v1/ai-site-offers/{$id}")->assertSuccessful();

        $this->assertSoftDeleted('ai_site_offers', ['id' => $id]);
        $this->assertNotNull($purchase->fresh()->offer, 'a trashed offer still names what was bought');
        $this->assertFalse($purchase->fresh()->offer->isAvailable(), 'no new sales');

        Sanctum::actingAs($user, ['*'], 'user_api');
        $offers = $this->getJson('/api/user/v1/ai-sites/offers', ['X-Country' => 'SA'])->assertOk()->json('data.offers');
        $this->assertSame([], $offers, 'the apps no longer list it');

        $this->actingAsAdmin();
        $this->deleteJson("/api/admin/v1/ai-site-offers/{$id}/force")->assertStatus(409);

        $this->postJson("/api/admin/v1/ai-site-offers/{$id}/restore")->assertSuccessful();
        $this->assertNotSoftDeleted('ai_site_offers', ['id' => $id]);
    }

    public function test_an_offer_never_sold_can_be_deleted_for_good(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/admin/v1/ai-site-offers', $this->payload())->json('data.id');

        $this->deleteJson("/api/admin/v1/ai-site-offers/{$id}")->assertSuccessful();
        $this->deleteJson("/api/admin/v1/ai-site-offers/{$id}/force")->assertSuccessful();

        $this->assertDatabaseMissing('ai_site_offers', ['id' => $id]);
        $this->assertDatabaseMissing('ai_site_offer_translations', ['ai_site_offer_id' => $id]);
    }

    public function test_each_action_needs_its_permission(): void
    {
        $this->actingAsAdmin(['view']);

        $this->getJson('/api/admin/v1/ai-site-offers')->assertOk();
        $this->postJson('/api/admin/v1/ai-site-offers', $this->payload())->assertForbidden();

        $id = AiSiteOffer::query()->create(['name' => 'X', 'code' => 'x-'.uniqid(), 'generations_included' => 1, 'is_active' => true])->id;
        $this->getJson("/api/admin/v1/ai-site-offers/{$id}/prices")->assertOk();
        $this->putJson("/api/admin/v1/ai-site-offers/{$id}/prices", ['prices' => []])->assertForbidden();

        $this->actingAsAdmin([]);
        $this->getJson('/api/admin/v1/ai-site-offers')->assertForbidden();
    }

    public function test_the_site_download_screen_can_read_but_not_change_the_download_offer_prices(): void
    {
        $download = AiSiteOffer::query()->create([
            'name' => 'Download', 'code' => 'source_download', 'kind' => 'source_download', 'generations_included' => 0, 'is_active' => true, 'is_free' => false,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('ai-site-download.view', 'admin_api');
        foreach (self::ACTIONS as $action) {
            Permission::findOrCreate("ai-site-offers.{$action}", 'admin_api');
        }
        $admin = Admin::query()->create(['name' => 'D', 'email' => 'd-'.uniqid().'@example.test', 'password' => 'password', 'status' => true]);
        $admin->givePermissionTo('ai-site-download.view');
        Sanctum::actingAs($admin, ['*'], 'admin_api');

        $this->getJson("/api/admin/v1/ai-site-offers/{$download->id}/prices")->assertOk();
        $this->putJson("/api/admin/v1/ai-site-offers/{$download->id}/prices", ['prices' => []])->assertForbidden();
        $this->getJson('/api/admin/v1/ai-site-offers')->assertForbidden();
    }

    public function test_the_apps_get_offer_text_in_their_own_language_with_the_same_keys(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/admin/v1/ai-site-offers', $this->payload())->json('data.id');
        $this->putJson("/api/admin/v1/ai-site-offers/{$id}/prices", ['prices' => [['country_id' => $this->country->id, 'price' => 199]]])->assertSuccessful();

        Sanctum::actingAs($this->user(), ['*'], 'user_api');

        foreach (['en' => ['Portfolio site', 'A site to show your work'], 'ar' => ['موقع أعمال', 'موقع لعرض أعمالك']] as $locale => [$name, $description]) {
            $row = collect($this->getJson('/api/user/v1/ai-sites/offers', ['X-Locale' => $locale, 'X-Country' => 'SA'])
                ->assertOk()->json('data.offers'))->firstWhere('id', $id);

            $this->assertSame($name, $row['name']);
            $this->assertSame($description, $row['description']);
            $this->assertEquals(199, $row['price']);
            $this->assertSame('SAR', $row['currency']);
            $this->assertTrue($row['available']);
            $this->assertTrue($row['is_active'], 'the apps still read is_active');
            $this->assertSame(20, $row['generations_included']);
            $this->assertArrayNotHasKey('translations', $row, 'only the admin panel gets every language');
        }
    }

    public function test_flat_text_still_works_for_code_that_creates_offers_directly(): void
    {
        $offer = AiSiteOffer::query()->create(['code' => 'flat', 'name' => 'Flat', 'description' => 'D', 'generations_included' => 1, 'is_active' => true]);

        $this->assertSame('Flat', $offer->fresh()->name);
        $this->assertSame('D', $offer->fresh()->description);
        $this->assertTrue($offer->fresh()->is_active);
        $this->assertCount(2, $offer->fresh()->translations, 'written into every stored language');
    }
}
