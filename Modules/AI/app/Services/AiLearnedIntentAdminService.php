<?php

namespace Modules\AI\Services;

use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\AI\Http\Resources\AiLearnedIntentResource;
use Modules\AI\Models\AiLearnedIntent;
use Modules\AI\Support\AiChatIntent;
use Modules\AI\Support\AiIntentVerdictParser;
use Modules\AI\Support\ArabicTextNormalizer;

/**
 * Admin-side management of what the intent router has learned (see
 * AiIntentRouterService / AiLearnedIntentStore). Every change that can
 * affect which phrases are active flushes the store's cache so the effect
 * is immediate, not "within five minutes".
 */
class AiLearnedIntentAdminService
{
    public function __construct(
        protected AiLearnedIntentStore $store,
        protected AiIntentRouterService $router,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): JsonResponse
    {
        $query = AiLearnedIntent::query();

        match ($filters['status'] ?? null) {
            'active' => $query->where('is_active', true)->where('conflicts', 0),
            'pending' => $query->where('is_active', false)->where('conflicts', 0),
            'conflict' => $query->where('conflicts', '>', 0),
            default => null,
        };

        if (! empty($filters['intent'])) {
            $query->where('intent', $filters['intent']);
        }

        if (! empty($filters['mode'])) {
            $query->where('match_mode', $filters['mode']);
        }

        if (! empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }

        if (! empty($filters['search'])) {
            $needle = ArabicTextNormalizer::normalize((string) $filters['search']);
            $query->where('phrase', 'like', '%'.addcslashes($needle, '%_\\').'%');
        }

        $perPage = max(5, min(100, (int) ($filters['per_page'] ?? 10)));

        $paginator = $query->orderByDesc('hits')->orderByDesc('id')->paginate($perPage);

        return ApiResponse::fromPaginator(
            $paginator,
            AiLearnedIntentResource::collection($paginator->items()),
            __('api.retrieved'),
        );
    }

    public function stats(): JsonResponse
    {
        $total = AiLearnedIntent::query()->count();
        $active = AiLearnedIntent::query()->where('is_active', true)->where('conflicts', 0)->count();
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
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): JsonResponse
    {
        $phrase = ArabicTextNormalizer::normalize((string) $data['phrase']);

        $row = AiLearnedIntent::query()->create([
            'phrase' => $phrase,
            'match_mode' => $data['match_mode'],
            'intent' => $data['intent'],
            'file_format' => $data['intent'] === AiChatIntent::FILE_OUTPUT ? ($data['file_format'] ?? null) : null,
            'language' => AiIntentVerdictParser::languageOf($phrase),
            'confidence' => 1,
            'confirmations' => 1,
            'conflicts' => 0,
            'is_active' => true,
            'source' => AiLearnedIntent::SOURCE_ADMIN,
        ]);

        $this->store->flushCache();

        return ApiResponse::created(new AiLearnedIntentResource($row), __('api.created'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): JsonResponse
    {
        $row = AiLearnedIntent::query()->findOrFail($id);

        if (array_key_exists('intent', $data) && $data['intent'] !== $row->intent) {
            $clash = AiLearnedIntent::query()
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

        if (array_key_exists('is_active', $data)) {
            $row->is_active = (bool) $data['is_active'];

            // Switching off marks the row as "do not trust": the router
            // never turns it back on by itself. Switching on clears that.
            $row->conflicts = $row->is_active ? 0 : max(1, (int) $row->conflicts);
        }

        $row->save();
        $this->store->flushCache();

        return ApiResponse::success(new AiLearnedIntentResource($row), __('api.updated'));
    }

    public function delete(int $id): JsonResponse
    {
        AiLearnedIntent::query()->findOrFail($id)->delete();
        $this->store->flushCache();

        return ApiResponse::noContent(__('api.deleted'));
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
