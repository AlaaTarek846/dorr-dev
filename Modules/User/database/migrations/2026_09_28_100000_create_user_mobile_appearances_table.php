<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_mobile_appearances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('uses_default_colors')->default(true);
            $table->json('custom_light_tokens')->nullable();
            $table->json('custom_dark_tokens')->nullable();
            $table->foreignId('mobile_app_font_id')
                ->nullable()
                ->constrained('mobile_app_fonts')
                ->nullOnDelete();
            $table->string('dark_mode', 16)->default('system');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_mobile_appearances');
    }
};
