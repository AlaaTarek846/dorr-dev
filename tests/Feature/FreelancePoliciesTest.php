<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelancePolicy;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelanceFees;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Policy\FreelancePolicyData;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FL-008: the freelance numbers — defaults, a country's own, a country + specialty's own, the most
 * specific winning; strict checks; the money of an amount; the snapshot; the admin screen's API.
 */
class FreelancePoliciesTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private Country $egypt;

    private Country $kuwait;

    protected function setUp(): void
    {
        parent::setUp();

        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'ر.س', 'decimal_places' => 2]);
        $this->saudi = $this->country('SA', '+966', $sar);
        $this->egypt = $this->country('EG', '+20', Currency::create(['code' => 'EGP', 'symbol' => 'ج.م', 'decimal_places' => 2]));
        $this->kuwait = $this->country('KW', '+965', $sar); // same currency as Saudi Arabia, for the copy test
        FreelancePolicies::forget();
    }

    private function country(string $code, string $dial, Currency $currency): Country
    {
        return Country::create([
            'code' => $code, 'dial_code' => $dial, 'phone_length' => 9, 'status' => true,
            'flag_id' => Flag::create(['code' => strtolower($code)])->id, 'currency_id' => $currency->id,
        ]);
    }

    private function policies(): FreelancePolicies
    {
        return app(FreelancePolicies::class);
    }

    private function asAdmin(array $permissions): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'A', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    // -------------------------------------------------------------- merging

    public function test_without_a_policy_the_defaults_apply(): void
    {
        $data = $this->policies()->for($this->saudi->id);

        $this->assertSame('percent', $data->get('commission.type'));
        $this->assertSame(1000, $data->get('commission.value'));
        $this->assertSame(200, $data->get('client_fee.value'));
        $this->assertNull($data->get('commission.min_minor'));
        $this->assertSame(10, $data->get('offers.free_monthly_quota'));
        $this->assertSame([], $data->policyIds);
        $this->assertSame(array_keys(FreelancePolicyData::KEYS), array_keys($data->all()));
    }

    public function test_the_most_specific_scope_wins_key_by_key(): void
    {
        $this->policies()->save($this->saudi->id, null, ['commission.value' => 1500, 'review.days' => 10]);
        $this->policies()->save($this->saudi->id, 7, ['commission.value' => 800]);

        $country = $this->policies()->for($this->saudi->id);
        $specialty = $this->policies()->for($this->saudi->id, 7);
        $otherSpecialty = $this->policies()->for($this->saudi->id, 8);

        $this->assertSame(1500, $country->get('commission.value'));
        $this->assertSame(800, $specialty->get('commission.value'));
        $this->assertSame(10, $specialty->get('review.days'), 'the specialty inherits what it does not set');
        $this->assertSame(1500, $otherSpecialty->get('commission.value'));
        $this->assertCount(2, $specialty->policyIds);
        $this->assertSame(1000, $this->policies()->for($this->egypt->id)->get('commission.value'), 'another country is untouched');
    }

    public function test_saving_empty_data_brings_the_scope_back_to_inheriting(): void
    {
        $this->policies()->save($this->saudi->id, null, ['review.days' => 3]);
        $this->assertSame(3, $this->policies()->for($this->saudi->id)->get('review.days'));

        $this->assertNull($this->policies()->save($this->saudi->id, null, []));
        $this->assertSame(0, FreelancePolicy::query()->count());
        $this->assertSame(7, $this->policies()->for($this->saudi->id)->get('review.days'));
    }

    // -------------------------------------------------------------- checks

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badData(): array
    {
        return [
            'unknown key' => [['commission.rate' => 10]],
            'negative money' => [['commission.min_minor' => -1]],
            'negative amount' => [['client_fee.value' => -5]],
            'unknown type' => [['commission.type' => 'tiered']],
            'string number' => [['offers.window_days' => '14']],
            'zero window' => [['offers.window_days' => 0]],
            'review too long' => [['review.days' => 365]],
            'not a bool' => [['review.auto_approve' => 1]],
            'percent above 100%' => [['commission.value' => 10001]],
            'min above max' => [['commission.min_minor' => 5000, 'commission.max_minor' => 100]],
            'budget range crossed' => [['project.min_budget_minor' => 900, 'project.max_budget_minor' => 100]],
        ];
    }

    /** @dataProvider badData */
    public function test_bad_values_are_refused(array $data): void
    {
        try {
            $this->policies()->save($this->saudi->id, null, $data);
            $this->fail('saved: '.json_encode($data));
        } catch (FreelanceException $e) {
            $this->assertSame('freelance_policy_invalid', $e->apiErrorCode());
            $this->assertNotEmpty($e->apiData()['keys']);
        }
        $this->assertSame(0, FreelancePolicy::query()->count());
    }

    public function test_a_fixed_fee_may_pass_ten_thousand_minor_units(): void
    {
        $this->policies()->save($this->saudi->id, null, ['commission.type' => 'fixed', 'commission.value' => 50000]);
        $this->assertSame(50000, $this->policies()->for($this->saudi->id)->get('commission.value'));
    }

    public function test_a_country_change_that_breaks_one_of_its_specialties_is_refused(): void
    {
        $this->policies()->save($this->saudi->id, 7, ['commission.max_minor' => 1000]);

        $this->expectException(FreelanceException::class);
        $this->policies()->save($this->saudi->id, null, ['commission.min_minor' => 5000]);
    }

    // -------------------------------------------------------------- money

    public function test_a_percent_fee_rounds_half_up_to_the_minor_unit(): void
    {
        $values = FreelancePolicyData::merge([['commission.value' => 1000, 'client_fee.value' => 250]])->all();

        $this->assertSame([
            'amount_minor' => 12345,
            'client_fee_minor' => 309, // 2.5% of 123.45 = 3.08625 → 3.09
            'client_total_minor' => 12654,
            'commission_minor' => 1235, // 10% of 123.45 = 12.345 → 12.35
            'freelancer_net_minor' => 11110,
        ], FreelanceFees::breakdown(12345, $values));
    }

    public function test_limits_hold_a_percent_fee_and_a_fixed_fee_stays_fixed(): void
    {
        $values = FreelancePolicyData::merge([[
            'commission.value' => 1000, 'commission.min_minor' => 500, 'commission.max_minor' => 20000,
            'client_fee.type' => 'fixed', 'client_fee.value' => 300,
        ]])->all();

        $small = FreelanceFees::breakdown(1000, $values);   // 10% = 1.00 → min 5.00
        $large = FreelanceFees::breakdown(1000000, $values); // 10% = 1,000 → max 200.00

        $this->assertSame(500, $small['commission_minor']);
        $this->assertSame(500, $small['freelancer_net_minor']);
        $this->assertSame(20000, $large['commission_minor']);
        $this->assertSame(300, $small['client_fee_minor']);
        $this->assertSame(300, $large['client_fee_minor']);
    }

    public function test_the_commission_never_passes_the_amount_and_zero_costs_nothing(): void
    {
        $values = FreelancePolicyData::merge([['commission.type' => 'fixed', 'commission.value' => 5000]])->all();

        $this->assertSame(0, FreelanceFees::breakdown(3000, $values)['freelancer_net_minor']);
        $this->assertSame(3000, FreelanceFees::breakdown(3000, $values)['commission_minor']);
        $this->assertSame(
            ['amount_minor' => 0, 'client_fee_minor' => 0, 'client_total_minor' => 0, 'commission_minor' => 0, 'freelancer_net_minor' => 0],
            FreelanceFees::breakdown(0, $values),
        );
    }

    public function test_a_snapshot_keeps_its_numbers_and_currency_when_the_policy_changes(): void
    {
        $this->policies()->save($this->saudi->id, null, ['commission.value' => 1500]);
        $snapshot = $this->policies()->for($this->saudi->id)->snapshot($this->saudi->currency);

        $this->policies()->save($this->saudi->id, null, ['commission.value' => 500]);

        $this->assertSame('SAR', $snapshot['currency']);
        $this->assertSame(2, $snapshot['decimal_places']);
        $this->assertSame(1500, $snapshot['values']['commission.value']);
        $this->assertSame(1500, FreelanceFees::breakdown(10000, $snapshot['values'])['commission_minor']);
        $this->assertSame(500, FreelanceFees::breakdown(10000, $this->policies()->for($this->saudi->id))['commission_minor']);
    }

    // -------------------------------------------------------------- copying between countries

    public function test_copying_to_another_currency_leaves_the_amounts_behind(): void
    {
        $this->policies()->save($this->saudi->id, null, [
            'commission.value' => 1200, 'commission.min_minor' => 500,
            'client_fee.type' => 'fixed', 'client_fee.value' => 300,
            'review.days' => 4,
        ]);

        $result = $this->policies()->copy($this->saudi->id, $this->egypt->id);

        $this->assertSame(1, $result['copied']);
        $this->assertEqualsCanonicalizing(['commission.min_minor', 'client_fee.type', 'client_fee.value'], $result['skipped']);
        $egypt = $this->policies()->for($this->egypt->id);
        $this->assertSame(1200, $egypt->get('commission.value'), 'a percent carries over');
        $this->assertSame(4, $egypt->get('review.days'));
        $this->assertNull($egypt->get('commission.min_minor'));
        $this->assertSame('percent', $egypt->get('client_fee.type'), 'the fixed fee stays out, its type with it');
    }

    public function test_copying_to_the_same_currency_takes_everything(): void
    {
        $this->policies()->save($this->saudi->id, null, ['commission.min_minor' => 500, 'client_fee.type' => 'fixed', 'client_fee.value' => 300]);
        $this->policies()->save($this->saudi->id, 7, ['review.days' => 3]);

        $result = $this->policies()->copy($this->saudi->id, $this->kuwait->id);

        $this->assertSame(['copied' => 2, 'skipped' => []], $result);
        $this->assertSame(300, $this->policies()->for($this->kuwait->id)->get('client_fee.value'));
        $this->assertSame(3, $this->policies()->for($this->kuwait->id, 7)->get('review.days'));
    }

    // -------------------------------------------------------------- admin

    public function test_the_admin_reads_saves_previews_and_copies(): void
    {
        $this->asAdmin(['freelance-policies.view', 'freelance-policies.update']);

        $this->getJson('/api/admin/v1/freelance-policies/options')->assertOk()
            ->assertJsonStructure(['data' => ['countries' => [['id', 'currency' => ['code', 'symbol', 'decimal_places']]], 'keys', 'defaults', 'specialties']]);

        $this->getJson('/api/admin/v1/freelance-policies?country_id='.$this->saudi->id)->assertOk()
            ->assertJsonPath('data.currency.code', 'SAR')
            ->assertJsonPath('data.own', []);

        $this->putJson('/api/admin/v1/freelance-policies', ['country_id' => $this->saudi->id, 'data' => ['commission.value' => 1500, 'review.auto_approve' => false]])
            ->assertOk()->assertJsonPath('data.effective', fn ($e) => $e['commission.value'] === 1500 && $e['review.auto_approve'] === false);
        $this->putJson('/api/admin/v1/freelance-policies', ['country_id' => $this->saudi->id, 'data' => ['commission.value' => 20000]])
            ->assertUnprocessable()->assertJsonPath('error_code', 'freelance_policy_invalid')->assertJsonPath('data.keys', ['commission.value']);
        $this->getJson('/api/admin/v1/freelance-policies/scopes')->assertOk()->assertJsonCount(1, 'data');

        // The preview uses the form's unsaved numbers on top of what the scope inherits.
        $this->postJson('/api/admin/v1/freelance-policies/preview', ['country_id' => $this->saudi->id, 'amount_minor' => 100000, 'data' => ['commission.value' => 500]])
            ->assertOk()
            ->assertJsonPath('data.commission_minor', 5000)
            ->assertJsonPath('data.client_fee_minor', 2000)
            ->assertJsonPath('data.client_total_minor', 102000)
            ->assertJsonPath('data.freelancer_net_minor', 95000)
            ->assertJsonPath('data.currency.code', 'SAR');

        $this->postJson('/api/admin/v1/freelance-policies/copy', ['from_country_id' => $this->saudi->id, 'to_country_id' => $this->egypt->id])
            ->assertOk()->assertJsonPath('data.copied', 1);
        $this->assertSame(1500, $this->policies()->for($this->egypt->id)->get('commission.value'));
    }

    public function test_the_guide_is_a_word_file_with_every_key_the_countrys_numbers_and_a_worked_example(): void
    {
        $this->asAdmin(['freelance-policies.view']);
        $this->policies()->save($this->saudi->id, null, ['commission.value' => 1500, 'client_fee.type' => 'fixed', 'client_fee.value' => 300]);

        $response = $this->get('/api/admin/v1/freelance-policies/guide?country_id='.$this->saudi->id)->assertOk();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()) === true);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        foreach (array_keys(FreelancePolicyData::KEYS) as $key) {
            $this->assertStringContainsString($key, $xml);
        }
        $this->assertStringContainsString('الأسعار والقواعد', $xml);
        $this->assertStringContainsString('15%', $xml, 'the current commission');
        $this->assertStringContainsString('1,003.00', $xml, 'the example: 1000 + the fixed 3.00 fee');
        $this->assertStringContainsString('850.00', $xml, 'the example: 1000 - 15%');
    }

    public function test_the_screen_needs_its_permission(): void
    {
        $this->asAdmin(['freelance-policies.view']);
        $this->getJson('/api/admin/v1/freelance-policies?country_id='.$this->saudi->id)->assertOk();
        $this->putJson('/api/admin/v1/freelance-policies', ['country_id' => $this->saudi->id, 'data' => []])->assertForbidden();
        $this->postJson('/api/admin/v1/freelance-policies/copy', ['from_country_id' => $this->saudi->id, 'to_country_id' => $this->egypt->id])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->asAdmin([]);
        $this->getJson('/api/admin/v1/freelance-policies/options')->assertForbidden();
    }

    // -------------------------------------------------------------- the apps

    public function test_the_apps_get_the_currency_and_the_numbers_of_their_country(): void
    {
        FreelanceFeatureRule::query()->create(['feature' => 'freelance', 'country_id' => $this->egypt->id, 'enabled' => true]);
        FreelanceFeatures::forget();
        $this->policies()->save($this->egypt->id, null, ['client_fee.value' => 300, 'project.min_budget_minor' => 50000]);
        $freelancer = Freelancer::query()->create(['phone' => '+20100000001', 'country_id' => $this->egypt->id, 'phone_verified_at' => now()]);
        Sanctum::actingAs($freelancer, [], 'freelance_api');

        $this->getJson('/api/freelance/v1/config')->assertOk()
            ->assertJsonPath('data.currency.code', 'EGP')
            ->assertJsonPath('data.currency.decimal_places', 2)
            ->assertJsonPath('data.policy', fn ($p) => $p['client_fee.value'] === 300 && $p['project.min_budget_minor'] === 50000 && $p['commission.type'] === 'percent');
    }
}
