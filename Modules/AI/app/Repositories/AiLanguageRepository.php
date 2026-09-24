<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiLanguage;

class AiLanguageRepository extends BaseRepository
{
    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'name' => 'asc',
    ];

    public function __construct(AiLanguage $model)
    {
        $this->model = $model;
    }
}
