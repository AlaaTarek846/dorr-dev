<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Driver\Enums\DriverStatus;
use Modules\Driver\Models\Driver;
use Modules\Rides\Models\DriverDailyStat;
use Modules\Rides\Services\Policy\RidePolicies;
use Tests\TestCase;

/**
 * Batch 5 of docs/rides_tasks.md: the captain's earnings report (D-193..D-197) — the last 3 / 6 / 9 /
 * 12 months or a range picked by hand — set against the period of the same length right before it
 * (D-200..D-202): the figures, the difference in money and in percent, and a direction word.
 */
class RidesEarningsReportTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id,
        ]);
        $this->driver = $this->captain('Omar', '+966550000001');
        // "Today" is the 15th of October, midday in Riyadh.
        $this->travelTo(now('Asia/Riyadh')->setDate(2026, 10, 15)->setTime(12, 0));
    }

    public function test_a_preset_period_is_summed_compared_with_the_one_before_and_split_by_month(): void
    {
        // 3 months back from 15 Oct → 16 Jul … 15 Oct (92 days); the one before is 15 Apr … 15 Jul.
        $this->stat($this->driver, '2026-10-10', trips: 5, net: 10000, gross: 12000, tips: 500, online: 7200);
        $this->stat($this->driver, '2026-08-01', trips: 3, net: 5000, gross: 6000, tips: 0, online: 3600);
        $this->stat($this->driver, '2026-05-01', trips: 4, net: 8000, gross: 9000, tips: 0, online: 3600);
        $this->stat($this->driver, '2026-04-01', trips: 9, net: 99999, gross: 99999, tips: 0, online: 1); // before both: ignored
        $other = $this->captain('Other', '+966550000002');
        $this->stat($other, '2026-10-10', trips: 50, net: 777777, gross: 777777, tips: 0, online: 1);     // someone else's: ignored

        $res = $this->asDriver($this->driver)->getJson('/api/driver/v1/earnings/report?period=3m')->assertOk();
        $data = $res->json('data');

        $this->assertSame(['key' => '3m', 'from' => '2026-07-16', 'to' => '2026-10-15', 'days' => 92], $data['period']);
        $this->assertSame('2026-04-15', $data['previous']['from']);
        $this->assertSame('2026-07-15', $data['previous']['to']);
        $this->assertSame('SAR', $data['currency']['code']);

        $this->assertSame([8, 15000, 18000, 500, 10800], [$data['totals']['trips'], $data['totals']['net_minor'], $data['totals']['gross_minor'], $data['totals']['tips_minor'], $data['totals']['online_s']]);
        $this->assertSame([4, 8000], [$data['previous']['totals']['trips'], $data['previous']['totals']['net_minor']]);

        // D-200/D-201: 15000 against 8000 → +7000, +87.5 %.
        $this->assertSame(['value' => 7000, 'pct_bp' => 8750, 'direction' => 'up'], $data['change']['net_minor']);
        $this->assertSame(['value' => 4, 'pct_bp' => 10000, 'direction' => 'up'], $data['change']['trips']);
        // Tips: 500 now, nothing before → "new", no percentage to give.
        $this->assertSame(['value' => 500, 'pct_bp' => null, 'direction' => 'new'], $data['change']['tips_minor']);

        // Longer than two months → by month, every month of the range present (empty ones as zeros).
        $this->assertSame('month', $data['series']['unit']);
        $this->assertSame(['2026-07', '2026-08', '2026-09', '2026-10'], array_column($data['series']['points'], 'key'));
        $this->assertSame([0, 5000, 0, 10000], array_column($data['series']['points'], 'net_minor'));
    }

    public function test_every_preset_reaches_back_its_months(): void
    {
        foreach (['6m' => '2026-04-16', '9m' => '2026-01-16', '12m' => '2025-10-16'] as $key => $from) {
            $this->asDriver($this->driver)->getJson('/api/driver/v1/earnings/report?period='.$key)->assertOk()
                ->assertJsonPath('data.period.from', $from)->assertJsonPath('data.period.to', '2026-10-15');
        }
        // Nothing earned at all: zeros, and "same" rather than a divide by nothing.
        $this->asDriver($this->driver)->getJson('/api/driver/v1/earnings/report?period=12m')->assertOk()
            ->assertJsonPath('data.totals.net_minor', 0)->assertJsonPath('data.change.net_minor.direction', 'same')->assertJsonPath('data.change.net_minor.pct_bp', null);
    }

    public function test_a_custom_range_is_by_day_and_can_go_down_or_be_new(): void
    {
        $this->stat($this->driver, '2026-10-05', trips: 2, net: 2000, gross: 2500, tips: 0, online: 600);
        $this->stat($this->driver, '2026-09-25', trips: 5, net: 5000, gross: 6000, tips: 0, online: 1200);

        // 1–10 Oct (10 days) against 21–30 Sep.
        $res = $this->asDriver($this->driver)->getJson('/api/driver/v1/earnings/report?period=custom&from=2026-10-01&to=2026-10-10')->assertOk();
        $data = $res->json('data');
        $this->assertSame(10, $data['period']['days']);
        $this->assertSame(['from' => '2026-09-21', 'to' => '2026-09-30'], ['from' => $data['previous']['from'], 'to' => $data['previous']['to']]);
        // 2000 against 5000 → −3000, −60 % (D-202: the direction is a word, not only a colour).
        $this->assertSame(['value' => -3000, 'pct_bp' => -6000, 'direction' => 'down'], $data['change']['net_minor']);
        $this->assertSame('day', $data['series']['unit']);
        $this->assertCount(10, $data['series']['points']);
        $this->assertSame(2000, collect($data['series']['points'])->firstWhere('key', '2026-10-05')['net_minor']);

        // A range with nothing before it.
        $this->asDriver($this->driver)->getJson('/api/driver/v1/earnings/report?period=custom&from=2026-10-04&to=2026-10-06')->assertOk()
            ->assertJsonPath('data.change.net_minor.direction', 'new')->assertJsonPath('data.change.net_minor.pct_bp', null);
    }

    public function test_the_range_must_be_sensible_and_is_capped_at_today_and_by_policy(): void
    {
        // Missing or back-to-front dates and unknown periods.
        $this->asDriver($this->driver)->getJson('/api/driver/v1/earnings/report?period=custom')->assertStatus(422);
        $this->asDriver($this->driver)->getJson('/api/driver/v1/earnings/report?period=custom&from=2026-10-10&to=2026-10-01')->assertStatus(422);
        $this->asDriver($this->driver)->getJson('/api/driver/v1/earnings/report?period=2m')->assertStatus(422);
        $this->asDriver($this->driver)->getJson('/api/driver/v1/earnings/report')->assertStatus(422);

        // Wholly in the future: nothing to report.
        $this->asDriver($this->driver)->getJson('/api/driver/v1/earnings/report?period=custom&from=2026-11-01&to=2026-11-05')
            ->assertStatus(422)->assertJsonPath('error_code', 'rides_earnings_range_in_future');

        // The end is capped at today.
        $this->asDriver($this->driver)->getJson('/api/driver/v1/earnings/report?period=custom&from=2026-10-10&to=2026-12-31')->assertOk()
            ->assertJsonPath('data.period.to', '2026-10-15')->assertJsonPath('data.period.days', 6);

        // The longest range is the policy's (D-197).
        $this->asDriver($this->driver)->getJson('/api/driver/v1/earnings/report?period=custom&from=2026-01-01&to=2026-10-15')->assertOk();
        app(RidePolicies::class)->save($this->saudi->id, null, null, null, ['earnings.report_max_days' => 100]);
        RidePolicies::forget();
        $this->asDriver($this->driver)->getJson('/api/driver/v1/earnings/report?period=custom&from=2026-01-01&to=2026-10-15')
            ->assertStatus(422)->assertJsonPath('error_code', 'rides_earnings_range_too_long');
        $this->asDriver($this->driver)->getJson('/api/driver/v1/earnings/report?period=custom&from=2026-07-08&to=2026-10-15')->assertOk(); // exactly 100 days
    }

    public function test_the_report_needs_a_signed_in_captain(): void
    {
        $this->getJson('/api/driver/v1/earnings/report?period=3m')->assertUnauthorized();
    }

    private function captain(string $name, string $phone): Driver
    {
        return Driver::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => $name, 'phone' => $phone, 'phone_verified_at' => now(),
            'country_id' => $this->saudi->id, 'status' => DriverStatus::Active,
        ]);
    }

    private function stat(Driver $driver, string $date, int $trips, int $net, int $gross, int $tips, int $online): void
    {
        DriverDailyStat::query()->create([
            'driver_id' => $driver->id, 'stat_date' => $date, 'trips' => $trips, 'net_minor' => $net, 'gross_minor' => $gross,
            'tips_minor' => $tips, 'online_s' => $online,
        ]);
    }

    private function asDriver(Driver $driver): static
    {
        return $this->actingAs($driver, 'driver_api');
    }
}
