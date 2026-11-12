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
use Modules\Freelance\Services\Plans\FreelanceProfileViews;
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
        $viewer = $request->user('user_api');
        $client = $viewer !== null;
        $items = $this->portfolio->visibleTo($freelancer, $client);
        if ($viewer !== null) {
            app(FreelanceProfileViews::class)->record((int) $freelancer->getKey(), FreelanceProfileViews::PROFILE, 'portfolio', $viewer->getKey());
        }

        return ApiResponse::success($items->map(fn (Item $i) => $this->portfolio->present($i, false))->values(), __('api.retrieved'));
    }

    /** POST freelancers/{uuid}/portfolio/{item}/view (FS-704) — a client opened one work: counted per day, never per person (the same client within minutes counts once). */
    public function opened(Request $request, string $uuid, string $item): JsonResponse
    {
        $freelancer = Freelancer::query()->where('uuid', $uuid)->firstOrFail();
        $viewer = $request->user('user_api');
        $work = $this->portfolio->visibleTo($freelancer, true)->firstWhere('uuid', $item);
        abort_if($work === null, 404);
        app(FreelanceProfileViews::class)->record((int) $freelancer->getKey(), FreelanceProfileViews::WORK, 'portfolio', $viewer->getKey(), (int) $work->getKey());

        return ApiResponse::success(null, __('api.retrieved'));
    }

    /** GET portfolio-images/{media} — signed and short-lived; still re-checks that the work is visible and not hidden. */
    public function image(Media $media): BinaryFileResponse
    {
        $item = $media->model;
        abort_unless($item instanceof Item && $media->collection_name === 'images' && ! $item->isHidden() && ! $item->withdrawnByClient() && $item->visibility !== Item::PRIVATE && $item->freelancer?->status?->value === 'verified'
            // FS-202a: a team's work only while its team is active.
            && ($item->team_id === null || $item->team?->isActive() === true), 404);

        return FreelancerPortfolioController::file($media);
    }
}
