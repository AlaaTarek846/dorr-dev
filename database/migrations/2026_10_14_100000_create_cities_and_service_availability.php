<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->boolean('status')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('city_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name');
            $table->timestamps();
        });

        // Per service and place (a country, or one of its cities) the switches that decide who can use the service.
        // On a city row an empty (null) switch means "as the country says"; on a country row it means "on".
        Schema::create('service_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_category_id')->constrained('service_categories')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->cascadeOnDelete();
            $table->boolean('service_enabled')->nullable();
            $table->boolean('customer_enabled')->nullable();
            // What the customer sees while the customer switch is off: hidden | coming_soon | view_only.
            $table->string('customer_mode', 20)->nullable();
            $table->boolean('provider_enabled')->nullable();
            $table->timestamps();

            $table->index(['service_category_id', 'country_id', 'city_id'], 'service_availability_place');
        });

        // The "driver" audience is gone: a driver is a provider.
        DB::table('service_categories')->whereNotNull('audiences')->orderBy('id')->each(function ($row) {
            $list = json_decode((string) $row->audiences, true);

            if (! is_array($list) || ! in_array('driver', $list, true)) {
                return;
            }

            $list = array_values(array_unique(array_map(fn ($a) => $a === 'driver' ? 'provider' : $a, $list)));
            DB::table('service_categories')->where('id', $row->id)->update(['audiences' => json_encode($list)]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_availability');
        Schema::dropIfExists('city_translations');
        Schema::dropIfExists('cities');
    }
};
