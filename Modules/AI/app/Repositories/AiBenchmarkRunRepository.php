<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiBenchmarkRun;

class AiBenchmarkRunRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['results.case'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiBenchmarkRun $model)
    {
        $this->model = $model;
    }
}
