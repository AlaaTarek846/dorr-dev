<?php

namespace Modules\Freelance\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Services\Offers\ClientFavorites;
use Modules\Freelance\Services\Offers\ClientTeamFavorites;

/**
 * FL-306: the freelancers I keep — GET favorites, POST / DELETE favorites/{uuid}.
 * FS-213: the freelancers' teams I keep — GET favorites/teams, POST / DELETE favorites/teams/{uuid}.
 */
class ClientFavoriteController extends Controller
{
    public function __construct(private readonly ClientFavorites $favorites, private readonly ClientTeamFavorites $teams) {}

    public function index(Request $request): JsonResponse
    {
        $page = ApiPaginator::slice($this->favorites->list($request->user('user_api')));

        return ApiResponse::fromPaginator($page, $page->getCollection()->values(), __('api.retrieved'));
    }

    public function store(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user('user_api');
        $this->favorites->add($me, $this->favorites->findFor($me, $uuid));

        return ApiResponse::success(['is_favorite' => true], __('api.updated'));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user('user_api');
        $this->favorites->remove($me, $this->favorites->findFor($me, $uuid));

        return ApiResponse::success(['is_favorite' => false], __('api.updated'));
    }

    public function teams(Request $request): JsonResponse
    {
        $page = ApiPaginator::slice($this->teams->list($request->user('user_api')));

        return ApiResponse::fromPaginator($page, $page->getCollection()->values(), __('api.retrieved'));
    }

    public function storeTeam(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user('user_api');
        $this->teams->add($me, $this->teams->findFor($me, $uuid));

        return ApiResponse::success(['is_favorite' => true], __('api.updated'));
    }

    public function destroyTeam(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user('user_api');
        $this->teams->remove($me, $this->teams->findSaved($me, $uuid));

        return ApiResponse::success(['is_favorite' => false], __('api.updated'));
    }
}
