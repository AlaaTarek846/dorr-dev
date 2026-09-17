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
        Schema::create('dashboard_theme_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dashboard_theme_id')
                ->constrained('dashboard_themes')
                ->restrictOnDelete()
                ->comment('الثيم المختار');
            $table->string('authenticatable_type')->comment('نوع الحساب');
            $table->unsignedBigInteger('authenticatable_id')->comment('معرّف الحساب');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['authenticatable_type', 'authenticatable_id'],
                'dashboard_theme_preferences_authenticatable_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dashboard_theme_preferences');
    }
};
