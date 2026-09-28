<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Enums\AiModelCategory;
use Modules\AI\Repositories\AiProviderRepository;
use Tests\TestCase;

/**
 * Dynamic Model Registry, section 20: `php artisan ai:normalize-models`
 * is the one-time backfill for the ~103 models an admin registered by
 * hand before category/model_family/snapshot/alias existed as columns.
 * It must compute those fields from each existing model_key and must
 * NEVER touch capabilities/is_active/is_default/temperature - fields an
 * admin may have already hand-corrected - and must NEVER call any
 * provider's API (no Http::fake() set up in this test at all - a real
 * network call here would fail the test outright).
 */
class NormalizeAiModelsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_backfills_category_and_family_without_touching_admin_set_fields(): void
    {
        $provider = app(AiProviderRepository::class)->updateByKey('openai', ['api_key' => 'sk-test-not-real']);

        $model = $provider->models()->create([
            'model_key' => 'gpt-4o',
            'display_name' => 'My Custom Label',
            'capabilities' => ['chat', 'vision'],
            'temperature' => 0.9,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $transcriber = $provider->models()->create([
            'model_key' => 'whisper-1',
            'capabilities' => [],
            'is_default' => false,
            'is_active' => false,
            'sort_order' => 2,
        ]);

        $this->artisan('ai:normalize-models')->assertSuccessful();

        $model->refresh();
        $this->assertSame(AiModelCategory::General->value, $model->category);
        $this->assertSame('gpt-4o', $model->model_family);
        // Untouched - exactly what the admin set by hand.
        $this->assertSame('My Custom Label', $model->display_name);
        $this->assertSame(['chat', 'vision'], $model->capabilities);
        $this->assertSame(0.9, (float) $model->temperature);
        $this->assertTrue($model->is_default);
        $this->assertTrue($model->is_active);

        $transcriber->refresh();
        $this->assertSame(AiModelCategory::SpeechToText->value, $transcriber->category);
        // Still untouched even though it disagrees with the category -
        // the admin's own capabilities/is_active choice is never overridden.
        $this->assertSame([], $transcriber->capabilities);
        $this->assertFalse($transcriber->is_active);
    }

    public function test_dry_run_reports_without_saving_anything(): void
    {
        $provider = app(AiProviderRepository::class)->updateByKey('openai', ['api_key' => 'sk-test-not-real']);

        $model = $provider->models()->create([
            'model_key' => 'gpt-4o',
            'capabilities' => ['chat'],
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->artisan('ai:normalize-models', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($model->refresh()->category);
    }
}
