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
        Schema::create('verification_codes', function (Blueprint $table) {
            $table->id();
            $table->morphs('authenticatable');
            $table->string('type')->comment('phone / email');
            $table->string('code')->comment('رمز OTP');
            $table->timestamp('expires_at')->comment('وقت انتهاء الكود');
            $table->timestamp('verified_at')->nullable()->comment('وقت التحقق');
            $table->unsignedInteger('attempts')->default(0)->comment('عدد المحاولات');
            $table->timestamps();

            $table->index(
                ['authenticatable_type', 'authenticatable_id', 'type'],
                'verification_codes_authenticatable_type_index',
            );
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('verification_codes');
    }
};
