<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiFileProcessing;

class AiFileProcessingRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['file'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiFileProcessing $model)
    {
        $this->model = $model;
    }
}
