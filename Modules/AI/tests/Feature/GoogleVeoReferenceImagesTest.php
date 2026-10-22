<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Services\AiGateway;
use Tests\TestCase;

/**
 * DORR AI WORLD Phase 0: Veo must be able to receive the user's reference
 * photo(s). Whether Google accepts a real person's photo is settled by the
 * live feasibility spike; these tests only prove the request we send has the
 * documented shape and that nothing changes for plain text-to-video.
 */
class GoogleVeoReferenceImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function provider()
    {
        return app(AiProviderRepository::class)->updateByKey('google', ['is_enabled' => true, 'api_key' => 'g-test-not-real', 'model' => 'veo-3.1-fast-generate-preview']);
    }

    protected function fakeVeo(): void
    {
        Http::fake(['generativelanguage.googleapis.com/v1beta/models/*' => Http::response(['name' => 'operations/op1'], 200)]);
    }

    /** @return array<string, mixed> */
    protected function sentBody(): array
    {
        $body = null;

        Http::assertSent(function ($request) use (&$body) {
            $body = $request->data();

            return str_contains($request->url(), ':predictLongRunning');
        });

        return $body;
    }

    protected function image(string $bytes = 'IMG', string $mime = 'image/jpeg'): array
    {
        return ['bytes' => $bytes, 'mime' => $mime];
    }

    public function test_text_only_request_is_unchanged(): void
    {
        $this->fakeVeo();

        $result = app(AiGateway::class)->startVideo($this->provider(), 'a man walking', 8);
        $body = $this->sentBody();

        $this->assertTrue($result['success']);
        $this->assertSame('operations/op1', $result['job_id']);
        $this->assertSame([['prompt' => 'a man walking']], $body['instances']);
        $this->assertSame('8', $body['parameters']['durationSeconds']);
        $this->assertArrayNotHasKey('resolution', $body['parameters']);
        $this->assertArrayNotHasKey('personGeneration', $body['parameters']);
    }

    public function test_reference_images_are_sent_as_assets_and_capped_at_three(): void
    {
        $this->fakeVeo();

        $refs = [$this->image('A'), $this->image('B', 'image/png'), $this->image('C'), $this->image('D')];

        $result = app(AiGateway::class)->startVideo($this->provider(), 'me at the Eiffel Tower', 8, null, ['reference_images' => $refs]);
        $instance = $this->sentBody()['instances'][0];

        $this->assertTrue($result['success']);
        $this->assertCount(3, $instance['referenceImages']);
        $this->assertSame('asset', $instance['referenceImages'][0]['referenceType']);
        $this->assertSame(base64_encode('A'), $instance['referenceImages'][0]['image']['bytesBase64Encoded']);
        $this->assertSame('image/png', $instance['referenceImages'][1]['image']['mimeType']);
        $this->assertArrayNotHasKey('image', $instance);
    }

    public function test_first_frame_image_is_sent_for_image_to_video(): void
    {
        $this->fakeVeo();

        app(AiGateway::class)->startVideo($this->provider(), 'walk forward', 6, null, ['image' => ['base64' => base64_encode('FRAME'), 'mime' => 'image/webp']]);
        $instance = $this->sentBody()['instances'][0];

        $this->assertSame(base64_encode('FRAME'), $instance['image']['bytesBase64Encoded']);
        $this->assertSame('image/webp', $instance['image']['mimeType']);
        $this->assertArrayNotHasKey('referenceImages', $instance);
    }

    public function test_unusable_reference_fails_without_calling_google(): void
    {
        Http::fake();

        $bad = app(AiGateway::class)->startVideo($this->provider(), 'x', 8, null, ['reference_images' => [$this->image('A'), ['bytes' => 'ZZZ', 'mime' => 'application/pdf']]]);
        $empty = app(AiGateway::class)->startVideo($this->provider(), 'x', 8, null, ['image' => ['bytes' => '', 'mime' => 'image/jpeg']]);

        $this->assertFalse($bad['success']);
        $this->assertNull($bad['job_id']);
        $this->assertSame(__('ai.video_reference_invalid'), $bad['message']);
        $this->assertFalse($empty['success']);
        Http::assertNothingSent();
    }

    public function test_resolution_aspect_ratio_and_person_generation_are_forwarded_only_when_asked(): void
    {
        $this->fakeVeo();

        app(AiGateway::class)->startVideo($this->provider(), 'x', 4, null, [
            'resolution' => '1080p', 'aspect_ratio' => '9:16', 'person_generation' => 'allow_adult',
        ]);
        $parameters = $this->sentBody()['parameters'];

        $this->assertSame('1080p', $parameters['resolution']);
        $this->assertSame('9:16', $parameters['aspectRatio']);
        $this->assertSame('allow_adult', $parameters['personGeneration']);
        $this->assertSame('4', $parameters['durationSeconds']);
    }
}
