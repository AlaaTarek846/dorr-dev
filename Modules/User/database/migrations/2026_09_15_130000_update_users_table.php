<?php

use App\Enums\UserStatus;
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
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone')->nullable()->after('email')->comment('رقم الهاتف');
            }

            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status')
                    ->default(UserStatus::Active->value)
                    ->after('password')
                    ->comment('active / inactive / blocked');
            }

            if (! Schema::hasColumn('users', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')
                    ->nullable()
                    ->after('email_verified_at')
                    ->comment('وقت التحقق من الهاتف');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'phone_verified_at')) {
                $table->dropColumn('phone_verified_at');
            }

            if (Schema::hasColumn('users', 'status')) {
                $table->dropColumn('status');
            }

            if (Schema::hasColumn('users', 'phone')) {
                $table->dropColumn('phone');
            }
        });
    }
};
