<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()
                ->constrained('service_categories')->nullOnDelete();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->string('department')->nullable();
            $table->string('base_model')->nullable();
            $table->boolean('requires_provider')->default(true);
            $table->string('provider_type_label')->nullable();
            // Named `status` (not `is_active`) to match this project's own
            // convention for active/inactive catalog rows (Currency, Flag,
            // Country, Admin, User) - keeps the shared admin list/filter
            // frontend components (which key off a `status` column) working
            // without special-casing this resource.
            $table->boolean('status')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_categories');
    }
};
