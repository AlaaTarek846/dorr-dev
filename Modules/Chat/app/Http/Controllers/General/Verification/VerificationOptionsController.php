<?php

namespace Modules\Chat\Http\Controllers\General\Verification;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Chat\Enums\VerificationTrack;
use Modules\Chat\Models\Verification\ChatVerificationDocumentType;
use Modules\Chat\Services\ChatFeatures;

/**
 * GET chat/verification/options — the verification screen's starting point (REQ-01-01): each track,
 * whether it is open where this person is (the admin's switch, then the country and city rules), and
 * the documents it asks for. Choosing the celebrity track never promises approval.
 */
class VerificationOptionsController extends Controller
{
    public function __invoke(Request $request, ChatFeatures $features): JsonResponse
    {
        $me = $request->user();
        $documents = ChatVerificationDocumentType::query()->with('translations')->where('status', true)->orderBy('sort_order')->orderBy('id')->get();

        $tracks = array_map(fn (VerificationTrack $track) => [
            'track' => $track->value,
            'available' => $features->allows($me, 'verification_'.$track->value),
            'documents' => $documents
                ->filter(fn (ChatVerificationDocumentType $d) => in_array($track->value, $d->tracks ?? [], true))
                ->map(fn (ChatVerificationDocumentType $d) => [
                    'id' => $d->id,
                    'code' => $d->code,
                    'name' => $d->translated('name'),
                    'instructions' => $d->translated('instructions'),
                    'is_required' => (bool) $d->is_required,
                    'needs_back_side' => (bool) $d->needs_back_side,
                ])->values(),
        ], VerificationTrack::cases());

        return ApiResponse::success(['tracks' => $tracks], __('api.retrieved'));
    }
}
