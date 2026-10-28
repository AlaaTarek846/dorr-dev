<?php

namespace Modules\AI\Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Database\Seeders\AiWorldSeeder;
use Modules\AI\Models\World\AiWorldPrice;
use Tests\TestCase;

class AiWorldSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_prices_for_known_currencies_and_keeps_admin_edits(): void
    {
        $country = Country::create([
            'code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'eg'])->id,
            'currency_id' => Currency::create(['code' => 'EGP', 'symbol' => 'ج.م', 'decimal_places' => 2])->id,
        ]);

        $this->seed(AiWorldSeeder::class);
        $this->assertSame(1000, AiWorldPrice::where('country_id', $country->id)->where('quality', 'standard')->value('price_per_second_minor'));

        AiWorldPrice::where('country_id', $country->id)->where('quality', 'standard')->update(['price_per_second_minor' => 1234]);
        $this->seed(AiWorldSeeder::class);

        $this->assertSame(1234, AiWorldPrice::where('country_id', $country->id)->where('quality', 'standard')->value('price_per_second_minor'));
        $this->assertSame(2, AiWorldPrice::count());
    }
}
