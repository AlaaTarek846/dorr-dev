<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Builder;
use Modules\AI\Models\AiRequest;

class AiRequestRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['owner', 'gateway', 'intent', 'provider', 'response', 'usage', 'citations.knowledgeSource'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiRequest $model)
    {
        $this->model = $model;
    }

    /**
     * `?source=` narrows the list: `ai_chat` (the DORR AI chat itself), `tools` (the AI tools of the
     * chat and the other modules), or one tool's exact name (chat.translate…).
     */
    protected function buildIndexQuery(): Builder
    {
        $source = trim((string) request()->input('source', ''));

        return parent::buildIndexQuery()->when($source !== '', fn (Builder $query) => match ($source) {
            'ai_chat' => $query->whereNull('source'),
            'tools' => $query->whereNotNull('source'),
            default => $query->where('source', $source),
        });
    }
}
