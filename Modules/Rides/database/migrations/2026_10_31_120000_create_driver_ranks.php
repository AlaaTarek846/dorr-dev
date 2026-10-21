<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Captain ranks and performance (docs/rides_tasks.md D-5, D-6, D-239..D-259): «new» through the probation,
 * then bronze / silver / gold / diamond by the thresholds the admin sets (never hard-coded in the apps). Each
 * captain's numbers over the window, a warning with a grace before a lower rank, and the notes / warnings
 * the team writes — each open to one appeal where the policy allows it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_ranks', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 24)->unique();
            $table->json('name')->comment('{ar, en}');
            $table->json('benefits')->nullable()->comment('{ar: [...], en: [...]}');
            $table->string('color', 9)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('higher = better');
            $table->boolean('probation')->default(false)->comment('the rank of the probation period');
            $table->unsignedInteger('min_trips')->default(0);
            $table->unsignedSmallInteger('min_rating')->default(0)->comment('×100: 450 = 4.50');
            $table->unsignedSmallInteger('min_cleanliness')->default(0)->comment('×100');
            $table->unsignedSmallInteger('min_behavior')->default(0)->comment('×100');
            $table->unsignedSmallInteger('min_commitment_bp')->default(0)->comment('on-time arrivals');
            $table->unsignedSmallInteger('min_completion_bp')->default(0);
            $table->unsignedSmallInteger('max_cancel_bp')->default(10000);
            $table->unsignedSmallInteger('max_warnings')->default(99);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('driver_rank_states', function (Blueprint $table) {
            $table->foreignId('driver_id')->primary()->constrained('drivers')->cascadeOnDelete();
            $table->foreignId('rank_id')->nullable()->constrained('driver_ranks')->nullOnDelete();
            $table->json('metrics')->nullable()->comment('the last numbers worked out');
            $table->dateTime('computed_at')->nullable();
            $table->foreignId('falling_to_id')->nullable()->constrained('driver_ranks')->nullOnDelete()->comment('a lower rank pending its grace');
            $table->dateTime('downgrade_due_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('driver_performance_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->string('kind', 12)->comment('note / warning / praise / rank_change');
            $table->string('title', 160);
            $table->text('body')->nullable();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('ride_id')->nullable()->constrained('rides')->nullOnDelete();
            $table->string('appeal_status', 10)->default('none')->comment('none / pending / accepted / rejected');
            $table->text('appeal_text')->nullable();
            $table->text('appeal_answer')->nullable();
            $table->dateTime('appealed_at')->nullable();
            $table->dateTime('answered_at')->nullable();
            $table->timestamps();
            $table->index(['driver_id', 'id']);
            $table->index('appeal_status');
        });

        // The five ranks of the spec (D-239..D-243) — the admin changes the thresholds and the words.
        $rank = fn (string $slug, string $ar, string $en, int $sort, array $t) => array_merge([
            'slug' => $slug, 'name' => json_encode(['ar' => $ar, 'en' => $en], JSON_UNESCAPED_UNICODE), 'sort_order' => $sort,
            'probation' => false, 'min_trips' => 0, 'min_rating' => 0, 'min_cleanliness' => 0, 'min_behavior' => 0, 'min_commitment_bp' => 0,
            'min_completion_bp' => 0, 'max_cancel_bp' => 10000, 'max_warnings' => 99, 'status' => true, 'created_at' => now(), 'updated_at' => now(),
        ], $t);
        DB::table('driver_ranks')->insert([
            $rank('new', 'جديد', 'New', 0, ['probation' => true, 'color' => '#64748B']),
            $rank('bronze', 'برونزي', 'Bronze', 1, ['color' => '#B45309']),
            $rank('silver', 'فضي', 'Silver', 2, ['color' => '#94A3B8', 'min_trips' => 50, 'min_rating' => 450, 'min_completion_bp' => 8500, 'max_cancel_bp' => 1500]),
            $rank('gold', 'ذهبي', 'Gold', 3, ['color' => '#D4A017', 'min_trips' => 150, 'min_rating' => 470, 'min_cleanliness' => 450, 'min_commitment_bp' => 8500, 'min_completion_bp' => 9000, 'max_cancel_bp' => 1000, 'max_warnings' => 1]),
            $rank('diamond', 'ماسي', 'Diamond', 4, ['color' => '#0EA5E9', 'min_trips' => 400, 'min_rating' => 485, 'min_cleanliness' => 470, 'min_behavior' => 470, 'min_commitment_bp' => 9200, 'min_completion_bp' => 9500, 'max_cancel_bp' => 500, 'max_warnings' => 0]),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_performance_notes');
        Schema::dropIfExists('driver_rank_states');
        Schema::dropIfExists('driver_ranks');
    }
};
