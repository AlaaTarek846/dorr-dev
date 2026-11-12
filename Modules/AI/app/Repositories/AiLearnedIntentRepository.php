<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\AI\Models\AiLearnedIntent;

/**
 * Learned phrases as a catalog list (like countries, without translations - a phrase is matched, not translated):
 * status toggle, bulk delete, trash / restore / force delete.
 *
 * The list also takes the screen's own filters next to the catalog ones (search, status, trash):
 * `?state=pending|conflict`, `?intent=`, `?mode=phrase|exact`, `?source=model|admin`.
 */
class AiLearnedIntentRepository extends BaseRepository
{
    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'hits' => 'desc',
        'id' => 'desc',
    ];

    public function __construct(AiLearnedIntent $model)
    {
        $this->model = $model;
    }

    protected function buildIndexQuery(): Builder
    {
        $request = request();

        return parent::buildIndexQuery()
            ->when($request->query('state') === 'pending', fn (Builder $q) => $q->where('status', false)->where('conflicts', 0))
            ->when($request->query('state') === 'conflict', fn (Builder $q) => $q->where('conflicts', '>', 0))
            ->when($request->filled('intent'), fn (Builder $q) => $q->where('intent', (string) $request->query('intent')))
            ->when(in_array($request->query('mode'), [AiLearnedIntent::MODE_PHRASE, AiLearnedIntent::MODE_EXACT], true), fn (Builder $q) => $q->where('match_mode', $request->query('mode')))
            ->when(in_array($request->query('source'), [AiLearnedIntent::SOURCE_MODEL, AiLearnedIntent::SOURCE_ADMIN], true), fn (Builder $q) => $q->where('source', $request->query('source')));
    }

    /**
     * Switching a phrase off marks it "do not trust" (conflicts >= 1), so the router never switches it back on by
     * itself; switching it on clears that (same rule as the `ai:learned-intents` command).
     */
    public function changeStatus(int|string $id, bool $status): Model
    {
        /** @var AiLearnedIntent $row */
        $row = $this->query()->findOrFail($id);
        $row->forceFill([
            'status' => $status,
            'conflicts' => $status ? 0 : max(1, (int) $row->conflicts),
        ])->save();

        return $this->refresh($row);
    }
}
