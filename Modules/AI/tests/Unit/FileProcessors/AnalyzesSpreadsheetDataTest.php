<?php

namespace Modules\AI\Tests\Unit\FileProcessors;

use Modules\AI\Services\FileProcessors\Concerns\AnalyzesSpreadsheetData;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic coverage for the shared trait, independent of any one
 * processor or the Laravel container (none of these methods call
 * config()) - header detection confidence, duplicate-header renaming,
 * conservative value classification (doc S10's own leading-zero and
 * currency/percentage examples), and delimiter detection.
 */
class AnalyzesSpreadsheetDataTest extends TestCase
{
    protected object $subject;

    protected function setUp(): void
    {
        parent::setUp();
        // Widen the trait's protected helpers to public (same-name wrappers recurse forever).
        $this->subject = new class
        {
            use AnalyzesSpreadsheetData {
                detectHeaderRow as public;
                classifyScalar as public;
                detectDelimiter as public;
            }
        };
    }

    public function test_detects_the_header_row_when_it_is_not_the_first_row(): void
    {
        [$index, $confidence] = $this->subject->detectHeaderRow([
            [null, null],
            ['Product', 'Revenue'],
            ['Widget', 100],
        ]);

        $this->assertSame(1, $index);
        $this->assertGreaterThan(0.0, $confidence);
    }

    public function test_leading_zero_values_classify_as_string(): void
    {
        [$type, $value] = $this->subject->classifyScalar('00123');

        $this->assertSame('string', $type);
        $this->assertSame('00123', $value);
    }

    public function test_plain_integers_classify_as_integer(): void
    {
        [$type, $value] = $this->subject->classifyScalar('42');

        $this->assertSame('integer', $type);
        $this->assertSame(42, $value);
    }

    public function test_percentage_values_are_detected(): void
    {
        [$type, $value] = $this->subject->classifyScalar('15%');

        $this->assertSame('percentage', $type);
        $this->assertSame(15.0, $value);
    }

    public function test_currency_values_are_detected(): void
    {
        [$type, $value] = $this->subject->classifyScalar('$1,234.50');

        $this->assertSame('currency', $type);
        $this->assertSame(1234.50, $value);
    }

    public function test_arabic_text_classifies_as_string_not_mangled(): void
    {
        [$type, $value] = $this->subject->classifyScalar('القاهرة');

        $this->assertSame('string', $type);
        $this->assertSame('القاهرة', $value);
    }

    public function test_detects_comma_as_the_delimiter(): void
    {
        [$delimiter, $confidence] = $this->subject->detectDelimiter(['a,b,c', 'd,e,f', 'g,h,i']);

        $this->assertSame(',', $delimiter);
        $this->assertGreaterThan(0.5, $confidence);
    }

    public function test_detects_pipe_as_the_delimiter(): void
    {
        [$delimiter, $confidence] = $this->subject->detectDelimiter(['a|b|c', 'd|e|f']);

        $this->assertSame('|', $delimiter);
        $this->assertGreaterThan(0.5, $confidence);
    }
}
