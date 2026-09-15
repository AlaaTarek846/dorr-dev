<?php

namespace Database\Seeders\Concerns;

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
        \App\Models\Country::class => 'country_translations',
        \App\Models\Currency::class => 'currency_translations',
        \App\Models\Language::class => 'language_translations',
        \App\Models\Flag::class => 'flag_translations',
    ];

    protected function detachUsersFromCountries(): void
    {
        Admin::query()->update(['country_id' => null]);
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
