<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Financial PIN, separate from login. One per owner (not per wallet/country)
     * — see docs/wallet-structure.md §1.2. `owner_type` stores the alias
     * registered in WalletServiceProvider ('user'/'provider'), not a full
     * class name.
     */
    public function up(): void
    {
        Schema::create('wallet_pins', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type')->comment('alias المالك (user/provider)');
            $table->unsignedBigInteger('owner_id')->comment('معرف المالك');
            $table->string('pin_hash')->comment('Argon2id + server pepper، غير قابل لفك التشفير');
            $table->unsignedTinyInteger('failed_attempts')->default(0)->comment('المحاولات الفاشلة المتتالية');
            $table->timestamp('locked_until')->nullable()->comment('نهاية القفل المؤقت بعد محاولات فاشلة متكررة');
            $table->timestamp('changed_at')->nullable()->comment('آخر تغيير للـ PIN');
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_pins');
    }
};
