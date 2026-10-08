<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A quick reply's title and text become translations (one row per language), like every other
 * translated table. What already exists is kept as the Arabic version, the language agents wrote it in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_quick_reply_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_quick_reply_id')->constrained('support_quick_replies')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('title', 120);
            $table->text('body');
            $table->timestamps();

            $table->unique(['support_quick_reply_id', 'locale'], 'support_quick_reply_translations_unique');
        });

        DB::table('support_quick_replies')->orderBy('id')->each(function ($reply) {
            DB::table('support_quick_reply_translations')->insert([
                'support_quick_reply_id' => $reply->id,
                'locale' => 'ar',
                'title' => $reply->title,
                'body' => $reply->body,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        Schema::table('support_quick_replies', function (Blueprint $table) {
            $table->dropColumn(['title', 'body']);
        });
    }

    public function down(): void
    {
        Schema::table('support_quick_replies', function (Blueprint $table) {
            $table->string('title', 120)->default('')->after('shortcut');
            $table->text('body')->nullable()->after('title');
        });

        DB::table('support_quick_replies')->orderBy('id')->each(function ($reply) {
            $translation = DB::table('support_quick_reply_translations')->where('support_quick_reply_id', $reply->id)
                ->orderByRaw("locale = 'ar' desc")->first();

            DB::table('support_quick_replies')->where('id', $reply->id)->update([
                'title' => $translation->title ?? '',
                'body' => $translation->body ?? '',
            ]);
        });

        Schema::dropIfExists('support_quick_reply_translations');
    }
};
