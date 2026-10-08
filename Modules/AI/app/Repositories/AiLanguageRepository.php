<?php

namespace Modules\AI\Repositories;

use App\Models\Language;
use App\Repositories\BaseRepository;

/**
 * Root-cause fix (languages consolidation): used to wrap the AI module's
 * own now-removed "ai_languages" table - wraps the platform's single
 * general Language model instead, eager-loading its translations so
 * AiLanguageResource can show a real, per-locale display name.
 */
class AiLanguageRepository extends BaseRepository
{
    /**
     * @var list<string>
     */
    protected array $with = ['translations'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'code' => 'asc',
    ];

    public function __construct(Language $model)
    {
        $this->model = $model;
    }
}
