<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerPortfolioItem as Item;
use Modules\Freelance\Services\Account\FreelancerPortfolio;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The freelancer's own portfolio (FL-105): works, their images, and the order. Everything is scoped to the signed-in freelancer. */
class FreelancerPortfolioController extends Controller
{
    public function __construct(private readonly FreelancerPortfolio $portfolio) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->portfolio->mine($this->me($request))->map(fn (Item $i) => $this->portfolio->present($i, true))->values(), __('api.retrieved'));
    }

    public function store(Request $request): JsonResponse
    {
        $item = $this->portfolio->create($this->me($request), $request->validate($this->rules(true)));

        return ApiResponse::created($this->portfolio->present($item, true), __('api.created'));
    }

    public function update(Request $request, int $item): JsonResponse
    {
        $model = $this->mine($request, $item);

        return ApiResponse::success($this->portfolio->present($this->portfolio->update($model, $request->validate($this->rules(false))), true), __('api.updated'));
    }

    public function destroy(Request $request, int $item): JsonResponse
    {
        $this->portfolio->delete($this->mine($request, $item));

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** PUT profile/portfolio/order {ids[]} */
    public function order(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']]);
        $this->portfolio->reorder($this->me($request), $data['ids']);

        return $this->index($request);
    }

    /** POST profile/portfolio/{id}/images (multipart: image) */
    public function addImage(Request $request, int $item): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=200,min_height=200,max_width=8000,max_height=8000', new \Modules\Freelance\Rules\CleanUpload]]);
        $model = $this->mine($request, $item);
        $this->portfolio->addImage($model, $request->file('image'));

        return ApiResponse::created($this->portfolio->present($model->refresh()->load(['media', 'skills.translations', 'specialty.translations']), true), __('api.created'));
    }

    public function destroyImage(Request $request, int $item, int $media): JsonResponse
    {
        $model = $this->mine($request, $item);
        $model->getMedia('images')->firstWhere('id', $media)?->delete() ?? abort(404);

        return ApiResponse::success($this->portfolio->present($model->refresh()->load(['media', 'skills.translations', 'specialty.translations']), true), __('api.deleted'));
    }

    /** GET profile/portfolio/{id}/images/{media} — the owner's own image. */
    public function image(Request $request, int $item, int $media): BinaryFileResponse
    {
        $file = $this->mine($request, $item)->getMedia('images')->firstWhere('id', $media) ?? abort(404);

        return $this->file($file);
    }

    public static function file(Media $media): BinaryFileResponse
    {
        return tap(response()->file($media->getPath(), ['Content-Type' => $media->mime_type]), fn ($r) => $r->setPrivate()->headers->addCacheControlDirective('no-store'));
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$req, 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'completed_on' => ['nullable', 'date', 'before_or_equal:today'],
            'specialty_id' => ['nullable', 'integer'],
            'skill_ids' => ['sometimes', 'array', 'max:10'],
            'skill_ids.*' => ['integer', 'distinct'],
            'visibility' => ['sometimes', Rule::in(Item::VISIBILITIES)],
            'rights_confirmed' => ['sometimes', 'boolean'],
            'external_url' => ['nullable', 'url:http,https', 'max:255'],
            'video_url' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function mine(Request $request, int $id): Item
    {
        return $this->me($request)->portfolioItems()->findOrFail($id);
    }

    private function me(Request $request): Freelancer
    {
        return $request->user('freelance_api');
    }
}
