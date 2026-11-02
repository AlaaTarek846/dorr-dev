<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * FL-L07: a consultation is a listing of its own, like a ready-made service — a freelancer has several (each with its title, lengths,
 * prices and weekly hours), the staff look at each before clients see it, and a client finds them in their own list. The booking keeps
 * the listing it came from. A freelancer also keeps the time zone of their phone (a notice says the time on the reader's own clock).
 */
return new class extends Migration
{
    public function up(): void
    {
        // The foreign key needs an index of its own before the unique one goes.
        Schema::table('freelance_consultation_offers', fn (Blueprint $table) => $table->index('freelancer_id', 'fco_freelancer_idx'));
        Schema::table('freelance_consultation_offers', function (Blueprint $table) {
            $table->dropUnique(['freelancer_id']);
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->foreignId('country_id')->nullable()->after('freelancer_id')->constrained('countries');
            $table->string('status', 16)->default('draft')->after('about');
            $table->string('status_reason', 500)->nullable()->after('status');
            $table->timestamp('submitted_at')->nullable()->after('status_reason');
            $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('reviewed_at');
            $table->timestamp('published_at')->nullable()->after('reviewed_by');
            $table->index(['status', 'country_id']);
        });

        // What was set up before: kept, and live when it was switched on.
        DB::table('freelance_consultation_offers')->orderBy('id')->get()->each(function ($o) {
            $country = DB::table('freelancers')->where('id', $o->freelancer_id)->value('country_id');
            DB::table('freelance_consultation_offers')->where('id', $o->id)->update([
                'uuid' => (string) Str::uuid(), 'country_id' => $country, 'status' => $o->enabled ? 'published' : 'draft',
                'published_at' => $o->enabled ? now() : null, 'title' => $o->title ?: 'Consultation',
            ]);
        });
        Schema::table('freelance_consultation_offers', fn (Blueprint $table) => $table->dropColumn('enabled'));

        Schema::table('freelance_consultations', function (Blueprint $table) {
            $table->foreignId('offer_id')->nullable()->after('freelancer_id')->constrained('freelance_consultation_offers')->nullOnDelete();
        });
        DB::table('freelance_consultations')->orderBy('id')->get()->each(function ($c) {
            DB::table('freelance_consultations')->where('id', $c->id)->update(['offer_id' => DB::table('freelance_consultation_offers')->where('freelancer_id', $c->freelancer_id)->value('id')]);
        });

        Schema::table('freelancers', fn (Blueprint $table) => $table->string('timezone', 64)->nullable());
    }

    public function down(): void
    {
        Schema::table('freelancers', fn (Blueprint $table) => $table->dropColumn('timezone'));
        Schema::table('freelance_consultations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('offer_id');
        });
        Schema::table('freelance_consultation_offers', function (Blueprint $table) {
            $table->boolean('enabled')->default(false);
        });
        DB::table('freelance_consultation_offers')->update(['enabled' => DB::raw("status = 'published'")]);
        Schema::table('freelance_consultation_offers', function (Blueprint $table) {
            $table->dropIndex(['status', 'country_id']);
            $table->dropConstrainedForeignId('country_id');
            $table->dropColumn(['uuid', 'status', 'status_reason', 'submitted_at', 'reviewed_at', 'reviewed_by', 'published_at']);
        });
    }
};
