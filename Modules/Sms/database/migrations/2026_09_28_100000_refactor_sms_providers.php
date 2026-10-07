<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_providers', function (Blueprint $table) {
            $table->unsignedTinyInteger('priority')->default(1)->after('key');
        });

        Schema::create('sms_provider_countries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sms_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['sms_provider_id', 'country_id']);
        });

        if (Schema::hasTable('sms_accounts')) {
            foreach (\DB::table('sms_providers')->get() as $provider) {
                $account = \DB::table('sms_accounts')
                    ->where('provider_id', $provider->id)
                    ->whereNotNull('configuration')
                    ->orderByDesc('id')
                    ->first();

                if ($account && is_null($provider->configuration)) {
                    \DB::table('sms_providers')
                        ->where('id', $provider->id)
                        ->update(['configuration' => $account->configuration]);
                }
            }

            Schema::dropIfExists('sms_accounts');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_provider_countries');

        Schema::table('sms_providers', function (Blueprint $table) {
            $table->dropColumn('priority');
        });

        if (! Schema::hasTable('sms_accounts')) {
            Schema::create('sms_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->foreignId('provider_id')->constrained('sms_providers')->cascadeOnDelete();
                $table->string('sender')->nullable();
                $table->string('sender_code')->nullable();
                $table->string('sender_type')->nullable();
                $table->text('configuration')->nullable();
                $table->string('purpose')->nullable();
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_tested_at')->nullable();
                $table->string('test_status')->default('never_tested');
                $table->string('test_error')->nullable();
                $table->json('settings')->nullable();
                $table->timestamps();

                $table->index('is_default');
                $table->index('test_status');
            });
        }
    }
};
