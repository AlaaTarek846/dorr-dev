<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression guard for a real, previously-undiscovered bug found
 * 2026-09-24 while manually testing the chat end to end: AiProviderHealth
 * had no explicit $table, so Eloquent's default English pluralization
 * guessed 'ai_provider_healths' - a table that has never existed -
 * against a migration that actually creates 'ai_provider_health'
 * (singular). That crashed every single chat message with a raw 500 the
 * moment AiCircuitBreaker started reading/writing it for real. The same
 * root cause was then found, live, in AiUsage (written on every
 * successful chat reply) plus two more dormant cases
 * (AiFileProcessing, AiProjectKnowledge).
 *
 * Rather than trusting a one-off audit not to miss a sixth case, this
 * scans every concrete Eloquent model in Modules/AI/app/Models and
 * proves its resolved table (getTable(), the same resolution Eloquent
 * uses at runtime) actually exists in the schema - so this class of bug
 * cannot recur silently again for ANY model in this module.
 */
class AiModelTableNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_ai_model_resolves_to_a_table_that_actually_exists(): void
    {
        $modelsDir = base_path('Modules/AI/app/Models');
        $this->assertDirectoryExists($modelsDir, 'expected Modules/AI/app/Models to exist');

        $files = glob($modelsDir.'/*.php');
        $this->assertNotEmpty($files, 'expected to find AI model class files');

        $checked = [];

        foreach ($files as $file) {
            $class = 'Modules\\AI\\Models\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            /** @var Model $instance */
            $instance = $reflection->newInstance();
            $table = $instance->getTable();

            $this->assertTrue(
                Schema::hasTable($table),
                "{$class}::getTable() resolves to '{$table}', which does not exist as a real table - ".
                'this is exactly the class of bug found in AiProviderHealth/AiUsage on 2026-09-24 '.
                '(a migration using a singular/irregular table name with no matching $table override).',
            );

            $checked[] = $class;
        }

        // A model-discovery bug in this test itself (e.g. the glob or
        // namespace resolution silently matching nothing) would make
        // every assertion above vacuously true - this floor makes that
        // failure mode loud instead of silent.
        $this->assertGreaterThan(40, count($checked), 'expected to have actually checked a substantial number of AI models, not silently skipped them all');
    }
}
