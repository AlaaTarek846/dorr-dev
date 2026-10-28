<?php

namespace Modules\Freelance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerPortfolioController;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerPortfolioItem as Item;
use Modules\Freelance\Services\Account\FreelancerPortfolio;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * What others see of a portfolio (FL-105): a client (signed in) sees the public and clients-only works, anyone the public
 * ones; images come as links that expire (signed), never a permanent public URL.
 */
class PortfolioViewController extends Controller
{
    public function __construct(private readonly FreelancerPortfolio $portfolio) {}

    public function show(Request $request, string $uuid): JsonResponse
    {
        $freelancer = Freelancer::query()->where('uuid', $uuid)->firstOrFail();
        $client = $request->user('user_api') !== null;
        $items = $this->portfolio->visibleTo($freelancer, $client);

        return ApiResponse::success($items->map(fn (Item $i) => $this->portfolio->present($i, false))->values(), __('api.retrieved'));
    }

    /** GET portfolio-images/{media} — signed and short-lived; still re-checks that the work is visible and not hidden. */
    public function image(Media $media): BinaryFileResponse
    {
        $item = $media->model;
        abort_unless($item instanceof Item && $media->collection_name === 'images' && ! $item->isHidden() && $item->visibility !== Item::PRIVATE && $item->freelancer?->status?->value === 'verified', 404);

        return FreelancerPortfolioController::file($media);
    }
}
