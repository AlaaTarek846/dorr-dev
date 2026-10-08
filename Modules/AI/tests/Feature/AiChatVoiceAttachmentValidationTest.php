<?php

namespace Modules\AI\Tests\Feature;

use Modules\AI\Http\Requests\AiChatMessageRequest;
use Modules\AI\Rules\SafeUploadedFile;
use Tests\TestCase;

/**
 * The mobile app records voice notes as AAC in an .m4a container
 * (com.dorr.app.chat.VoiceRecorder) and uploads them through the same
 * `attachment` field an image/document goes through - but until this
 * fix, AiChatMessageRequest's `mimes:` rule and SafeUploadedFile's own
 * content-sniffed allowlist both only listed image/document types, so a
 * real voice-message upload from the app would have been rejected with
 * a 422 before AiChatService ever saw it, even though
 * AiChatSendMessageVoiceIntegrationTest proves the service itself
 * handles a voice attachment correctly once one reaches it.
 *
 * This deliberately checks the two validation allowlists directly
 * rather than posting a fake UploadedFile through the real HTTP route:
 * libmagic's real, content-sniffed MIME for an MP4/M4A container varies
 * by OS/library version (audio/mp4 vs audio/x-m4a vs video/mp4 are all
 * observed in the wild), so a fabricated minimal M4A byte fixture here
 * would risk asserting this server's specific libmagic behaviour rather
 * than the actual regression this fix targets - allowlist entries
 * silently disappearing again.
 */
class AiChatVoiceAttachmentValidationTest extends TestCase
{
    public function test_the_attachment_mimes_rule_accepts_the_apps_own_voice_note_extension(): void
    {
        $rules = (new AiChatMessageRequest)->rules();
        $attachmentRules = $rules['attachment'];

        $mimesRule = collect($attachmentRules)->first(fn ($rule) => is_string($rule) && str_starts_with($rule, 'mimes:'));

        $this->assertNotNull($mimesRule, 'expected a mimes: rule on the attachment field');
        $extensions = explode(',', substr($mimesRule, strlen('mimes:')));

        $this->assertContains('m4a', $extensions, 'the app\'s own voice notes are .m4a - without this the composer\'s mic button can never actually send one');
    }

    public function test_the_content_sniffed_allowlist_accepts_the_apps_own_voice_note_container(): void
    {
        $rule = new SafeUploadedFile;
        $reflection = new \ReflectionClass($rule);
        $property = $reflection->getProperty('allowedRealMimes');
        $property->setAccessible(true);
        $allowed = $property->getValue($rule);

        // Both spellings are allowed on purpose (see this test's class docblock) -
        // libmagic reports one or the other depending on version, and rejecting a
        // real voice note over which name the server's library happens to use
        // would be exactly the kind of silent breakage this test exists to catch.
        $this->assertContains('audio/mp4', $allowed);
        $this->assertContains('audio/x-m4a', $allowed);
    }
}
