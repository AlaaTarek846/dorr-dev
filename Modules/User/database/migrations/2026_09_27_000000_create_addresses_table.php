<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saved user addresses (home / work / other) with an optional pinned
     * location and a single default flag per user.
     */
    public function up(): void
    {
        Schema::create('user_addresses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('صاحب العنوان');
            $table->enum('type', ['home', 'work', 'other'])->comment('المنزل / العمل / أخرى');
            $table->string('title')->nullable()->comment('اسم مخصص للعنوان لو المستخدم كتبه');
            $table->string('building_number')->nullable()->comment('رقم المبنى');
            $table->string('floor')->nullable()->comment('الدور');
            $table->string('address_details')->nullable()->comment('الحي والشارع والمدينة');
            $table->string('landmark')->nullable()->comment('علامة مميزة');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_default')->default(false)->comment('العنوان الافتراضي لليوزر');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id']);
            $table->index(['user_id', 'is_default']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
