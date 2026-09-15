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
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->morphs('authenticatable');
            $table->string('provider')->comment('google / apple');
            $table->string('provider_id')->comment('المعرّف من مزود الخدمة');
            $table->string('email')->nullable()->comment('البريد من مزود الخدمة');
            $table->timestamps();

            $table->unique(
                ['authenticatable_type', 'authenticatable_id', 'provider'],
                'social_accounts_authenticatable_provider_unique',
            );
            $table->unique(
                ['provider', 'provider_id'],
                'social_accounts_provider_provider_id_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
