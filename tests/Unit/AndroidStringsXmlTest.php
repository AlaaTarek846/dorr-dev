<?php

namespace Tests\Unit;

use App\Support\Translations\AndroidStringsXml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AndroidStringsXmlTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function qualifiers(): array
    {
        return [
            'language' => ['fr', 'values-fr'],
            'region' => ['pt-BR', 'values-pt-rBR'],
            'script' => ['zh-Hans', 'values-b+zh+Hans'],
            'legacy hebrew' => ['he', 'values-iw'],
            'legacy indonesian' => ['id', 'values-in'],
        ];
    }

    #[DataProvider('qualifiers')]
    public function test_language_codes_map_to_resource_qualifiers(string $code, string $expected): void
    {
        $this->assertSame($expected, AndroidStringsXml::qualifier($code));
    }

    public function test_text_is_escaped_for_android_resources(): void
    {
        $this->assertSame("C\\'est \\\"ok\\\" &amp; &lt;b&gt;\\nfin", AndroidStringsXml::encode("C'est \"ok\" & <b>\nfin"));
        $this->assertSame('\\@home', AndroidStringsXml::encode('@home'));
        $this->assertSame('\\?why', AndroidStringsXml::encode('?why'));
        $this->assertSame('a\\\\b', AndroidStringsXml::encode('a\\b'));
    }

    public function test_escapes_round_trip(): void
    {
        foreach (["C'est \"ok\"\nfin", '@home', 'a\\b', "tab\tstop", '100% sûr'] as $text) {
            $encoded = htmlspecialchars_decode(AndroidStringsXml::encode($text), ENT_XML1 | ENT_NOQUOTES);
            $this->assertSame($text, AndroidStringsXml::decode($encoded));
        }
    }

    public function test_parse_and_build(): void
    {
        $entries = AndroidStringsXml::parse(<<<'XML'
            <resources>
                <string name="a">It\'s &amp; done</string>
                <string name="skip" translatable="false">x</string>
                <plurals name="p"><item quantity="one">%d x</item><item quantity="other">%d xs</item></plurals>
            </resources>
            XML);

        $this->assertSame(['a' => "It's & done", 'p' => ['one' => '%d x', 'other' => '%d xs']], $entries);

        $xml = AndroidStringsXml::build($entries, 'fr');
        $this->assertSame($entries, AndroidStringsXml::parse($xml));
    }

    public function test_doctype_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        AndroidStringsXml::parse('<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x "y">]><resources><string name="a">&x;</string></resources>');
    }

    public function test_plural_categories(): void
    {
        $this->assertSame(['one', 'many', 'other'], AndroidStringsXml::pluralCategories('fr'));
        $this->assertSame(['zero', 'one', 'two', 'few', 'many', 'other'], AndroidStringsXml::pluralCategories('ar'));
        $this->assertSame(['one', 'other'], AndroidStringsXml::pluralCategories('de'));
    }
}
