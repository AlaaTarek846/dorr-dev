<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Changing a phone number used to be a plain field on the general profile-update form — no OTP,
     * no PIN, no uniqueness check (docs/wallet-tasks.md §10.9, wallet policy bend 38). This gives it its
     * own guarded flow:
     *
     *  - `user_phone_changes`: the one pending change a user can have at a time — the new number and
     *    the code sent to confirm it belongs to them. Deleted once confirmed (or replaced by a fresh
     *    request).
     *  - `user_phone_history`: permanent, append-only record of every completed change — so old
     *    financial rows keep meaning ("this transfer went to +9665... at the time"), and so a transfer
     *    lookup can warn "this number changed recently" (wallet-tasks.md §10.9's beneficiary warning).
     */
    public function up(): void
    {
        Schema::create('user_phone_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('new_phone')->comment('الرقم الجديد — لسه مش مؤكّد');
            $table->string('code')->comment('رمز OTP على الرقم الجديد');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('user_phone_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('old_phone');
            $table->string('new_phone');
            $table->string('ip_address')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'changed_at']);
            // Looked up by the *new* number when a transfer recipient's phone changed recently.
            $table->index('new_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_phone_history');
        Schema::dropIfExists('user_phone_changes');
    }
};
