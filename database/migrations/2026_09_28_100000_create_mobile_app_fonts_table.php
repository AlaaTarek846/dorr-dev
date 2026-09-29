<?php

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_app_fonts', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->boolean('status')->default(Status::Active->value);
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'sort_order']);
        });

        Schema::create('mobile_app_font_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mobile_app_font_id')
                ->constrained('mobile_app_fonts')
                ->cascadeOnDelete();
            $table->string('locale');
            $table->string('name');
            $table->timestamps();

            $table->unique(['mobile_app_font_id', 'locale']);
        });

        Schema::create('mobile_app_color_defaults', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->json('light_tokens');
            $table->json('dark_tokens');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_color_defaults');
        Schema::dropIfExists('mobile_app_font_translations');
        Schema::dropIfExists('mobile_app_fonts');
    }
};
