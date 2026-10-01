<?php

namespace Tests\Feature;

use App\Models\Flag;
use App\Models\Language;
use App\Models\TranslationFile;
use App\Support\LocaleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use ZipArchive;

/**
 * Dashboard translation management: export → validate → import (draft) → publish, then
 * Laravel loads the published JSON (falling back to en), Vue gets it from the API and
 * Android gets a generated values-{qualifier} ZIP.
 */
class TranslationManagementTest extends TestCase
{
    use RefreshDatabase;

    private Language $french;

    private string $androidRes;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $flag = Flag::create(['code' => 'fr']);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        Language::create(['code' => 'ar', 'direction' => 'rtl', 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        $this->french = Language::create(['code' => 'fr', 'direction' => 'ltr', 'stores_translation' => false, 'status' => true, 'flag_id' => $flag->id]);

        $this->androidRes = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dorr-android-res-'.uniqid();
        File::ensureDirectoryExists($this->androidRes.'/values');
        File::put($this->androidRes.'/values/strings.xml', <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <resources>
                <string name="app_name">Dorr</string>
                <string name="greeting">Hello %1$s, it\'s day %2$d</string>
                <string name="terms">Terms &amp; conditions</string>
                <plurals name="items">
                    <item quantity="one">%d item</item>
                    <item quantity="other">%d items</item>
                </plurals>
            </resources>
            XML);
        File::put($this->androidRes.'/values/chat_strings.xml', '<resources><string name="ch_title">Chats</string></resources>');
        File::put($this->androidRes.'/values/wallet_strings.xml', '<resources><string name="wa_title">Wallet</string></resources>');
        config(['translations.android_res_path' => $this->androidRes]);

        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach (['languages.view', 'languages.update'] as $permission) {
            Permission::findOrCreate($permission, 'admin_api');
        }
        $admin->givePermissionTo(['languages.view', 'languages.update']);
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->androidRes);

        parent::tearDown();
    }

    public function test_overview_lists_every_platform_group_of_a_new_language(): void
    {
        $response = $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.language.code', 'fr')
            ->assertJsonPath('data.language.is_source', false)
            ->assertJsonPath('data.platforms.0.platform', 'backend')
            ->assertJsonPath('data.platforms.1.groups.0.group', 'messages')
            ->assertJsonPath('data.platforms.2.groups.0.group', 'strings');

        $groups = collect($response->json('data.platforms'))->flatMap(fn ($platform) => array_column($platform['groups'], 'group'));
        $this->assertSame(['api', 'validation', 'notifications', 'chat', 'wallet', 'sms', 'ai', 'provider', 'messages', 'strings', 'chat_strings', 'wallet_strings'], $groups->all());

        $api = $response->json('data.platforms.0.groups.0');
        $this->assertSame('not_started', $api['status']);
        $this->assertGreaterThan(0, $api['total_keys']);
        $this->assertSame($api['total_keys'], $api['missing_keys']);
        $this->assertSame(4, $response->json('data.platforms.2.groups.0.total_keys'));
    }

