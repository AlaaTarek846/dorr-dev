<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL without `explicit_defaults_for_timestamp` (common on shared hosting) turns the first
 * NOT NULL `timestamp` of a table into `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`, and
 * refuses a table with a second one ("Invalid default value"). Those columns are now nullable in
 * their create migrations; this repairs the tables that were already created that way, so e.g. a
 * code's `expires_at` no longer jumps to "now" whenever its row is updated. MySQL / MariaDB only —
 * SQLite and fresh installs never had the problem.
 */
return new class extends Migration
{
    /** table => [column => comment|null] */
    private const COLUMNS = [
        'verification_codes' => ['expires_at' => 'وقت انتهاء الكود'],
        'user_phone_changes' => ['expires_at' => null],
        'user_phone_history' => ['changed_at' => null],
        'wallet_beneficiaries' => ['first_added_at' => null, 'last_used_at' => null],
        'wallet_trusted_devices' => ['trusted_at' => null, 'last_seen_at' => null],
        'chat_stories' => ['expires_at' => null],
        'chat_story_views' => ['viewed_at' => null],
        'chat_link_previews' => ['fetched_at' => null],
        'chat_scheduled_messages' => ['send_at' => null],
        'chat_message_reminders' => ['remind_at' => null],
        'chat_subscriptions' => ['starts_at' => null, 'ends_at' => null],
        'chat_calendar_items' => ['starts_at' => 'UTC instant; all-day: the date at 00:00 in `timezone`'],
    ];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
                foreach ($columns as $column => $comment) {
                    if (! Schema::hasColumn($table, $column)) {
                        continue;
                    }
                    $definition = $blueprint->timestamp($column)->nullable();
                    if ($comment !== null) {
                        $definition->comment($comment);
                    }
                    $definition->change();
                }
            });
        }
    }

    public function down(): void
    {
        // Nothing to undo: nullable columns without an auto-update are what the code expects.
    }
};
