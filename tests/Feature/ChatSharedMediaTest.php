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
use Modules\Chat\Models\ChatMessage;
use Modules\User\Models\User;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * A forwarded file is the same file, not a copy (like WhatsApp): one file on the disk, which
 * stays while any message still uses it.
 */
class ChatSharedMediaTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private User $carol;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $flag = Flag::create(['code' => 'sa']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->alice = $make('Alice', '+966500000001');
        $this->bob = $make('Bob', '+966500000002');
        $this->carol = $make('Carol', '+966500000003');
        config(['services.onesignal.app_id' => 'app', 'services.onesignal.rest_api_key' => 'rest']);
        Http::fake(['*' => Http::response(['id' => 'n1'])]);
    }

    public function test_a_forwarded_file_is_the_same_file_until_the_last_message_using_it_goes(): void
    {
        Sanctum::actingAs($this->alice, [], 'user_api');
        $withBob = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->json('data.id');
        $withCarol = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->carol->id], $this->headers())->json('data.id');
        $sent = $this->post("/api/mobile/v1/chat/conversations/{$withBob}/messages", [
            'type' => 'document',
            'files' => [UploadedFile::fake()->createWithContent('report.pdf', str_repeat('a', 4096))],
        ], $this->headers())->assertCreated()->json('data.id');

        // Forward it to Carol, then forward the forward back to Bob.
        $this->postJson('/api/mobile/v1/chat/messages/forward', ['messages' => [$sent], 'conversations' => [$withCarol]], $this->headers())->assertCreated();
        $forwarded = ChatMessage::query()->where('is_forwarded', true)->latest('id')->firstOrFail();
        $this->postJson('/api/mobile/v1/chat/messages/forward', ['messages' => [$forwarded->uuid], 'conversations' => [$withBob]], $this->headers())->assertCreated();

        $rows = Media::query()->where('collection_name', ChatMessage::ATTACHMENTS)->orderBy('id')->get();
        $this->assertCount(3, $rows);
        [$original, $first, $second] = $rows->all();
        $this->assertCount(1, Storage::disk('public')->allFiles(), 'one file on the disk, not three');
        $this->assertSame($original->getPathRelativeToRoot(), $first->getPathRelativeToRoot());
        $this->assertSame($original->getPathRelativeToRoot(), $second->getPathRelativeToRoot());
        $this->assertSame($original->id, $first->shared_root_id);
        $this->assertSame($original->id, $second->shared_root_id);

        // The first message (and its row) goes: the forwards keep the file.
        ChatMessage::query()->where('uuid', $sent)->firstOrFail()->clearMediaCollection(ChatMessage::ATTACHMENTS);
        Storage::disk('public')->assertExists($first->getPathRelativeToRoot());
        $first->delete();
        Storage::disk('public')->assertExists($second->getPathRelativeToRoot());

        // The last one using it: now it's gone.
        $second->delete();
        Storage::disk('public')->assertMissing($second->getPathRelativeToRoot());
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_a_file_nobody_shares_is_removed_as_before(): void
    {
        Sanctum::actingAs($this->alice, [], 'user_api');
        $withBob = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->json('data.id');
        $this->post("/api/mobile/v1/chat/conversations/{$withBob}/messages", [
            'type' => 'document',
            'files' => [UploadedFile::fake()->createWithContent('notes.pdf', str_repeat('b', 2048))],
        ], $this->headers())->assertCreated();

        $media = Media::query()->firstOrFail();
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
        $media->delete();
        Storage::disk('public')->assertMissing($media->getPathRelativeToRoot());
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Country' => 'SA', 'Accept' => 'application/json'];
    }
}
