<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Region;
use Database\Seeders\General\RegionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegionSeederTest extends TestCase
{
    use RefreshDatabase;

    private function country(string $code, string $alpha3, string $dial): Country
    {
        return Country::create([
            'code' => $code, 'code_alpha3' => $alpha3, 'dial_code' => $dial, 'phone_starts_with' => '5', 'phone_length' => 9,
            'is_default' => false, 'flag_id' => Flag::query()->value('id') ?? Flag::create(['code' => 'xx'])->id,
            'currency_id' => Currency::query()->value('id') ?? Currency::create(['code' => 'XXX', 'symbol' => 'X'])->id, 'status' => true,
        ]);
    }

    private function names(Region $region): array
    {
        return $region->translations()->pluck('name', 'locale')->all();
    }

    public function test_it_seeds_the_first_level_divisions_in_both_languages(): void
    {
        $saudi = $this->country('SA', 'SAU', '+966');
        $algeria = $this->country('DZ', 'DZA', '+213');

        $this->seed(RegionSeeder::class);

        $first = Region::query()->where('country_id', $saudi->id)->orderBy('sort_order')->first();
        $this->assertSame(['en' => 'Riyadh', 'ar' => 'الرياض'], $this->names($first));
        $this->assertSame(1, $first->sort_order);
        $this->assertTrue($first->status);
        // Saudi Arabia has 13 regions, Algeria 58 wilayas.
        $this->assertSame(13, Region::query()->where('country_id', $saudi->id)->count());
        $this->assertSame(58, Region::query()->where('country_id', $algeria->id)->count());
    }

    public function test_it_can_run_again_without_duplicating_or_touching_the_admins_regions(): void
    {
        $egypt = $this->country('EG', 'EGY', '+20');

        $this->seed(RegionSeeder::class);
        $count = Region::query()->where('country_id', $egypt->id)->count();

        $mine = Region::create(['country_id' => $egypt->id, 'status' => false]);
        $mine->translations()->create(['locale' => 'en', 'name' => 'My Region']);

        $this->seed(RegionSeeder::class);

        $this->assertSame($count + 1, Region::query()->where('country_id', $egypt->id)->count());
        $this->assertFalse($mine->fresh()->status);
    }

    public function test_a_country_that_is_not_listed_gets_none_and_a_missing_country_is_skipped(): void
    {
        $japan = $this->country('JP', 'JPN', '+81');

        $this->seed(RegionSeeder::class);

        // Only the listed countries that exist here get regions.
        $this->assertSame(0, Region::query()->where('country_id', $japan->id)->count());
        $this->assertSame(0, Region::query()->count());
    }
}
