<?php

namespace Modules\AI\Tests\Unit\FileProcessors;

use Modules\AI\Services\FileProcessors\JsonFileProcessor;
use Tests\TestCase;

/**
 * Extends the Laravel-booted Tests\TestCase (not plain PHPUnit) because
 * JsonFileProcessor::process() reads config('ai.files.json_max_*'),
 * which needs a booted container - same reasoning already applied to
 * ExcelFileProcessorTest in Phase 1.
 */
class JsonFileProcessorTest extends TestCase
{
    protected JsonFileProcessor $processor;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new JsonFileProcessor;
        $this->tempDir = sys_get_temp_dir().'/ai-json-processor-test-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir.'/*') ?: []);
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    public function test_supports_the_documented_mime_type(): void
    {
        $this->assertTrue($this->processor->supports('application/json'));
        $this->assertFalse($this->processor->supports('text/plain'));
    }

    public function test_preserves_structure_as_path_value_blocks(): void
    {
        $json = json_encode(['users' => [['name' => 'Ali', 'email' => 'ali@example.test']]]);
        $path = $this->tempDir.'/users.json';
        file_put_contents($path, $json);

        $result = $this->processor->process($path, 'application/json');

        $this->assertTrue($result->success);
        $paths = array_column($result->blocks, 'path');

        $this->assertContains('$.users[0].name', $paths);
        $this->assertContains('$.users[0].email', $paths);
    }

    public function test_malformed_json_fails_cleanly_not_a_crash(): void
    {
        $path = $this->tempDir.'/broken.json';
        file_put_contents($path, '{"users": [ this is not valid json');

        $result = $this->processor->process($path, 'application/json');

        $this->assertFalse($result->success);
        $this->assertSame('JSON_INVALID', $result->error);
    }

    public function test_an_oversized_structure_is_truncated_not_exhausted(): void
    {
        $big = [];

        for ($i = 0; $i < 50; $i++) {
            $big["key{$i}"] = "value{$i}";
        }

        $path = $this->tempDir.'/big.json';
        file_put_contents($path, json_encode($big));

        config(['ai.files.json_max_leaf_blocks' => 10]);

        $result = $this->processor->process($path, 'application/json');

        $this->assertTrue($result->success);
        $this->assertLessThanOrEqual(10, count($result->blocks));
        $this->assertContains('json_truncated_leaf_blocks', $result->warnings);
    }
}
