<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Superseded before ever being migrated: the admin asked not to add a
 * separate "classifier model" field at all - AiModelCapabilityClassifier
 * now simply uses the provider's own existing `model` field instead (see
 * that class), so this migration is intentionally left as a no-op rather
 * than deleted, in case it was already migrated in some environment.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Intentionally empty - see class docblock above.
    }

    public function down(): void
    {
        // Intentionally empty - see class docblock above.
    }
};