    public function test_arabic_and_english_are_not_managed_from_the_dashboard(): void
    {
        $english = Language::query()->where('code', 'en')->firstOrFail();

        $this->getJson($this->url('', $english))->assertOk()->assertJsonPath('data.language.is_source', true)->assertJsonCount(0, 'data.platforms');
        $this->post($this->url('/backend/api/import', $english), ['file' => $this->jsonFile(['not_found' => 'x'])], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error_code', 'translations_source_locale');
    }

    public function test_import_saves_a_draft_and_reports_missing_keys_without_publishing(): void
    {
        $response = $this->upload('backend/api/import', ['not_found' => 'Page introuvable.'])->assertOk()
            ->assertJsonPath('data.report.translated_keys', 1)
            ->assertJsonPath('data.file.status', 'draft')
            ->assertJsonPath('data.file.is_published', false);

        $this->assertSame($response->json('data.report.total_keys') - 1, $response->json('data.report.missing_keys'));
        $this->assertContains('unauthorized', $response->json('data.report.missing'));

        $file = TranslationFile::query()->sole();
        $this->assertSame('draft', $file->status->value);
        $this->assertNotNull($file->draftMedia());
        $this->assertNull($file->publishedMedia());
        Storage::disk('local')->assertExists($file->draftMedia()->getPathRelativeToRoot());
        $this->assertSame(['not_found' => 'Page introuvable.'], $file->contents(TranslationFile::DRAFT_COLLECTION));

        $this->assertNotContains('fr', LocaleResolver::supported());
    }

    public function test_validate_is_a_dry_run(): void
    {
        $this->upload('backend/api/validate', ['not_found' => 'Page introuvable.'])->assertOk()->assertJsonPath('data.valid', true);

        $this->assertDatabaseCount('translation_files', 0);
    }

    public function test_extra_keys_are_rejected(): void
    {
        $this->upload('backend/api/import', ['not_found' => 'Page introuvable.', 'made_up_key' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'translations_import_invalid')
            ->assertJsonPath('data.report.extra', ['made_up_key'])
            ->assertJsonPath('data.report.errors.0.code', 'extra_keys');

        $this->assertDatabaseCount('translation_files', 0);
    }

    public function test_changed_placeholders_are_rejected(): void
    {
        $this->upload('backend/api/import', ['phone_invalid_length' => 'Le numéro doit contenir :count chiffres.'])
            ->assertStatus(422)
            ->assertJsonPath('data.report.placeholder_errors.0.key', 'phone_invalid_length')
            ->assertJsonPath('data.report.placeholder_errors.0.expected', [':length']);

        $this->upload('vue/messages/import', ['languages' => ['showing_entries' => 'Affichage de {start} à {to} sur {total}']])
            ->assertStatus(422)
            ->assertJsonPath('data.report.errors.0.code', 'placeholders');

        $this->upload('android/strings/import', ['greeting' => 'Bonjour %s'])
            ->assertStatus(422)
            ->assertJsonPath('data.report.errors.0.code', 'placeholders');

        // Same placeholders in a different order are fine.
        $this->upload('backend/api/validate', ['phone_invalid_length' => ':Length chiffres requis.'])->assertOk();
        $this->upload('android/strings/validate', ['greeting' => 'Jour %2$d, bonjour %1$s'])->assertOk();
    }

    public function test_php_code_bad_files_and_non_text_values_are_rejected(): void
    {
        $php = UploadedFile::fake()->createWithContent('fr.php', '<?php return ["not_found" => "x"];');
        $this->post($this->url('/backend/api/import'), ['file' => $php], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $disguised = UploadedFile::fake()->createWithContent('fr.json', '<?php system("id"); ?>');
        $this->post($this->url('/backend/api/import'), ['file' => $disguised], ['Accept' => 'application/json'])->assertStatus(422);

        $big = UploadedFile::fake()->create('fr.json', 3000, 'application/json');
        $this->post($this->url('/backend/api/import'), ['file' => $big], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $broken = UploadedFile::fake()->createWithContent('fr.json', '{"not_found": ');
        $this->post($this->url('/backend/api/import'), ['file' => $broken], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('data.report.errors.0.code', 'invalid_json');

        $this->upload('backend/api/import', ['not_found' => 5])->assertStatus(422)->assertJsonPath('data.report.errors.0.code', 'invalid_value');
        $this->upload('backend/api/import', ['not_found' => '<script>alert(1)</script>'])->assertStatus(422)->assertJsonPath('data.report.errors.0.code', 'unsafe_value');

        $this->assertDatabaseCount('translation_files', 0);
    }

    public function test_unknown_groups_are_not_found(): void
    {
        $this->upload('backend/secrets/import', ['x' => 'y'])->assertStatus(404)->assertJsonPath('error_code', 'translations_invalid_target');
        $this->getJson($this->url('/backend/..%2F..%2Fenv/export'))->assertNotFound();
    }

    public function test_publish_makes_backend_translations_live_and_falls_back_to_english(): void
    {
        $this->upload('backend/api/import', ['not_found' => 'Page introuvable.'])->assertOk();
        $this->postJson($this->url('/backend/api/publish'))->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.has_draft', false);

        $file = TranslationFile::query()->sole();
        $this->assertNull($file->draftMedia());
        $this->assertNotNull($file->publishedMedia());

        $this->assertContains('fr', LocaleResolver::supported());
        $this->assertSame('Page introuvable.', __('api.not_found', [], 'fr'));
        $this->assertSame('You do not have permission to access this data.', __('api.unauthorized', [], 'fr'));
        $this->assertSame('الصفحة أو الرابط غير موجود.', __('api.not_found', [], 'ar'));

        $this->getJson('/api/general/v1/no-such-endpoint', ['X-Locale' => 'fr'])
            ->assertNotFound()->assertJsonPath('message', 'Page introuvable.');
        $this->getJson('/api/general/v1/no-such-endpoint', ['X-Locale' => 'fr-CA'])
            ->assertJsonPath('message', 'Page introuvable.');
    }

    public function test_a_disabled_language_is_no_longer_served(): void
    {
        $this->upload('backend/api/import', ['not_found' => 'Page introuvable.'])->assertOk();
        $this->postJson($this->url('/backend/api/publish'))->assertOk();
        $this->assertContains('fr', LocaleResolver::supported());

        $this->french->update(['status' => false]);

        $this->assertNotContains('fr', LocaleResolver::supported());
    }

    public function test_publish_needs_a_draft_and_a_draft_can_be_discarded(): void
    {
        $this->postJson($this->url('/backend/api/publish'))->assertStatus(422)->assertJsonPath('error_code', 'translations_no_draft');

        $this->upload('backend/api/import', ['not_found' => 'Page introuvable.'])->assertOk();
        $this->deleteJson($this->url('/backend/api/draft'))->assertOk()->assertJsonPath('data.status', 'not_started');
        $this->assertDatabaseCount('translation_files', 0);

        $this->upload('backend/api/import', ['not_found' => 'Page introuvable.'])->assertOk();
        $this->postJson($this->url('/backend/api/publish'))->assertOk();
        $this->upload('backend/api/import', ['not_found' => 'Introuvable.'])->assertOk()->assertJsonPath('data.file.status', 'draft');
        $this->deleteJson($this->url('/backend/api/draft'))->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertSame('Page introuvable.', __('api.not_found', [], 'fr'));
    }

    public function test_vue_messages_are_served_from_the_api_once_published(): void
    {
        $this->getJson('/api/general/v1/translations/fr/vue')->assertNotFound();
        $codes = collect($this->getJson('/api/general/v1/translations/languages')->assertOk()->json('data'))->pluck('code');
        $this->assertEqualsCanonicalizing(['en', 'ar'], $codes->all());

        $this->upload('vue/messages/import', [
            'dashboard' => 'Tableau de bord',
            'languages' => ['showing_entries' => 'Affichage de {from} à {to} sur {total}'],
        ])->assertOk();
        $this->postJson($this->url('/vue/messages/publish'))->assertOk();

        $this->getJson('/api/general/v1/translations/fr/vue')->assertOk()
            ->assertHeader('ETag')
            ->assertJsonPath('data.locale', 'fr')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.messages.dashboard', 'Tableau de bord')
            ->assertJsonPath('data.messages.languages.showing_entries', 'Affichage de {from} à {to} sur {total}');

        $french = collect($this->getJson('/api/general/v1/translations/languages')->json('data'))->firstWhere('code', 'fr');
        $this->assertSame('ltr', $french['direction']);
        $this->assertSame(1, $french['version']);
        $this->assertFalse($french['is_source']);

        $this->getJson('/api/general/v1/translations/en/vue')->assertNotFound();
    }

    public function test_android_strings_merge_every_published_group(): void
    {
        $this->getJson('/api/general/v1/translations/fr/android')->assertNotFound()
            ->assertJsonPath('error_code', 'translations_not_published');

        $this->publishAndroid('strings', [
            'app_name' => 'Dorr FR',
            'greeting' => "Bonjour %1\$s, c'est le jour %2\$d",
            'items' => ['one' => '%d élément', 'other' => '%d éléments'],
        ]);
        $this->publishAndroid('chat_strings', ['ch_title' => 'Discussions']);

        $version = sha1('strings:1|chat_strings:1|wallet_strings:0');

        $response = $this->getJson('/api/general/v1/translations/fr/android')->assertOk()
            ->assertHeader('ETag', "\"android-fr-{$version}\"")
            ->assertJsonPath('data.code', 'fr')
            ->assertJsonPath('data.direction', 'ltr')
            ->assertJsonPath('data.version', $version)
            ->assertJsonPath('data.strings.app_name', 'Dorr FR')
            ->assertJsonPath('data.strings.greeting', "Bonjour %1\$s, c'est le jour %2\$d")
            ->assertJsonPath('data.strings.items.other', '%d éléments')
            ->assertJsonPath('data.strings.ch_title', 'Discussions');

        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $this->assertArrayNotHasKey('wa_title', $response->json('data.strings'));
    }

    public function test_the_android_version_changes_when_a_group_is_republished(): void
    {
        $this->publishAndroid('strings', ['app_name' => 'Dorr FR']);
        $first = $this->getJson('/api/general/v1/translations/fr/android')->json('data.version');

        $this->publishAndroid('wallet_strings', ['wa_title' => 'Portefeuille']);
        $second = $this->getJson('/api/general/v1/translations/fr/android')->json('data.version');

        $this->publishAndroid('strings', ['app_name' => 'Dorr Français']);
        $third = $this->getJson('/api/general/v1/translations/fr/android')
            ->assertJsonPath('data.strings.app_name', 'Dorr Français')
            ->json('data.version');

        $this->assertSame(sha1('strings:1|chat_strings:0|wallet_strings:0'), $first);
        $this->assertSame(sha1('strings:1|chat_strings:0|wallet_strings:1'), $second);
        $this->assertSame(sha1('strings:2|chat_strings:0|wallet_strings:1'), $third);
    }

    public function test_android_strings_need_the_strings_group_and_an_active_language(): void
    {
        $this->publishAndroid('chat_strings', ['ch_title' => 'Discussions']);
        $this->getJson('/api/general/v1/translations/fr/android')->assertNotFound();

        $this->publishAndroid('strings', ['app_name' => 'Dorr FR']);
        $this->getJson('/api/general/v1/translations/fr/android')->assertOk();

        $this->french->update(['status' => false]);
        $this->getJson('/api/general/v1/translations/fr/android')->assertNotFound();

        $this->getJson('/api/general/v1/translations/en/android')->assertNotFound();
        $this->getJson('/api/general/v1/translations/ar/android')->assertNotFound();
    }

    public function test_android_strings_answer_304_when_the_etag_matches(): void
    {
        $this->publishAndroid('strings', ['app_name' => 'Dorr FR']);

        $etag = $this->getJson('/api/general/v1/translations/fr/android')->assertOk()->headers->get('ETag');

        $this->getJson('/api/general/v1/translations/fr/android', ['If-None-Match' => $etag])
            ->assertStatus(304)
            ->assertHeader('ETag', $etag);

        $this->publishAndroid('strings', ['app_name' => 'Dorr Français']);

        $this->getJson('/api/general/v1/translations/fr/android', ['If-None-Match' => $etag])
            ->assertOk()
            ->assertJsonPath('data.strings.app_name', 'Dorr Français');
    }

    public function test_the_android_language_list_adds_languages_with_published_strings(): void
    {
        $listed = fn () => collect($this->getJson('/api/general/v1/translations/languages?platform=android')->assertOk()->json('data'));

        $this->assertEqualsCanonicalizing(['en', 'ar'], $listed()->pluck('code')->all());
        $this->assertNull($listed()->firstWhere('code', 'ar')['android_version']);

        $this->publishAndroid('chat_strings', ['ch_title' => 'Discussions']);
        $this->assertNotContains('fr', $listed()->pluck('code')->all());

        $this->publishAndroid('strings', ['app_name' => 'Dorr FR']);
        $french = $listed()->firstWhere('code', 'fr');

        $this->assertSame(sha1('strings:1|chat_strings:1|wallet_strings:0'), $french['android_version']);
        $this->assertSame('ltr', $french['direction']);
        $this->assertSame(['id', 'code', 'name', 'direction', 'flag', 'android_version'], array_keys($french));
        $this->assertSame($french['android_version'], $this->getJson('/api/general/v1/translations/fr/android')->json('data.version'));

        $this->french->update(['status' => false]);
        $this->assertNotContains('fr', $listed()->pluck('code')->all());
    }

    public function test_the_interface_language_list_without_a_platform_is_unchanged(): void
    {
        $this->publishAndroid('strings', ['app_name' => 'Dorr FR']);

        $languages = collect($this->getJson('/api/general/v1/translations/languages')->assertOk()->json('data'));

        $this->assertEqualsCanonicalizing(['en', 'ar'], $languages->pluck('code')->all());
        $this->assertSame(
            ['id', 'code', 'name', 'direction', 'is_default_dashboard', 'is_source', 'version', 'flag'],
            array_keys($languages->first()),
        );
    }

    public function test_csv_export_and_import_round_trip(): void
    {
        $csv = $this->get($this->url('/backend/ai/export?format=csv'))->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="dorr-fr-backend-ai.csv"')
            ->getContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim(substr($csv, 3)))));
        $this->assertSame(['key', 'en', 'ar', 'translation'], $rows[0]);
        $first = $rows[1];
        $this->assertNotSame('', $first[1]);

        $translated = "key,en,ar,translation\n".$this->csvLine([$first[0], $first[1], $first[2], 'FR '.$first[1]]);
        $upload = UploadedFile::fake()->createWithContent('fr.csv', "\xEF\xBB\xBF".$translated);
        $this->post($this->url('/backend/ai/import'), ['file' => $upload], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.report.translated_keys', 1);

        $missing = $this->get($this->url('/backend/ai/export?format=json&mode=missing'))->assertOk()->getContent();
        $this->assertArrayNotHasKey($first[0], json_decode($missing, true));

        $all = json_decode($this->get($this->url('/backend/ai/export?format=json'))->getContent(), true);
        $this->assertSame('FR '.$first[1], $all[$first[0]]);
    }

    public function test_json_export_prefills_untranslated_keys_with_the_english_text(): void
    {
        $this->upload('android/strings/import', ['app_name' => 'Dorr FR'])->assertOk();

        $json = json_decode($this->get($this->url('/android/strings/export?format=json'))->assertOk()->getContent(), true);

        $this->assertSame('Dorr FR', $json['app_name']);
        $this->assertSame('Hello %1$s, it\'s day %2$d', $json['greeting']);
        $this->assertSame(['one' => '%d item', 'many' => '%d items', 'other' => '%d items'], $json['items']);

        $missing = json_decode($this->get($this->url('/android/strings/export?format=json&mode=missing'))->getContent(), true);
        $this->assertArrayNotHasKey('app_name', $missing);
        $this->assertSame('Terms & conditions', $missing['terms']);
    }

    public function test_android_json_is_exported_as_values_xml_in_a_zip(): void
    {
        $this->upload('android/strings/import', ['items' => ['one' => '%d élément']])
            ->assertStatus(422)->assertJsonPath('data.report.errors.0.code', 'plural_other');

        $this->upload('android/strings/import', [
            'greeting' => "Bonjour %1\$s, c'est le jour %2\$d",
            'terms' => 'Conditions & <règles>',
            'items' => ['one' => '%d élément', 'many' => "%d d'éléments", 'other' => '%d éléments'],
        ])->assertOk()->assertJsonPath('data.report.missing', ['app_name']);

        $this->get($this->url('/android/export'))->assertStatus(422)->assertJsonPath('error_code', 'translations_nothing_to_export');

        $draftZip = $this->get($this->url('/android/export?source=draft'))->assertOk();
        $this->assertSame('attachment; filename=dorr-android-fr.zip', $draftZip->headers->get('Content-Disposition'));

        $this->postJson($this->url('/android/strings/publish'))->assertOk();
        $response = $this->get($this->url('/android/export'))->assertOk();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $xml = $zip->getFromName('values-fr/strings.xml');
        $this->assertFalse($zip->getFromName('values-fr/chat_strings.xml'));
        $zip->close();

        $this->assertStringContainsString('<string name="greeting">Bonjour %1$s, c\\\'est le jour %2$d</string>', $xml);
        $this->assertStringContainsString('<string name="terms">Conditions &amp; &lt;règles&gt;</string>', $xml);
        $this->assertStringContainsString('<item quantity="many">%d d\\\'éléments</item>', $xml);
        $this->assertStringNotContainsString('app_name', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    /**
     * @param  array<mixed>  $data
     */
    private function publishAndroid(string $group, array $data): void
    {
        $this->upload("android/{$group}/import", $data)->assertOk();
        $this->postJson($this->url("/android/{$group}/publish"))->assertOk();
    }

    private function url(string $suffix = '', ?Language $language = null): string
    {
        return '/api/admin/v1/languages/'.($language ?? $this->french)->id.'/translations'.$suffix;
    }

    /**
     * @param  array<mixed>  $data
     */
    private function upload(string $path, array $data)
    {
        return $this->post($this->url('/'.$path), ['file' => $this->jsonFile($data)], ['Accept' => 'application/json']);
    }

    /**
     * @param  array<mixed>  $data
     */
    private function jsonFile(array $data): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('fr.json', json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  list<string>  $fields
     */
    private function csvLine(array $fields): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $fields, ',', '"', '');
        rewind($handle);
        $line = (string) stream_get_contents($handle);
        fclose($handle);

        return $line;
    }
}
