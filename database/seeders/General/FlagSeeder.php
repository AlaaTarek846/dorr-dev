<?php

namespace Database\Seeders\General;

use App\Models\Country;
use App\Models\Flag;
use App\Models\Language;
use Database\Seeders\Concerns\TruncatesBeforeSeeding;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Locale;

class FlagSeeder extends Seeder
{
    use TruncatesBeforeSeeding;

    public function run(): void
    {
        $this->detachUsersFromCountries();
        $this->truncateModels(Country::class, Language::class, Flag::class);

        /** @var list<array{code: string, statuses: array{is_status: int}}> $flags */
        $flags = require database_path('seeders/data/flags.php');

        $now = now();
        $rows = [];

        foreach ($flags as $flag) {
            $rows[] = [
                'code' => $flag['code'],
                'status' => (bool) $flag['statuses']['is_status'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('flags')->insert($chunk);
        }

        $flagIds = Flag::query()->pluck('id', 'code');
        $translationRows = [];

        foreach ($flags as $flag) {
            $code = $flag['code'];
            $region = '-'.strtoupper($code);

            foreach (['en', 'ar'] as $locale) {
                $translationRows[] = [
                    'flag_id' => $flagIds[$code],
                    'locale' => $locale,
                    'name' => Locale::getDisplayRegion($region, $locale) ?: strtoupper($code),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($translationRows, 100) as $chunk) {
            DB::table('flag_translations')->insert($chunk);
        }
    }
}
