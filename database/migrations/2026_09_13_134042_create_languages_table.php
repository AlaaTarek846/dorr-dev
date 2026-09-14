<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\Status;
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->enum('direction', ['rtl', 'ltr'])->default('ltr');
            $table->boolean('is_default_website')->default(Status::Inactive->value);
            $table->boolean('is_default_dashboard')->default(Status::Inactive->value);
            $table->boolean('stores_translation')->default(Status::Inactive->value);
            $table->boolean('status')->default(Status::Active->value);
            $table->foreignId('flag_id')->constrained('flags');
            $table->timestamps();
        });

        Schema::create('language_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained('languages');
            $table->string('locale');
            $table->string('name');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
