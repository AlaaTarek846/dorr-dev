<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_app_color_defaults', function (Blueprint $table) {
            $table->json('light_gradients')->nullable()->after('dark_tokens');
            $table->json('dark_gradients')->nullable()->after('light_gradients');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_app_color_defaults', function (Blueprint $table) {
            $table->dropColumn(['light_gradients', 'dark_gradients']);
        });
    }
};
