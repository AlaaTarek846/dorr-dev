<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatContact;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * A folder sent as one zip with its name and file count (spec 10), a short silent clip that
 * loops like a GIF (spec 11), and the tiny preview data saver shows before a photo (spec 82).
 */
class ChatFolderGifTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private string $chat;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Http::fake();
        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'sa']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $sar->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->alice = $make('Alice', '+966500000001');
        $this->bob = $make('Bob', '+966500000002');
        foreach ([[$this->alice, $this->bob], [$this->bob, $this->alice]] as [$owner, $contact]) {
            ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
        }
        Sanctum::actingAs($this->alice, [], 'user_api');
        $this->chat = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->json('data.id');
    }

    public function test_a_folder_goes_as_one_zip_with_its_name_and_count(): void
    {
        $this->post("/api/mobile/v1/chat/conversations/{$this->chat}/messages", [
            'type' => 'document',
            'folder_name' => 'Trip photos',
            'folder_count' => 42,
            'files' => [UploadedFile::fake()->create('Trip photos.zip', 300, 'application/zip')],
        ], $this->headers())->assertCreated()
            ->assertJsonPath('data.meta.folder.name', 'Trip photos')
            ->assertJsonPath('data.meta.folder.count', 42)
            ->assertJsonCount(1, 'data.attachments');

        // A plain document has no folder; a folder is only ever a document.
        $this->post("/api/mobile/v1/chat/conversations/{$this->chat}/messages", [
            'type' => 'document',
            'files' => [UploadedFile::fake()->create('cv.pdf', 40, 'application/pdf')],
        ], $this->headers())->assertCreated()->assertJsonPath('data.meta', null);
        $this->post("/api/mobile/v1/chat/conversations/{$this->chat}/messages", [
            'type' => 'image',
            'folder_name' => 'Nope',
            'files' => [UploadedFile::fake()->image('a.jpg', 40, 40)],
        ], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('folder_name');
    }

    public function test_a_clip_sent_as_a_gif_says_so_and_only_a_video_can(): void
    {
        $this->post("/api/mobile/v1/chat/conversations/{$this->chat}/messages", [
            'type' => 'video',
            'gif' => 1,
            'duration_ms' => 4200,
            'files' => [UploadedFile::fake()->create('loop.mp4', 200, 'video/mp4')],
        ], $this->headers())->assertCreated()
            ->assertJsonPath('data.meta.gif', true)
            ->assertJsonPath('data.meta.duration_ms', 4200);

        $this->post("/api/mobile/v1/chat/conversations/{$this->chat}/messages", [
            'type' => 'video',
            'duration_ms' => 9000,
            'files' => [UploadedFile::fake()->create('clip.mp4', 200, 'video/mp4')],
        ], $this->headers())->assertCreated()->assertJsonMissingPath('data.meta.gif');

        $this->post("/api/mobile/v1/chat/conversations/{$this->chat}/messages", [
            'type' => 'text',
            'body' => 'hi',
            'gif' => 1,
        ], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('gif');
    }

    public function test_a_photo_carries_its_size_and_a_tiny_preview_for_data_saver(): void
    {
        $photo = $this->post("/api/mobile/v1/chat/conversations/{$this->chat}/messages", [
            'type' => 'image',
            'files' => [UploadedFile::fake()->image('beach.jpg', 800, 600)],
        ], $this->headers())->assertCreated()
            ->assertJsonPath('data.attachments.0.width', 800)
            ->assertJsonPath('data.attachments.0.height', 600);

        // A ~40px JPEG, small enough to travel with the message.
        $preview = base64_decode((string) $photo->json('data.attachments.0.preview'), true);
        $this->assertNotFalse($preview);
        $this->assertLessThan(2048, strlen($preview));
        [$width, $height] = getimagesizefromstring($preview);
        $this->assertSame([40, 30], [$width, $height]);

        // Not a photo: no preview.
        $this->post("/api/mobile/v1/chat/conversations/{$this->chat}/messages", [
            'type' => 'document',
            'files' => [UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf')],
        ], $this->headers())->assertCreated()->assertJsonPath('data.attachments.0.preview', null);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Country' => 'SA', 'Accept' => 'application/json'];
    }
}
