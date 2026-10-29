<?php

namespace Modules\Freelance\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Services\Offers\ClientFavorites;

/** FL-306: the freelancers I keep — GET favorites, POST / DELETE favorites/{uuid}. */
class ClientFavoriteController extends Controller
{
    public function __construct(private readonly ClientFavorites $favorites) {}

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
}
