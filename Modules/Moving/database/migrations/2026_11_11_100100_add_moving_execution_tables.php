<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B-08 → B-14 of docs/moving_tasks.md: moving day (the driver's last place, the numbered inventory with photos),
 * delivery by the customer's code (or recorded without it), the objection window, the money moving to the
 * establishment, and the cases: a customer's report (part of the money stays held) or a request for an exception.
 */
return new class extends Migration
{
    private const CATEGORIES = [
        ['moving_commissions', 'income', 'Moving commissions', 'عمولات نقل العفش'],
        ['moving_taxes', 'income', 'Moving taxes', 'ضرائب نقل العفش'],
    ];

    public function up(): void
    {
        Schema::table('moving_requests', function (Blueprint $table) {
            $table->string('delivery_code', 8)->nullable()->after('payment_status')->comment('the customer gives it at the door (or the QR holds it)');
            $table->decimal('driver_lat', 10, 7)->nullable()->after('delivery_code');
            $table->decimal('driver_lng', 10, 7)->nullable()->after('driver_lat');
            $table->dateTime('driver_location_at')->nullable()->after('driver_lng');
            $table->dateTime('delivered_at')->nullable()->after('driver_location_at');
            $table->string('delivery_ack', 12)->nullable()->after('delivered_at')->comment('code / refused / absent — silence is never consent (REQ-033)');
            $table->string('recipient_name', 120)->nullable()->after('delivery_ack');
            $table->dateTime('objection_until')->nullable()->after('recipient_name');
            $table->dateTime('settled_at')->nullable()->after('objection_until');
            $table->unsignedBigInteger('refunded_minor')->nullable()->after('settled_at');
        });

        Schema::create('moving_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('request_id')->constrained('moving_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('label', 150);
            $table->unsignedSmallInteger('trip_no')->default(1);
            $table->string('condition_note', 500)->nullable()->comment('as found before loading');
            $table->string('delivered_note', 500)->nullable()->comment('a reservation at delivery (REQ-033)');
            $table->timestamps();
            $table->unique(['request_id', 'number']);
        });

        Schema::create('moving_cases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('request_id')->constrained('moving_requests')->cascadeOnDelete();
            $table->string('kind', 12)->comment('report (damage, loss…) / exception (cancelling for a reason that is the other side\'s fault or force majeure)');
            $table->string('opened_by', 12)->comment('user / provider');
            $table->unsignedBigInteger('opener_id');
            $table->string('reason_code', 40);
            $table->text('details')->nullable();
            $table->unsignedBigInteger('claim_minor')->nullable();
            $table->unsignedBigInteger('held_minor')->nullable()->comment('what stays held for it (REQ-052)');
            $table->string('status', 12)->default('open')->comment('open / decided / withdrawn');
            $table->string('decision', 16)->nullable()->comment('accepted / partial / rejected');
            $table->unsignedBigInteger('award_minor')->nullable();
            $table->string('decision_note', 1000)->nullable();
            $table->foreignId('decided_by_admin_id')->nullable()->constrained('admins', 'id', 'moving_case_admin_fk')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();
            $table->index(['request_id', 'status']);
            $table->index(['status', 'kind']);
        });

        if (Schema::hasTable('financial_categories')) {
            foreach (self::CATEGORIES as [$slug, $type, $en, $ar]) {
                if (DB::table('financial_categories')->where('slug', $slug)->exists()) {
                    continue;
                }
                $id = DB::table('financial_categories')->insertGetId(['slug' => $slug, 'type' => $type, 'is_system' => true, 'status' => true, 'created_at' => now(), 'updated_at' => now()]);
                if (Schema::hasTable('financial_category_translations')) {
                    foreach (['en' => $en, 'ar' => $ar] as $locale => $name) {
                        DB::table('financial_category_translations')->insert(['financial_category_id' => $id, 'locale' => $locale, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('moving_cases');
        Schema::dropIfExists('moving_inventory_items');
        Schema::table('moving_requests', function (Blueprint $table) {
            $table->dropColumn(['delivery_code', 'driver_lat', 'driver_lng', 'driver_location_at', 'delivered_at', 'delivery_ack', 'recipient_name', 'objection_until', 'settled_at', 'refunded_minor']);
        });
    }
};
