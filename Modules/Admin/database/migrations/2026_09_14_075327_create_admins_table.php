<?php

use App\Enums\Gender;
use App\Enums\Status;
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
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('الاسم');
            $table->string('email')->unique()->comment('البريد الإلكتروني');
            $table->string('password')->comment('كلمة المرور');
            $table->string('phone')->nullable()->comment('رقم الهاتف');
            $table->boolean('status')->default(Status::Active->value)->comment('الحالة');
            $table->enum('gender', array_column(Gender::cases(), 'value'))->nullable()->comment('الجنس');
            $table->foreignId('country_id')->nullable()->constrained('countries')->comment('الدولة');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
