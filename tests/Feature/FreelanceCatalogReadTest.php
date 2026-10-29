<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\User\Models\User;
use Tests\TestCase;

/** FL-101: the apps read the active specialty tree in their language, behind the `freelance` switch. */
class FreelanceCatalogReadTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private FreelanceSpecialty $design;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id,
        ]);
        FreelanceFeatureRule::query()->create(['feature' => 'freelance', 'country_id' => $this->saudi->id, 'enabled' => true]);
        FreelanceFeatures::forget();

        $this->design = $this->specialty(null, 'Design', 'تصميم', 1);
        $this->specialty($this->design->id, 'Logo', 'شعارات', 1);
        $this->specialty($this->design->id, 'Icons', 'أيقونات', 2, false);
        $writing = $this->specialty(null, 'Writing', 'كتابة', 2);
        $this->specialty($writing->id, 'Articles', 'مقالات', 1);
        $this->specialty(null, 'Hidden', 'مخفي', 3, false);
    }

    private function specialty(?int $parentId, string $en, string $ar, int $order, bool $status = true): FreelanceSpecialty
    {
        $s = FreelanceSpecialty::query()->create(['parent_id' => $parentId, 'status' => $status, 'sort_order' => $order]);
        $s->translations()->createMany([['locale' => 'en', 'name' => $en], ['locale' => 'ar', 'name' => $ar]]);

        return $s;
    }

    private function freelancer(): void
    {
        Sanctum::actingAs(Freelancer::query()->create(['phone' => '+966500000077', 'country_id' => $this->saudi->id, 'phone_verified_at' => now()]), [], 'freelance_api');
    }

    private function client(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]), [], 'user_api');
    }

    public function test_both_apps_get_the_active_tree_in_order_with_inactive_ones_hidden(): void
    {
        foreach (['freelance', 'client'] as $who) {
            $who === 'freelance' ? $this->freelancer() : $this->client();
            $url = $who === 'freelance' ? '/api/freelance/v1/specialties' : '/api/mobile/v1/freelance/specialties';

            $tree = $this->getJson($url)->assertOk()->assertJsonStructure(['pagination' => ['version']])->json('data');

            $this->assertSame(['Design', 'Writing'], array_column($tree, 'name'));
            $this->assertSame(['Logo'], array_column($tree[0]['children'], 'name'), 'the inactive sub-specialty is hidden');
            $this->assertSame(['Articles'], array_column($tree[1]['children'], 'name'));
        }
    }

    public function test_the_names_follow_the_language_of_the_request(): void
    {
        $this->freelancer();

        $tree = $this->getJson('/api/freelance/v1/specialties', ['Accept-Language' => 'ar'])->assertOk()->json('data');

        $this->assertSame(['تصميم', 'كتابة'], array_column($tree, 'name'));
        $this->assertSame('شعارات', $tree[0]['children'][0]['name']);
    }

    public function test_an_inactive_parent_takes_its_children_with_it(): void
    {
        $this->design->update(['status' => false]);
        $this->freelancer();

        $this->assertSame(['Writing'], array_column($this->getJson('/api/freelance/v1/specialties')->json('data'), 'name'));
    }

    public function test_the_version_changes_with_the_catalog(): void
    {
        $this->freelancer();
        $before = $this->getJson('/api/freelance/v1/specialties')->json('pagination.version');

        $this->specialty(null, 'Video', 'فيديو', 4);

        $this->assertNotSame($before, $this->getJson('/api/freelance/v1/specialties')->json('pagination.version'));
    }

    public function test_it_needs_a_signed_in_account_and_the_service_open_in_the_country(): void
    {
        $this->getJson('/api/freelance/v1/specialties')->assertUnauthorized();
        $this->getJson('/api/mobile/v1/freelance/specialties')->assertUnauthorized();

        FreelanceFeatureRule::query()->where('feature', 'freelance')->update(['enabled' => false]);
        FreelanceFeatures::forget();
        $this->freelancer();
        $this->getJson('/api/freelance/v1/specialties')->assertForbidden()->assertJsonPath('error_code', 'freelance_feature_off');
    }
}
