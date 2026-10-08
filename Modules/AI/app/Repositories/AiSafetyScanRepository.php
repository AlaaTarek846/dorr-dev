<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiSafetyScan;

class AiSafetyScanRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['owner'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiSafetyScan $model)
    {
        $this->model = $model;
    }
}
