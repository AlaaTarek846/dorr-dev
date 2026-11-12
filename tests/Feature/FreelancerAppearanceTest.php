<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Models\Freelancer;
use Tests\TestCase;

/** The freelancer app's colours, dark mode and font: the DORR app's appearance settings, kept per freelancer on `freelance/v1/appearance`. */
class FreelancerAppearanceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/freelance/v1/appearance';

    private Country $saudi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
    }

    private function freelancer(string $phone): Freelancer
    {
        return Freelancer::query()->create(['name' => 'Sara', 'phone' => $phone, 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
    }

    private function as(Freelancer $f): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($f->refresh(), [], 'freelance_api');
    }

    public function test_it_needs_a_signed_in_freelancer(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->putJson(self::URL, ['dark_mode' => 'dark'])->assertUnauthorized();
    }

    public function test_a_new_freelancer_gets_the_platform_defaults(): void
    {
        $this->as($this->freelancer('+966500000001'));

        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.uses_default_colors', true)
            ->assertJsonPath('data.dark_mode', 'system')
            ->assertJsonPath('data.mobile_app_font_id', null)
            ->assertJsonStructure(['data' => ['default' => ['light_tokens', 'dark_tokens'], 'resolved' => ['light_tokens', 'dark_tokens'], 'available_fonts', 'customizable_token_keys']]);
    }

    public function test_the_choice_is_saved_for_that_freelancer_only(): void
    {
        $sara = $this->freelancer('+966500000001');
        $lina = $this->freelancer('+966500000002');

        $this->as($sara);
        $this->putJson(self::URL, ['dark_mode' => 'dark'])->assertOk()->assertJsonPath('data.dark_mode', 'dark');
        $this->getJson(self::URL)->assertJsonPath('data.dark_mode', 'dark');
        $this->assertDatabaseHas('freelancer_mobile_appearances', ['freelancer_id' => $sara->id, 'dark_mode' => 'dark']);

        $this->as($lina);
        $this->getJson(self::URL)->assertJsonPath('data.dark_mode', 'system');
        $this->assertDatabaseMissing('freelancer_mobile_appearances', ['freelancer_id' => $lina->id]);
    }

    public function test_own_colours_replace_the_defaults_and_can_be_reset(): void
    {
        $this->as($this->freelancer('+966500000001'));

        $this->putJson(self::URL, ['uses_default_colors' => false, 'custom_light_tokens' => ['primary' => '#112233']])
            ->assertOk()->assertJsonPath('data.uses_default_colors', false)->assertJsonPath('data.resolved.light_tokens.primary', '#112233');

        $this->putJson(self::URL, ['uses_default_colors' => true])->assertOk()->assertJsonPath('data.uses_default_colors', true);
    }

    public function test_a_wrong_value_is_refused(): void
    {
        $this->as($this->freelancer('+966500000001'));

        $this->putJson(self::URL, ['dark_mode' => 'purple'])->assertUnprocessable();
        $this->putJson(self::URL, ['mobile_app_font_id' => 999999])->assertUnprocessable();
    }

    public function test_a_dorr_user_token_cannot_use_it(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(\Modules\User\Models\User::query()->create(['name' => 'Mona', 'phone' => '+966500000009', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]), [], 'user_api');

        $this->getJson(self::URL)->assertUnauthorized();
    }
}
