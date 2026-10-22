<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** FL-002: the freelance switches are per country, off unless a rule says otherwise. */
class FreelanceFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function country(string $code): Country
    {
        return Country::create([
            'code' => $code, 'dial_code' => '+9'.random_int(10, 99), 'phone_length' => 9, 'status' => true,
            'flag_id' => Flag::create(['code' => strtolower($code)])->id,
            'currency_id' => Currency::create(['code' => $code.'X', 'symbol' => $code, 'decimal_places' => 2])->id,
        ]);
    }

    private function rule(Country $country, string $feature, bool $enabled): void
    {
        FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $country->id, 'enabled' => $enabled]);
    }

    public function test_no_rule_means_off(): void
    {
        $sa = $this->country('SA');

        $this->assertFalse(app(FreelanceFeatures::class)->allows($sa->id, 'projects'));
        $this->assertFalse(app(FreelanceFeatures::class)->snapshot($sa->id)['service']);
    }

    public function test_a_feature_needs_the_service_to_be_on(): void
    {
        $sa = $this->country('SA');
        $this->rule($sa, 'projects', true);
        $features = app(FreelanceFeatures::class);

        $this->assertFalse($features->allows($sa->id, 'projects'));

        $this->rule($sa, 'freelance', true);
        FreelanceFeatures::forget();

        $this->assertTrue($features->allows($sa->id, 'projects'));
        $this->assertFalse($features->allows($sa->id, 'bidding'));
        $snapshot = $features->snapshot($sa->id);
        $this->assertTrue($snapshot['service']);
        $this->assertTrue($snapshot['features']['projects']);
        $this->assertFalse($snapshot['features']['bidding']);
    }

    public function test_rules_do_not_leak_between_countries(): void
    {
        $sa = $this->country('SA');
        $eg = $this->country('EG');
        $this->rule($sa, 'freelance', true);

        $this->assertTrue(app(FreelanceFeatures::class)->allows($sa->id, 'freelance'));
        $this->assertFalse(app(FreelanceFeatures::class)->allows($eg->id, 'freelance'));
    }

    public function test_saving_a_rule_clears_the_cache(): void
    {
        $sa = $this->country('SA');
        $features = app(FreelanceFeatures::class);
        $this->assertFalse($features->allows($sa->id, 'freelance'));

        $this->rule($sa, 'freelance', true);

        $this->assertTrue($features->allows($sa->id, 'freelance'));
    }

    public function test_admin_switches_a_feature_and_copies_a_country(): void
    {
        $this->asAdmin(['freelance-feature-rules.view', 'freelance-feature-rules.update']);
        $sa = $this->country('SA');
        $eg = $this->country('EG');

        $this->getJson('/api/admin/v1/freelance-feature-rules/options')->assertOk()->assertJsonFragment(['bidding']);
        $this->postJson('/api/admin/v1/freelance-feature-rules', ['feature' => 'freelance', 'country_id' => $sa->id, 'enabled' => true])->assertOk();
        $this->postJson('/api/admin/v1/freelance-feature-rules', ['feature' => 'bidding', 'country_id' => $sa->id, 'enabled' => true])->assertOk();
        $this->postJson('/api/admin/v1/freelance-feature-rules', ['feature' => 'flying', 'country_id' => $sa->id, 'enabled' => true])->assertUnprocessable();
        $this->assertTrue(app(FreelanceFeatures::class)->allows($sa->id, 'bidding'));

        $this->postJson('/api/admin/v1/freelance-feature-rules/copy', ['from_country_id' => $sa->id, 'to_country_id' => $eg->id])->assertOk()->assertJsonPath('data.copied', 2);
        $this->assertTrue(app(FreelanceFeatures::class)->allows($eg->id, 'bidding'));
        $this->getJson('/api/admin/v1/freelance-feature-rules?country_id='.$eg->id)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_guide_is_a_word_file_that_lists_every_switch(): void
    {
        $this->asAdmin(['freelance-feature-rules.view']);
        $sa = $this->country('SA');
        $this->rule($sa, 'freelance', true);

        $response = $this->get('/api/admin/v1/freelance-feature-rules/guide?country_id='.$sa->id)->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file) === true);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        foreach (FreelanceFeatures::all() as $feature) {
            $this->assertStringContainsString($feature, $xml);
        }
        $this->assertStringContainsString('مفاتيح الخصائص', $xml);
        $this->assertStringContainsString('نشط', $xml);
    }

    public function test_the_screen_needs_its_permission(): void
    {
        $this->asAdmin([]);
        $this->getJson('/api/admin/v1/freelance-feature-rules/guide')->assertForbidden();

        $this->getJson('/api/admin/v1/freelance-feature-rules')->assertForbidden();
        $this->postJson('/api/admin/v1/freelance-feature-rules', [])->assertForbidden();
    }

    /** @param list<string> $permissions */
    private function asAdmin(array $permissions): void
    {
        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');
    }
}
