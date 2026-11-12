<?php

namespace Modules\AI\Services;

use App\Services\CatalogService;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\AI\Http\Resources\AiLearnedIntentResource;
use Modules\AI\Models\AiLearnedIntent;
use Modules\AI\Repositories\AiLearnedIntentRepository;
use Modules\AI\Support\AiChatIntent;
use Modules\AI\Support\AiIntentVerdictParser;
use Modules\AI\Support\ArabicTextNormalizer;

/**
 * Admin side of what the intent router has learned (AiIntentRouterService / AiLearnedIntentStore), as a catalog
 * like countries (list + status counts, status toggle, bulk delete, trash / restore / force delete), plus the
 * screen's stats and the side-effect-free "try a phrase" panel.
 *
 * Every change empties the store's cache of active phrases (AiLearnedIntent's model events), so it takes effect on
 * the next message, not "within five minutes".
 */
class AiLearnedIntentAdminService extends CatalogService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiLearnedIntentResource::class;

    public function __construct(
        AiLearnedIntentRepository $repository,
        protected AiIntentRouterService $router,
    ) {
        parent::__construct($repository);
    }

    public function stats(): JsonResponse
    {
        $total = AiLearnedIntent::query()->count();
        $active = AiLearnedIntent::query()->where('status', true)->where('conflicts', 0)->count();
        $conflicts = AiLearnedIntent::query()->where('conflicts', '>', 0)->count();

        $byIntent = AiLearnedIntent::query()
            ->selectRaw('intent, count(*) as total, sum(hits) as hits')
            ->groupBy('intent')
            ->get()
            ->map(fn ($row) => ['intent' => $row->intent, 'total' => (int) $row->total, 'hits' => (int) $row->hits])
            ->values();

        return ApiResponse::success([
            'total' => $total,
            'active' => $active,
            'pending' => max(0, $total - $active - $conflicts),
            'conflicts' => $conflicts,
            'hits' => (int) AiLearnedIntent::query()->sum('hits'),
            'min_confirmations' => max(1, (int) config('ai.intent_router.learn_min_confirmations', 2)),
            'from_model' => AiLearnedIntent::query()->where('source', AiLearnedIntent::SOURCE_MODEL)->count(),
            'from_admin' => AiLearnedIntent::query()->where('source', AiLearnedIntent::SOURCE_ADMIN)->count(),
            'by_intent' => $byIntent,
        ], __('api.retrieved'));
    }

    /**
     * An admin phrase: normalized like the router's, trusted at once (confidence 1, no conflicts), source admin.
     * `status` false saves it switched off - marked "do not trust" like a phrase switched off from the list.
     *
     * @param  array<string, mixed>  $data
     */
    public function store(array $data): Model
    {
        $phrase = ArabicTextNormalizer::normalize((string) $data['phrase']);
        $status = (bool) ($data['status'] ?? true);

        return $this->repository->store([
            'phrase' => $phrase,
            'match_mode' => $data['match_mode'],
            'intent' => $data['intent'],
            'file_format' => $data['intent'] === AiChatIntent::FILE_OUTPUT ? ($data['file_format'] ?? null) : null,
            'language' => AiIntentVerdictParser::languageOf($phrase),
            'confidence' => 1,
            'confirmations' => 1,
            'conflicts' => $status ? 0 : 1,
            'status' => $status,
            'source' => AiLearnedIntent::SOURCE_ADMIN,
        ]);
    }

    /**
     * The phrase and match type are what was learned and never change; the intent, the file format and the switch
     * can. Switching off marks the phrase "do not trust" (the router never turns it back on); switching on clears it.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(int|string $id, array $data): Model
    {
        /** @var AiLearnedIntent $row */
        $row = $this->repository->query()->findOrFail($id);

        if (array_key_exists('intent', $data) && $data['intent'] !== $row->intent) {
            $clash = AiLearnedIntent::query()->withTrashed()
                ->where('phrase', $row->phrase)
                ->where('match_mode', $row->match_mode)
                ->where('intent', $data['intent'])
                ->where('id', '!=', $row->id)
                ->exists();

            if ($clash) {
                throw ValidationException::withMessages(['intent' => __('ai.learned_intent_exists')]);
            }

            $row->intent = $data['intent'];
        }

        if (array_key_exists('file_format', $data) || array_key_exists('intent', $data)) {
            $row->file_format = $row->intent === AiChatIntent::FILE_OUTPUT
                ? ($data['file_format'] ?? $row->file_format)
                : null;
        }

        if (array_key_exists('status', $data) && $data['status'] !== null && (bool) $data['status'] !== (bool) $row->status) {
            $row->status = (bool) $data['status'];
            $row->conflicts = $row->status ? 0 : max(1, (int) $row->conflicts);
        }

        $row->save();

        return $row->refresh();
    }

    /**
     * "What would the chat do with this message?" - with no side effects
     * (no model call, no hit counted, nothing learned).
     */
    public function test(string $message, bool $recentImage): JsonResponse
    {
        return ApiResponse::success($this->router->preview($message, $recentImage), __('api.retrieved'));
    }
}
