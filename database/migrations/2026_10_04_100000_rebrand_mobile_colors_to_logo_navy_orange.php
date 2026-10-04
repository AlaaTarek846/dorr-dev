<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dorr's identity is the logo's navy (#001B53) and orange (#FA7552), not the preview's red.
 * The stored platform colours move to the new defaults (config/mobile_appearance.php) — but only
 * the tokens still holding the old seeded value: anything an admin picked stays as it is.
 * People's own appearance overrides are never touched.
 */
return new class extends Migration
{
    /** token => [old seeded value, new value] */
    private const LIGHT = [
        'primary' => ['#E50914', '#001B53'],
        'primaryLight' => ['#F2202C', '#1E3A7B'],
        'primaryDark' => ['#B30710', '#00113A'],
        'secondary' => ['#111928', '#FA7552'],
        'accent' => ['#FF8A4C', '#FA7552'],
        'authAccent' => ['#E50914', '#001B53'],
        'authGlowDeep' => ['#EFA8B4', '#F8BCA9'],
        'authGlowMid' => ['#F3C4CC', '#FBD2C4'],
        'authGlowSoft' => ['#F0B8C2', '#F9C4B4'],
        'authWell' => ['#FDE8EC', '#FFEEE8'],
        'authBorderPink' => ['#F8B4C0', '#FBC8B7'],
        'authTextEmphasis' => ['#991B1B', '#001B53'],
    ];

    private const DARK = [
        'primary' => ['#2DA8B0', '#FA7552'],
        'primaryLight' => ['#2DA8B0', '#FF8E6E'],
        'primaryDark' => ['#166064', '#D9583A'],
        'secondary' => ['#0E9F6E', '#8EA6DD'],
        'accent' => ['#FF8A4C', '#FA7552'],
        'authAccent' => ['#FF4D57', '#FA7552'],
        'authGlowAccent' => ['#E50914', '#FA7552'],
    ];

    public function up(): void
    {
        $this->swap(0, 1);
    }

    public function down(): void
    {
        $this->swap(1, 0);
    }

    private function swap(int $from, int $to): void
    {
        foreach (DB::table('mobile_app_color_defaults')->get(['id', 'light_tokens', 'dark_tokens']) as $row) {
            $light = json_decode((string) $row->light_tokens, true) ?: [];
            $dark = json_decode((string) $row->dark_tokens, true) ?: [];

            foreach ([[&$light, self::LIGHT], [&$dark, self::DARK]] as [&$tokens, $map]) {
                foreach ($map as $key => $pair) {
                    if (isset($tokens[$key]) && strcasecmp((string) $tokens[$key], $pair[$from]) === 0) {
                        $tokens[$key] = $pair[$to];
                    }
                }
            }
            unset($tokens);

            DB::table('mobile_app_color_defaults')->where('id', $row->id)->update([
                'light_tokens' => json_encode($light),
                'dark_tokens' => json_encode($dark),
                'updated_at' => now(),
            ]);
        }
    }
};
