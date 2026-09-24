<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiFile;

class AiFileRepository extends BaseRepository
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

    public function __construct(AiFile $model)
    {
        $this->model = $model;
    }
}
