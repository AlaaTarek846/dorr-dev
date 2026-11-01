<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ready-made services (FL-L04): a freelancer's fixed-price service in up to three levels (basic / standard / premium) with paid extras,
 * and the orders clients place on them. An order keeps its own copy of what was bought (a later edit of the service never changes it) and
 * runs as a private project with an accepted offer and an agreement — the same agreement, parts, funding and release as any project, so
 * there is no new money path. The project remembers the service it came from (`service_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_services', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries');
            $table->foreignId('freelance_specialty_id')->nullable()->constrained('freelance_specialties')->nullOnDelete();
            $table->string('title', 120);
            $table->text('description');
            // What the freelancer needs from the client to start (asked when ordering).
            $table->string('requirements', 1000)->nullable();
            $table->string('currency_code', 8);
            $table->string('status', 20)->default('draft');
            $table->string('status_reason', 500)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['country_id', 'status']);
            $table->index(['freelancer_id', 'status']);
        });

        Schema::create('freelance_service_tiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_id')->constrained('freelance_services')->cascadeOnDelete();
            $table->string('level', 12)->comment('basic | standard | premium');
            $table->string('name', 60);
            $table->string('description', 500)->nullable();
            $table->unsignedBigInteger('price_minor')->comment('minor units of the service currency');
            $table->unsignedSmallInteger('delivery_days');
            $table->unsignedTinyInteger('revisions')->default(0);
            $table->json('features')->nullable();
            $table->timestamps();
            $table->unique(['service_id', 'level']);
        });

        Schema::create('freelance_service_addons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_id')->constrained('freelance_services')->cascadeOnDelete();
            $table->string('title', 80);
            $table->unsignedBigInteger('price_minor');
            $table->unsignedSmallInteger('extra_days')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('freelance_projects', function (Blueprint $table): void {
            $table->foreignId('service_id')->nullable()->after('freelance_specialty_id')->constrained('freelance_services')->nullOnDelete();
        });

        Schema::create('freelance_service_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('service_id')->constrained('freelance_services');
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->unique()->constrained('freelance_projects')->nullOnDelete();
            // The app's id for this tap: the same one twice is the same order.
            $table->string('request_id', 64)->nullable();
            $table->string('level', 12);
            // What was bought, as it was: the service title, the level's name and features, the extras chosen.
            $table->json('snapshot');
            $table->unsignedBigInteger('price_minor');
            $table->string('currency_code', 8);
            $table->unsignedSmallInteger('delivery_days');
            $table->unsignedTinyInteger('revisions')->default(0);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'request_id']);
            $table->index(['freelancer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_service_orders');
        Schema::table('freelance_projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('service_id');
        });
        Schema::dropIfExists('freelance_service_addons');
        Schema::dropIfExists('freelance_service_tiers');
        Schema::dropIfExists('freelance_services');
    }
};
