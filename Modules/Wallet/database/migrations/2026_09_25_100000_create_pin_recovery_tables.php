<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How an owner gets back into their wallet when they forget the wallet PIN.
     *
     *  - `wallet_recovery_methods`: the one method they chose when creating the PIN (password, birth
     *    date, ID photo, passport photo, or e-mail). Secrets are stored *hashed* (Argon2id + the same
     *    server pepper as the PIN), documents on the private disk, never as URLs.
     *  - `pin_recovery_requests`: a "I forgot my PIN" request made with a document. A person reviews
     *    it in the dashboard against the photo uploaded at setup; approving resets the PIN to 0000.
     */
    public function up(): void
    {
        Schema::create('wallet_recovery_methods', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type')->comment('alias المالك (user/provider)');
            $table->unsignedBigInteger('owner_id');
            $table->string('method', 20)->comment('password | birth_date | id_photo | passport_photo | email');
            $table->text('secret_hash')->nullable()->comment('Argon2id لكلمة المرور/تاريخ الميلاد — غير قابل للاسترجاع');
            $table->string('email')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id']);
        });

        Schema::create('pin_recovery_requests', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->string('method', 20)->comment('id_photo | passport_photo');
            $table->string('status', 12)->default('pending')->comment('pending | approved | rejected');
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pin_recovery_requests');
        Schema::dropIfExists('wallet_recovery_methods');
    }
};
