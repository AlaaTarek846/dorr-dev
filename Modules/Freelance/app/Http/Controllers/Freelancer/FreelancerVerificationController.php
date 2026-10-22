<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelanceVerificationDocument;
use Modules\Freelance\Services\Verification\FreelancerVerification;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The freelancer's side of verification (FL-107 / FL-108): what is required, uploading, sending the request, and their own files. */
class FreelancerVerificationController extends Controller
{
    public function __construct(private readonly FreelancerVerification $verification) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->verification->overview($this->me($request)), __('api.retrieved'));
    }

    /** POST verification/documents (multipart) {document_type_id, number?, expires_on?, front, back?} */
    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_type_id' => ['required', 'integer'],
            'number' => ['nullable', 'string', 'max:64'],
            'expires_on' => ['nullable', 'date', 'after:today'],
            'front' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:'.(int) config('freelance.verification.max_file_kb', 8192)],
            'back' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:'.(int) config('freelance.verification.max_file_kb', 8192)],
        ]);
        $doc = $this->verification->upload($this->me($request), (int) $data['document_type_id'], $data, $request->file('front'), $request->file('back'));

        return ApiResponse::created($this->verification->presentDocument($doc), __('api.created'));
    }

    public function destroy(Request $request, int $document): JsonResponse
    {
        $me = $this->me($request);
        $this->verification->deleteDraft($me, $this->mine($me, $document));

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** POST verification/submit — sends the uploaded documents for review. */
    public function submit(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $this->verification->submit($me);

        return ApiResponse::success($this->verification->overview($me->refresh()), __('api.updated'));
    }

    /** GET verification/documents/{id}/{side} — the freelancer's own file, never a public link. */
    public function file(Request $request, int $document, string $side): BinaryFileResponse
    {
        $media = $this->mine($this->me($request), $document)->file($side);
        abort_if($media === null, 404);

        return tap(response()->download($media->getPath(), $media->file_name), fn ($r) => $r->setPrivate()->headers->addCacheControlDirective('no-store'));
    }

    private function mine(Freelancer $me, int $id): FreelanceVerificationDocument
    {
        return FreelanceVerificationDocument::query()->where('freelancer_id', $me->id)->findOrFail($id);
    }

    private function me(Request $request): Freelancer
    {
        return $request->user('freelance_api');
    }
}
