<?php

namespace Modules\AI\Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Database\Seeders\AiSiteSeeder;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiSiteHostingPlan;
use Modules\AI\Models\AiSiteOffer;
use Tests\TestCase;

class AiSiteSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_offer_hosting_plans_prices_and_is_rerunnable(): void
    {
        $egp = Currency::create(['code' => 'EGP', 'symbol' => 'ج.م', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'eg']);
        $country = Country::create([
            'code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10,
            'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $egp->id, 'status' => true,
        ]);
        AiPlan::query()->create(['code' => 'pro', 'name' => 'Pro', 'usage_minutes' => 10, 'cooldown_minutes' => 1, 'duration_days' => 30, 'price' => 1, 'is_active' => true]);

        $this->seed(AiSiteSeeder::class);
        AiSiteOffer::query()->update(['name' => 'edited']);
        $this->seed(AiSiteSeeder::class);

        $this->assertSame(1, AiSiteOffer::query()->count());
        $this->assertSame(2, AiSiteHostingPlan::query()->count());
        $offer = AiSiteOffer::query()->first();
        $this->assertSame(1, $offer->prices()->where('country_id', $country->id)->count());
        $this->assertSame(3, AiPlan::query()->where('code', 'pro')->value('site_projects_limit'));
    }
}
