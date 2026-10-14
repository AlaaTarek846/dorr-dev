<?php

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Driver\Enums\VehicleStatus;

/** Vehicle catalogs and the drivers' vehicles (docs/rides-structure.md §1.5–1.6). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_makes', function (Blueprint $table) {
            $table->id();
            $table->boolean('status')->default(Status::Active->value);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('vehicle_make_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_make_id')->constrained('vehicle_makes')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 80)->comment('اسم الماركة');
            $table->unique(['vehicle_make_id', 'locale']);
        });

        Schema::create('vehicle_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('make_id')->constrained('vehicle_makes')->cascadeOnDelete();
            $table->string('body_type', 16)->comment('sedan / suv / van / minibus / bus / motorcycle / boat / yacht');
            $table->unsignedTinyInteger('seats')->comment('عدد المقاعد من غير السواق');
            $table->boolean('is_luxury')->default(false)->comment('مؤهلة لـ VIP');
            $table->boolean('status')->default(Status::Active->value);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('vehicle_model_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_model_id')->constrained('vehicle_models')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 80)->comment('اسم الموديل');
            $table->unique(['vehicle_model_id', 'locale']);
        });

        Schema::create('vehicle_colors', function (Blueprint $table) {
            $table->id();
            $table->char('hex', 7)->comment('اللون #RRGGBB');
            $table->boolean('status')->default(Status::Active->value);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('vehicle_color_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_color_id')->constrained('vehicle_colors')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 40)->comment('اسم اللون');
            $table->unique(['vehicle_color_id', 'locale']);
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete()->comment('دولة اللوحة');
            $table->foreignId('make_id')->constrained('vehicle_makes')->restrictOnDelete();
            $table->foreignId('model_id')->constrained('vehicle_models')->restrictOnDelete();
            $table->foreignId('color_id')->constrained('vehicle_colors')->restrictOnDelete();
            $table->unsignedSmallInteger('year')->comment('سنة الصنع');
            $table->string('body_type', 16)->comment('لقطة من الموديل وممكن تتعدل');
            $table->unsignedTinyInteger('seats')->comment('من غير السواق');
            $table->string('plate_letters', 8)->comment('حروف اللوحة بالعربي');
            $table->string('plate_number', 8)->comment('أرقام اللوحة');
            $table->unsignedSmallInteger('plate_type')->comment('كود نوع اللوحة عند وصل');
            $table->string('sequence_number', 16)->nullable()->comment('الرقم التسلسلي للاستمارة');
            $table->json('features')->nullable()->comment('child_seat / wheelchair_ramp / pet_friendly / large_luggage');
            $table->string('status', 16)->default(VehicleStatus::Pending->value);
            $table->boolean('is_active')->default(false)->comment('المركبة اللي شغال بيها دلوقتي');
            $table->foreignId('approved_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['country_id', 'plate_letters', 'plate_number', 'plate_type'], 'vehicles_plate_unique');
            $table->unique(['country_id', 'sequence_number']);
            $table->index(['driver_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('vehicle_color_translations');
        Schema::dropIfExists('vehicle_colors');
        Schema::dropIfExists('vehicle_model_translations');
        Schema::dropIfExists('vehicle_models');
        Schema::dropIfExists('vehicle_make_translations');
        Schema::dropIfExists('vehicle_makes');
    }
};
