<?php

namespace Database\Seeders\Concerns;

use App\Models\Country;
use App\Models\Currency;
use App\Models\DashboardTheme;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Admin\Models\Admin;

trait TruncatesBeforeSeeding
{
    /**
     * @var array<class-string<Model>, string>
     */
    protected array $translationTables = [
        Country::class => 'country_translations',
        Currency::class => 'currency_translations',
        Language::class => 'language_translations',
        Flag::class => 'flag_translations',
        DashboardTheme::class => 'dashboard_theme_translations',
    ];

    protected function detachUsersFromCountries(): void
    {
        Admin::query()->update(['country_id' => null]);
    }

    /**
     * Truncate Spatie permission/role tables (pivot tables first).
     *
     * @param  class-string<Model>  $models
     */
    protected function truncatePermissionModels(string ...$models): void
    {
        $tables = config('permission.table_names');

        Schema::disableForeignKeyConstraints();

        DB::table($tables['role_has_permissions'])->truncate();
        DB::table($tables['model_has_roles'])->truncate();
        DB::table($tables['model_has_permissions'])->truncate();

        foreach ($models as $model) {
            /** @var Model $instance */
            $instance = new $model;
            DB::table($instance->getTable())->truncate();
        }

        Schema::enableForeignKeyConstraints();
    }

    /**
     * @param  class-string<Model>  $models
     */
    protected function truncateModels(string ...$models): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ($models as $model) {
            if (isset($this->translationTables[$model])) {
                DB::table($this->translationTables[$model])->truncate();
            }

            /** @var Model $instance */
            $instance = new $model;
            DB::table($instance->getTable())->truncate();
        }

        Schema::enableForeignKeyConstraints();
    }
}
