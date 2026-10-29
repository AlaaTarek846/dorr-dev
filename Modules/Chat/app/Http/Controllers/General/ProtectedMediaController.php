<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Chat\Support\GroupMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * GET chat/media/{media}?v=…&expires=…&signature=… — a file of an educational / work group
 * (docs/groups_tasks.md GR-008). The link is signed (route middleware `signed`) and names its
 * viewer; the viewer must still be a member right now. Ranges work (video seeking).
 */
class ProtectedMediaController extends Controller
{
    public function __invoke(Request $request, string $media)
    {
        $file = Media::query()->where('uuid', $media)->where('disk', GroupMedia::DISK)->first();

        abort_if($file === null || ! GroupMedia::viewerMay($file, (string) $request->query('v')), 404);

        return response()->file($file->getPath(), [
            'Content-Type' => $file->mime_type,
            // Private: phones may keep it for the link's life, shared caches never.
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
