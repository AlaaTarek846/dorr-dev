<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('country_service_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained('service_categories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['country_id', 'service_category_id'], 'country_service_category_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('country_service_category');
    }
};
