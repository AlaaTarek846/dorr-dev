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
        Schema::create('flags', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('كود العلم');
            $table->boolean('status')->default(Status::Active->value)->comment('الحالة');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('flag_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flag_id')->constrained('flags');
            $table->string('locale')->comment('اللغة');
            $table->string('name')->comment('اسم العلم');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flag_translations');
        Schema::dropIfExists('flags');
    }
};
