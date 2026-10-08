<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiBenchmarkCase;

class AiBenchmarkCaseRepository extends BaseRepository
{
    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiBenchmarkCase $model)
    {
        $this->model = $model;
    }
}
