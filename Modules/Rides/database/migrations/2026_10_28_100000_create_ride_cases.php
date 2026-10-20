<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Cases (docs/rides_tasks.md R-133..R-140, D-229..D-238): a complaint, a financial dispute or a "rider left
 * without paying" report. A case is its own record — it is never deleted, not with the ride, not when an
 * account closes or is blocked — and it does not move the ride's status or anyone's money by itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_cases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 12)->unique();
            $table->string('kind', 16)->comment('complaint / dispute / unpaid_report');
            $table->string('reporter_type', 8)->comment('user / driver');
            $table->unsignedBigInteger('reporter_id');
            $table->foreignId('ride_id')->nullable()->constrained('rides')->restrictOnDelete();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->string('category', 24);
            $table->string('priority', 8)->default('normal');
            $table->string('status', 20)->default('submitted');
            $table->text('summary');
            $table->bigInteger('disputed_minor')->nullable();
            $table->unsignedBigInteger('currency_id')->nullable();
            $table->json('evidence')->nullable()->comment('frozen when the case is opened');
            $table->json('decision')->nullable();
            $table->foreignId('decided_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('appealed_at')->nullable();
            $table->text('appeal_note')->nullable();
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->index(['reporter_type', 'reporter_id']);
            $table->index(['kind', 'status']);
            $table->index('ride_id');
        });

        Schema::create('ride_case_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('ride_cases')->restrictOnDelete();
            $table->string('author_type', 8)->comment('user / driver / admin');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->boolean('internal')->default(false)->comment('an admin note the reporter never sees');
            $table->text('body');
            $table->timestamp('created_at')->nullable();
            $table->index('case_id');
        });

        Schema::create('ride_case_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('ride_cases')->restrictOnDelete();
            $table->string('uploader_type', 8);
            $table->unsignedBigInteger('uploader_id')->nullable();
            $table->string('path');
            $table->string('name', 160);
            $table->string('mime', 80)->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->index('case_id');
        });

        Schema::create('ride_case_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('ride_cases')->restrictOnDelete();
            $table->string('type', 24);
            $table->string('actor_type', 8)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('case_id');
        });
    }

    public function down(): void
    {
        foreach (['ride_case_events', 'ride_case_files', 'ride_case_messages', 'ride_cases'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
