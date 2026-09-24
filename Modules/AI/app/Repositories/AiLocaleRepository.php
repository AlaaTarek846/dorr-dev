<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiLocale;

class AiLocaleRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['language'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'code' => 'asc',
    ];

    public function __construct(AiLocale $model)
    {
        $this->model = $model;
    }
}
