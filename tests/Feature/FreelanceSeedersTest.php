<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Freelance\Database\Seeders\FreelanceDemoSeeder;
use Modules\Freelance\Database\Seeders\FreelanceSettingsSeeder;
use Modules\Freelance\Database\Seeders\FreelanceSkillSeeder;
use Modules\Freelance\Database\Seeders\FreelanceSpecialtySeeder;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\User\Models\User;
use Tests\TestCase;

/** The freelance seeders: the switches for every active country, and the demo data — both safe to run again. */
class FreelanceSeedersTest extends TestCase
{
    use RefreshDatabase;

    private function country(): Country
    {
        return Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
    }

    public function test_the_switches_are_added_once_and_a_switch_an_admin_turned_off_stays_off(): void
    {
        $country = $this->country();
        $this->seed(FreelanceSettingsSeeder::class);
        $this->assertSame(count(FreelanceFeatures::all()), FreelanceFeatureRule::query()->where('country_id', $country->id)->where('enabled', true)->count());

        FreelanceFeatureRule::query()->where('feature', 'bidding')->update(['enabled' => false]);
        $this->seed(FreelanceSettingsSeeder::class);

        $this->assertSame(count(FreelanceFeatures::all()), FreelanceFeatureRule::query()->count());
        $this->assertFalse((bool) FreelanceFeatureRule::query()->where('feature', 'bidding')->value('enabled'));
    }

    public function test_the_demo_data_is_complete_and_running_it_again_adds_nothing(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->country();
        $this->seed([FreelanceSpecialtySeeder::class, FreelanceSkillSeeder::class, FreelanceSettingsSeeder::class, FreelanceDemoSeeder::class]);

        $counts = fn () => [User::query()->count(), Freelancer::query()->count(), FreelanceProject::query()->count(), \Modules\Freelance\Models\FreelanceOffer::query()->count(), \DB::table('freelance_project_events')->count(), \DB::table('freelancer_skills')->count(), \DB::table('wallet_transactions')->count()];
        $first = $counts();
        $this->assertSame([4, 8, 24], array_slice($first, 0, 3));
        $this->assertGreaterThan(0, $first[3], 'some offers are seeded');
        $this->assertSame(10, FreelanceProject::query()->where('status', 'published')->count());
        $this->assertSame(4, Freelancer::query()->where('status', 'verified')->count());
        $this->assertTrue(FreelanceProject::query()->where('status', 'rejected')->whereNotNull('status_reason')->exists());
        $this->assertGreaterThan(0, $first[6]);

        // A picture for every freelancer that has a profile, two images for each work; a second run adds none.
        $media = fn () => [DB::table('media')->where('collection_name', 'avatar')->count(), DB::table('media')->where('collection_name', 'images')->count()];
        $this->assertSame([7, 14], $media());

        $this->seed(FreelanceDemoSeeder::class);
        $this->assertSame($first, $counts());
        $this->assertSame([7, 14], $media());
    }

    public function test_the_demo_seeder_does_nothing_in_production(): void
    {
        $this->country();
        $this->seed([FreelanceSettingsSeeder::class]);
        $this->app['env'] = 'production';
        (new FreelanceDemoSeeder)->run();

        $this->assertSame(0, Freelancer::query()->count());
    }
}
