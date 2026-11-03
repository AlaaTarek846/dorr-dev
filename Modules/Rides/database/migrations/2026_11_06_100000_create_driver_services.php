<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O-89 (owner): the services a captain works in, set from the dashboard per country and per kind of
 * vehicle — a bus carries riders in Egypt but only runs bus trips in Saudi Arabia; a motorcycle carries
 * riders in Egypt but is hired by the hour (and delivers) in Saudi Arabia. The captain switches on the
 * ones they want among those open to their vehicle.
 */
return new class extends Migration
{
    /** key => [ar, en, ar description, en description, icon, colour, coming soon] */
    private const SERVICES = [
        'rides' => ['توصيل الركاب', 'Passenger rides', 'رحلات فورية من مكان إلى آخر', 'Instant rides from A to B', 'car', '#1E88E5', false],
        'hourly' => ['التأجير بالساعة', 'Hourly hire', 'رحلات بالساعة دون وجهة إلزامية', 'Rides by the hour, destination optional', 'timer', '#FB8C00', false],
        'scheduled' => ['الرحلات المجدولة', 'Scheduled rides', 'احجز رحلات قادمة مسبقًا', 'Reserve upcoming rides ahead', 'calendar', '#8E24AA', false],
        'airport' => ['طابور المطار', 'Airport queue', 'انتظر دورك في المطار', 'Wait your turn at the airport', 'flight', '#3949AB', false],
        'bus' => ['رحلات الباص', 'Bus trips', 'انشر رحلات بمقاعد محجوزة', 'Publish trips with booked seats', 'bus', '#5E35B1', false],
        'marine' => ['الرحلات البحرية', 'Sea trips', 'رحلات بحرية وتأجير بالساعة', 'Sea trips and hourly hire', 'boat', '#00ACC1', false],
        'hire' => ['سائق تحت الطلب', 'Driver on demand', 'قُد سيارة العميل بالساعة أو باليوم', 'Drive the customer\'s car by the hour or day', 'person', '#43A047', false],
        'lessons' => ['تعليم القيادة', 'Driving lessons', 'باقات دروس للمتدربين', 'Lesson packs for learners', 'school', '#7B1FA2', false],
        'parcels' => ['توصيل الطرود', 'Parcel delivery', 'توصيل الطرود والمشتريات', 'Deliver parcels and shopping', 'package', '#6D4C41', true],
        'food' => ['توصيل الطعام', 'Food delivery', 'توصيل الطلبات من المطاعم', 'Deliver orders from restaurants', 'restaurant', '#E53935', true],
    ];

    /** What each kind of vehicle does until the dashboard says otherwise (as the app worked so far). */
    public const DEFAULTS = [
        'car' => ['rides', 'hourly', 'scheduled', 'airport', 'hire', 'lessons'],
        'motorcycle' => ['rides', 'hourly', 'scheduled'],
        'minibus' => ['rides', 'scheduled', 'bus'],
        'bus' => ['bus'],
        'boat' => ['marine'],
        'yacht' => ['marine'],
        'none' => ['hire', 'lessons'],
    ];

    public function up(): void
    {
        Schema::create('driver_services', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();
            $table->json('name')->comment('{ar, en}');
            $table->json('description')->nullable()->comment('{ar, en}');
            $table->string('icon', 32)->default('car')->comment('the app draws it: car, timer, calendar, group, flight, bus, boat, person, school, package, restaurant');
            $table->string('color', 9)->default('#1E88E5');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('coming_soon')->default(false)->comment('shown, but nobody can switch it on yet');
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('driver_service_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_service_id')->constrained('driver_services')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->string('vehicle_kind', 16)->comment('car / motorcycle / minibus / bus / boat / yacht / none');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['driver_service_id', 'country_id', 'vehicle_kind'], 'driver_service_rules_unique');
            $table->index(['country_id', 'vehicle_kind']);
        });

        Schema::create('driver_service_choices', function (Blueprint $table) {
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->foreignId('driver_service_id')->constrained('driver_services')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->primary(['driver_id', 'driver_service_id']);
        });

        $now = now();
        $order = 0;
        foreach (self::SERVICES as $key => [$ar, $en, $dAr, $dEn, $icon, $color, $soon]) {
            DB::table('driver_services')->insert([
                'key' => $key, 'name' => json_encode(['ar' => $ar, 'en' => $en], JSON_UNESCAPED_UNICODE),
                'description' => json_encode(['ar' => $dAr, 'en' => $dEn], JSON_UNESCAPED_UNICODE),
                'icon' => $icon, 'color' => $color, 'sort_order' => ++$order, 'coming_soon' => $soon, 'status' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Every country already there starts with the defaults, which the dashboard then changes.
        $ids = DB::table('driver_services')->pluck('id', 'key');
        foreach (DB::table('countries')->pluck('id') as $countryId) {
            $rows = [];
            foreach (self::DEFAULTS as $kind => $keys) {
                foreach ($keys as $key) {
                    $rows[] = ['driver_service_id' => $ids[$key], 'country_id' => $countryId, 'vehicle_kind' => $kind, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now];
                }
            }
            DB::table('driver_service_rules')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_service_choices');
        Schema::dropIfExists('driver_service_rules');
        Schema::dropIfExists('driver_services');
    }
};
