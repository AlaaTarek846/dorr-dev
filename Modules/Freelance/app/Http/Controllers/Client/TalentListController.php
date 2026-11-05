<?php

namespace Modules\Freelance\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Services\Offers\TalentPool;

/**
 * FS-103: the client's lists of freelancers - GET/POST talent-lists, GET/PUT/DELETE talent-lists/{uuid}, POST talent-lists/{uuid}/members,
 * PUT/DELETE talent-lists/{uuid}/members/{freelancer}, POST talent-lists/{uuid}/invite, GET talent-lists/containing/{freelancer}.
 */
class TalentListController extends Controller
{
    public function __construct(private readonly TalentPool $pool) {}

    public function index(Request $request): JsonResponse
    {
        $lists = $this->pool->lists($request->user('user_api'), $request->integer('specialty_id') ?: null);

        return ApiResponse::success(['lists' => $lists->values(), 'max_lists' => TalentPool::MAX_LISTS], __('api.retrieved'));
    }

    public function store(Request $request): JsonResponse
    {
        $me = $request->user('user_api');
        $data = $this->listRules($request);
        $list = $this->pool->create($me, $data['name'], $data['specialty_id'] ?? null);

        return ApiResponse::success($this->pool->show($me, $list), __('api.created'), 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user('user_api');

        return ApiResponse::success($this->pool->show($me, $this->pool->find($me, $uuid)), __('api.retrieved'));
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user('user_api');
        $data = $this->listRules($request);
        $list = $this->pool->update($this->pool->find($me, $uuid), $data['name'], $data['specialty_id'] ?? null);

        return ApiResponse::success($this->pool->show($me, $list), __('api.updated'));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->pool->delete($this->pool->find($request->user('user_api'), $uuid));

        return ApiResponse::success(null, __('api.deleted'));
    }

    public function addMember(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user('user_api');
        $data = $request->validate(['freelancer' => ['required', 'uuid'], 'note' => ['nullable', 'string', 'max:200']]);
        $list = $this->pool->find($me, $uuid);
        $this->pool->add($list, $this->pool->freelancer($data['freelancer']), $data['note'] ?? null);

        return ApiResponse::success($this->pool->show($me, $list), __('api.updated'));
    }

    public function updateMember(Request $request, string $uuid, string $freelancer): JsonResponse
    {
        $me = $request->user('user_api');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:200'], 'remind_in_days' => ['nullable', 'integer', Rule::in(TalentPool::REMIND_DAYS)]]);
        $list = $this->pool->find($me, $uuid);
        $this->pool->updateMember($list, $this->pool->freelancer($freelancer), array_intersect_key($data, array_flip(array_keys($request->only(['note', 'remind_in_days'])))));

        return ApiResponse::success($this->pool->show($me, $list), __('api.updated'));
    }

    public function removeMember(Request $request, string $uuid, string $freelancer): JsonResponse
    {
        $me = $request->user('user_api');
        $list = $this->pool->find($me, $uuid);
        $this->pool->remove($list, $this->pool->freelancer($freelancer));

        return ApiResponse::success($this->pool->show($me, $list), __('api.updated'));
    }

    public function invite(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user('user_api');
        $data = $request->validate(['project' => ['required', 'uuid'], 'freelancers' => ['nullable', 'array', 'max:50'], 'freelancers.*' => ['uuid']]);

        return ApiResponse::success($this->pool->invite($me, $this->pool->find($me, $uuid), $data['project'], $data['freelancers'] ?? null), __('api.updated'));
    }

    public function containing(Request $request, string $freelancer): JsonResponse
    {
        $me = $request->user('user_api');

        return ApiResponse::success(['lists' => $this->pool->listsHolding($me, $this->pool->freelancer($freelancer))], __('api.retrieved'));
    }

    /** @return array{name: string, specialty_id?: ?int} */
    private function listRules(Request $request): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:80'], 'specialty_id' => ['nullable', 'integer', 'exists:freelance_specialties,id']]);
    }
}
