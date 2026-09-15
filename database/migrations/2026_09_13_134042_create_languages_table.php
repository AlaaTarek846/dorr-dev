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
            $table->string('code')->unique()->comment('كود اللغة');
            $table->enum('direction', ['rtl', 'ltr'])->default('ltr')->comment('اتجاه الكتابة');
            $table->boolean('is_default_website')->default(Status::Inactive->value)->comment('اللغة الافتراضية للموقع');
            $table->boolean('is_default_dashboard')->default(Status::Inactive->value)->comment('اللغة الافتراضية للوحة التحكم');
            $table->boolean('stores_translation')->default(Status::Inactive->value)->comment('هل تُحفظ ترجمة هذه اللغة في قاعدة البيانات');
            $table->boolean('status')->default(Status::Active->value)->comment('الحالة');
            $table->foreignId('flag_id')->constrained('flags')->comment('العلم');
            $table->timestamps();
        });

        Schema::create('language_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained('languages')->comment('اللغة');
            $table->string('locale')->comment('اللغة');
            $table->string('name')->comment('اسم اللغة');
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
