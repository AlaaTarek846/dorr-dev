<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerPortfolioItem as Item;
use Modules\Freelance\Services\Account\FreelancerPortfolio;
use Modules\Freelance\Services\FreelancerTeam\FreelancerTeams;
use Modules\Freelance\Services\FreelancerTeam\TeamAbilities;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * FS-202a: a team's works (`teams/{team}/portfolio`): the team's people read them all and download their images; only an Owner (`edit_team`)
 * adds, changes, orders or removes one, and never while the team is suspended. Same rules as a person's portfolio (FL-105).
 */
class TeamPortfolioController extends Controller
{
    public function __construct(
        private readonly FreelancerPortfolio $portfolio,
        private readonly FreelancerTeams $teams,
        private readonly TeamAbilities $abilities,
    ) {}

    public function index(Request $request, string $team): JsonResponse
    {
        $t = $this->team($request, $team);

        return ApiResponse::success($this->portfolio->teamItems($t)->map(fn (Item $i) => $this->present($t, $i))->values(), __('api.retrieved'));
    }

    public function store(Request $request, string $team): JsonResponse
    {
        $t = $this->editable($request, $team);
        $item = $this->portfolio->createForTeam($t, $this->me($request), $request->validate($this->rules(true)));

        return ApiResponse::created($this->present($t, $item), __('api.created'));
    }

    public function update(Request $request, string $team, int $item): JsonResponse
    {
        $t = $this->editable($request, $team);
        $model = $this->portfolio->update($this->item($t, $item), $request->validate($this->rules(false)));

        return ApiResponse::success($this->present($t, $model), __('api.updated'));
    }

    public function destroy(Request $request, string $team, int $item): JsonResponse
    {
        $t = $this->editable($request, $team);
        $this->portfolio->delete($this->item($t, $item));

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** PUT teams/{team}/portfolio/order {ids[]} */
    public function order(Request $request, string $team): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']]);
        $t = $this->editable($request, $team);
        $this->portfolio->reorderTeam($t, $data['ids']);

        return $this->index($request, $team);
    }

    /** POST teams/{team}/portfolio/{id}/images (multipart: image) */
    public function addImage(Request $request, string $team, int $item): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=200,min_height=200,max_width=8000,max_height=8000', new \Modules\Freelance\Rules\CleanUpload]]);
        $t = $this->editable($request, $team);
        $model = $this->item($t, $item);
        $this->portfolio->addImage($model, $request->file('image'));

        return ApiResponse::created($this->present($t, $model->refresh()->load(['media', 'skills.translations', 'specialty.translations'])), __('api.created'));
    }

    public function destroyImage(Request $request, string $team, int $item, int $media): JsonResponse
    {
        $t = $this->editable($request, $team);
        $model = $this->item($t, $item);
        $model->getMedia('images')->firstWhere('id', $media)?->delete() ?? abort(404);

        return ApiResponse::success($this->present($t, $model->refresh()->load(['media', 'skills.translations', 'specialty.translations'])), __('api.deleted'));
    }

    /** GET teams/{team}/portfolio/{id}/images/{media} - for the team's people. */
    public function image(Request $request, string $team, int $item, int $media): BinaryFileResponse
    {
        $file = $this->item($this->team($request, $team), $item)->getMedia('images')->firstWhere('id', $media) ?? abort(404);

        return FreelancerPortfolioController::file($file);
    }

    /** @return array<string, mixed> */
    private function present(FreelanceTeam $team, Item $item): array
    {
        return $this->portfolio->present($item, true, "teams/{$team->uuid}/portfolio");
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

    /** The team, for somebody in it (404 otherwise). */
    private function team(Request $request, string $uuid): FreelanceTeam
    {
        $team = FreelanceTeam::query()->where('uuid', $uuid)->firstOrFail();
        if (! $this->teams->isMember($team, $this->me($request))) {
            throw new ModelNotFoundException;
        }

        return $team;
    }

    /** The team, for an Owner of an active one: 409 freelancer_team_inactive, 403 freelancer_team_ability. */
    private function editable(Request $request, string $uuid): FreelanceTeam
    {
        $team = $this->team($request, $uuid);
        if (! $team->isActive()) {
            throw new FreelanceException('freelancer_team_inactive', 409);
        }
        $this->abilities->assert($team, $this->me($request), TeamAbilities::EDIT_TEAM);

        return $team;
    }

    private function item(FreelanceTeam $team, int $id): Item
    {
        return $team->portfolioItems()->findOrFail($id);
    }

    private function me(Request $request): Freelancer
    {
        return $request->user('freelance_api');
    }
}
